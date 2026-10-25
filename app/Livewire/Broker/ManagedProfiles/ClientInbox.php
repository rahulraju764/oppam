<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Actions\Broker\ManagedProfiles\RespondToInterestForClient;
use App\Actions\Broker\ManagedProfiles\SendInterestForClient;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\BureauInterestAllowance;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Engagement\InterestMessages;
use App\Domain\Profile\ProfileNames;
use App\Enums\BureauPermission;
use App\Enums\InterestStatus;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Livewire\Member\Profile\ProfileFacts;
use App\Models\Broker;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Engagement\InterestLists;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /broker/profiles/{code}/inbox — the Client Inbox (§11A B.6, B.11 `client_inbox.respond`):
 * interests received by one managed client, to accept or decline on their behalf after speaking
 * to the family, the interests sent for them, and "send an interest" by profile code against the
 * bureau's monthly allowance (R-M13-35, decisions 2026-10-25). Lists come from InterestLists with
 * the client as "me" (blocked, ignored, incognito and non-ACTIVE members never appear). Members
 * are shown by first name + initial and their short facts — never contact details or photos
 * (R-M13-7). Every action is an Action that checks the permission and the scope again; the code
 * is locked. Live: a new interest refreshes the page; a claim leaves it.
 */
final class ClientInbox extends Component
{
    use WithPagination;

    public const TABS = ['pending', 'accepted', 'declined', 'sent'];

    private const PER_PAGE = 10;

    #[Locked]
    public string $code = '';

    #[Url(as: 'tab')]
    public string $tab = 'pending';

    public string $target = '';

    public string $template = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->client();
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'pending';
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff !== null ? [
            'echo-private:broker.'.$staff->broker_id.',.client.interest' => '$refresh',
            'echo-private:broker.'.$staff->broker_id.',.profile.claimed' => 'claimed',
        ] : [];
    }

    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'pending';
        $this->resetPage();
    }

    public function accept(string $from, RespondToInterestForClient $respond): void
    {
        $this->respond($respond, $from, true);
    }

    public function decline(string $from, RespondToInterestForClient $respond): void
    {
        $this->respond($respond, $from, false);
    }

    public function send(SendInterestForClient $send): void
    {
        $this->validate([
            'target' => ['required', 'string', 'max:20'],
            'template' => ['nullable', 'string', 'max:40'],
        ], ['target.required' => __('Enter the profile code the family chose, like OPM12345.')]);

        try {
            $result = $send->handle($this->user(), $this->code, $this->target, $this->template === '' ? null : $this->template);
        } catch (ManagedProfileRefused|EngagementRefused $refused) {
            $this->addError('target', $refused->getMessage());

            return;
        }

        $this->reset('target', 'template');
        $this->dispatch('toast', type: 'success', message: match (true) {
            $result->connected => __('They had already sent an interest — you are now connected.'),
            $result->changed => __('Interest sent.'),
            default => __('An interest to this profile is already waiting for an answer.'),
        });
    }

    /**
     * B.15 ProfileClaimed: when it is this client, the bureau's access is gone — leave the page.
     *
     * @param  array<string, mixed>  $payload
     */
    public function claimed(array $payload): mixed
    {
        if (($payload['profile'] ?? null) !== $this->code) {
            return null;
        }

        session()->flash('status', __('Profile :code was claimed by the client. Your bureau no longer has access to it.', ['code' => $this->code]));

        return $this->redirectRoute('broker.profiles.index', navigate: true);
    }

    public function render(InterestLists $lists, ProfileFacts $facts, ProfileNames $names, InterestMessages $messages, BureauInterestAllowance $allowance): View
    {
        $client = $this->client();
        $staff = app(BureauGate::class)->staff($this->user());
        /** @var Broker $broker */
        $broker = $staff?->broker;

        $rows = ($this->tab === 'sent'
            ? $lists->box($client, InterestLists::SENT)
            : $lists->box($client, InterestLists::RECEIVED, $this->tab))
            ->with('educationCareer')   // the short facts (education, occupation) — one query, not one per card
            ->paginate(self::PER_PAGE);

        $counts = $lists->counts($client);

        return view('livewire.broker.managed-profiles.client-inbox', [
            'client' => $client,
            'rows' => $rows,
            'cards' => $rows->getCollection()->map(fn (Profile $member): array => [
                'code' => $member->code,
                'name' => $names->forViewer($member, null),
                'facts' => $facts->headline($member),
                'status' => InterestStatus::tryFrom((string) $member->getAttribute('interest_status')),
                'message' => $member->getAttribute('interest_message'),
                'at' => $member->getAttribute('interest_at'),
            ])->all(),
            'counts' => [
                'pending' => $counts[InterestLists::RECEIVED]['pending'] ?? 0,
                'accepted' => $counts[InterestLists::RECEIVED]['accepted'] ?? 0,
                'declined' => $counts[InterestLists::RECEIVED]['declined'] ?? 0,
                'sent' => $counts[InterestLists::SENT]['all'] ?? 0,
            ],
            'canSend' => $client->status === ProfileStatus::Active,
            'allowanceUsed' => $allowance->used($broker),
            'allowanceLimit' => $allowance->limit($broker),
            'templates' => $messages->templates(),
        ])->layout('layouts::broker', ['seo' => SeoData::private(__('Client inbox :code', ['code' => $client->code]).' | Oppam Matrimony')]);
    }

    private function respond(RespondToInterestForClient $respond, string $from, bool $accept): void
    {
        try {
            $respond->handle($this->user(), $this->code, $from, $accept);
        } catch (EngagementRefused|ManagedProfileRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', type: 'error', message: __('This interest is no longer available.'));

            return;
        }

        $this->dispatch('toast', type: 'success', message: $accept
            ? __('Accepted for :code. The member has been told; chat opens once the client joins Oppam.', ['code' => $this->code])
            : __('Declined for :code.', ['code' => $this->code]));
    }

    private function client(): Profile
    {
        $staff = app(BureauGate::class)->authorize($this->user(), BureauPermission::ClientInboxRespond);

        return app(ManagedProfileScope::class)->find($staff, $this->code);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
