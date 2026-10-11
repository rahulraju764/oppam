<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Engagement\SendInterest;
use App\Domain\Engagement\Connections;
use App\Domain\Engagement\InterestMessages;
use App\Domain\Profile\ProfileCards;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\Entitlement;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Send Interest dialog (PRD M06 `Shared\InterestButton`: "a modal with 5 templated messages
 * (+ custom for paid), shows remaining quota, handles quota-exceeded → upgrade modal"). ONE per
 * member page (in the layout): every "Send Interest" button on the page opens it for its profile
 * (`compose-interest`), instead of each row carrying a dialog of its own. Loads nothing until
 * opened. The profile is held by CODE, locked; every rule is SendInterest's.
 */
final class InterestComposer extends Component
{
    #[Locked]
    public string $code = '';

    /** The profile's name as this member may see it (display only). */
    #[Locked]
    public string $name = '';

    /** @var list<array{code: string, label: string}> */
    #[Locked]
    public array $templates = [];

    /** null = unlimited */
    #[Locked]
    public ?int $remaining = null;

    #[Locked]
    public bool $customAllowed = false;

    public string $template = '';

    public string $message = '';

    public ?string $refusal = null;

    #[On('compose-interest')]
    public function open(string $code, ProfileVisibility $visibility, ProfileCards $cards, InterestMessages $messages, EntitlementService $entitlements): void
    {
        $member = $this->member();
        $own = $member->profile;
        $target = preg_match('/^OPM\d{1,10}$/', $code) === 1 ? Profile::query()->where('code', $code)->first() : null;

        if ($own === null || $target === null || $target->id === $own->id || ! $visibility->canView($target, $member)) {
            $this->dispatch('toast', title: __('This profile is no longer available.'), type: 'danger');

            return;
        }

        $this->reset('template', 'message', 'refusal');
        $this->resetErrorBag();
        $this->code = $target->code;
        $this->name = $cards->forViewer($target, $member)->name;
        $this->templates = array_map(fn ($t): array => ['code' => $t->code, 'label' => $t->label], $messages->templates());
        $this->template = $this->templates[0]['code'] ?? '';
        $this->customAllowed = $messages->allowsCustom($own);
        $this->remaining = $entitlements->remaining($own, Entitlement::InterestsPerMonth);

        $this->dispatch('open-modal', name: 'send-interest');
    }

    public function send(SendInterest $send): void
    {
        $this->refusal = null;
        $this->resetErrorBag();
        $target = Profile::query()->where('code', $this->code)->first();

        try {
            $result = $send->handle($this->member(), $target ?? throw new NotFoundHttpException,
                $this->template !== '' ? $this->template : null,
                $this->customAllowed ? $this->message : null);
        } catch (QuotaExceeded $quota) {
            $this->dispatch('close-modal', name: 'send-interest');
            $this->dispatch('quota-reached',
                title: __('You’ve used this month’s interests'),
                message: trans_choice('Your plan includes :count interest a month. Upgrade to send more.|Your plan includes :count interests a month. Upgrade to send more.', $quota->limit, ['count' => $quota->limit]));

            return;
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $errors) {
                $this->addError($field, (string) ($errors[0] ?? ''));
            }

            return;
        } catch (EngagementRefused|ImpersonationRestricted $refused) {
            $this->refusal = $refused->getMessage();

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('close-modal', name: 'send-interest');
            $this->dispatch('toast', title: __('This profile is no longer available.'), type: 'danger');

            return;
        }

        $this->dispatch('close-modal', name: 'send-interest');
        $this->dispatch('interest-changed', code: $this->code, state: $result->state);
        $this->dispatch('toast', type: 'success', title: $result->state === Connections::CONNECTED
            ? __('You are now connected with :name.', ['name' => $this->name])
            : __('Interest sent to :name.', ['name' => $this->name]));
    }

    public function render(): View
    {
        return view('livewire.shared.interest-composer', ['max' => InterestMessages::MAX]);
    }

    private function member(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(404);
    }
}
