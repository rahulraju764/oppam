<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\RevertTemplate;
use App\Actions\Admin\Comms\SaveTemplate;
use App\Actions\Admin\Comms\SendTestTemplate;
use App\Domain\Comms\TemplateCopy;
use App\Domain\Content\TextDiff;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A14 template manager (P8.3a, `templates.edit`): the copy of every member notification on each
 * channel it uses (email Markdown, SMS DLT id, in-app text), plus reusable campaign texts. Editing
 * saves a new version (history + compare + revert); test-send goes to the admin's own address.
 * Which template is open is decided server-side from its key — never from a client id.
 */
final class Templates extends Component
{
    #[Url(except: 'notifications')]
    public string $tab = 'notifications';

    /** The template being edited: "{event}.{channel}" or "campaign.{key}" (new campaign: ''). */
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public ?int $comparing = null;

    public string $campaignKey = '';

    public string $campaignName = '';

    public string $campaignChannel = 'email';

    public string $subject = '';

    public string $body = '';

    public string $dltTemplateId = '';

    public function mount(): void
    {
        $this->authorize('templates.edit');
    }

    /** Open the editor for a notification event on one channel (with its live text, or the blank form). */
    public function editEvent(string $event, string $channel): void
    {
        $known = NotificationEvent::tryFrom($event);
        $chan = CommsChannel::tryFrom($channel);
        abort_if($known === null || $chan === null || ! $known->isForMembers() || ! $known->usesChannel($chan->value), 404);

        $this->open(CommunicationTemplate::keyFor($event, $chan));
    }

    public function editCampaign(string $key): void
    {
        abort_unless(str_starts_with($key, 'campaign.') && CommunicationTemplate::query()->where('key', $key)->exists(), 404);
        $this->open($key);
    }

    public function newCampaign(): void
    {
        $this->resetEditor();
        $this->editing = '';
        $this->dispatch('open-drawer', name: 'template-editor');
    }

    public function save(SaveTemplate $save): void
    {
        [$event, $channel, $key, $name] = $this->target();

        try {
            $template = $save->handle($this->admin(), $event, $channel, $this->subject, $this->body, $this->dltTemplateId, $name, $key);
        } catch (ValidationException $invalid) {
            $this->setErrorBag($invalid->validator->errors());

            return;
        }

        $this->editing = $template->key;
        $this->comparing = null;
        $this->dispatch('toast', type: 'success', title: __('Saved as version :v — it is live now.', ['v' => $template->current_version]));
    }

    public function revert(int $version, RevertTemplate $revert): void
    {
        $template = $this->current() ?? abort(404);

        try {
            $saved = $revert->handle($this->admin(), $template, $version);
        } catch (CommsRefused|ValidationException $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->open($saved->key);
        $this->dispatch('toast', type: 'success', title: __('Version :old is live again (saved as version :v).', ['old' => $version, 'v' => $saved->current_version]));
    }

    public function compare(int $version): void
    {
        $this->comparing = $this->comparing === $version ? null : $version;
    }

    public function testSend(SendTestTemplate $test): void
    {
        $template = $this->current() ?? abort(404);

        try {
            $sent = $test->handle($this->admin(), $template);
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: match (true) {
            $template->channel === CommsChannel::InApp => __('In-app copy has nothing to send — the preview is what members see.'),
            $sent === 0 => __('The test SMS could not be sent. See the delivery log.'),
            $template->channel === CommsChannel::Email => __('Test email sent to :to.', ['to' => $this->admin()->email]),
            default => trans_choice('Test SMS sent to :count test number.|Test SMS sent to :count test numbers.', $sent),
        });
    }

    /** @return list<array{event: NotificationEvent, channels: list<array{channel: CommsChannel, template: ?CommunicationTemplate}>}> */
    #[Computed]
    public function eventRows(): array
    {
        $templates = CommunicationTemplate::query()->where('event', '!=', CommunicationTemplate::CAMPAIGN_EVENT)->get()->keyBy('key');

        return array_map(fn (NotificationEvent $event): array => [
            'event' => $event,
            'channels' => array_values(array_map(fn (CommsChannel $channel): array => [
                'channel' => $channel,
                'template' => $templates->get(CommunicationTemplate::keyFor($event->value, $channel)),
            ], array_filter(CommsChannel::cases(), fn (CommsChannel $c): bool => $event->usesChannel($c->value)))),
        ], NotificationEvent::forMemberSettings());
    }

    public function render(): View
    {
        $template = $this->current();
        $versions = $template !== null ? CommunicationTemplateVersion::query()->where('template_id', $template->id)
            ->with('author:id,name')->orderByDesc('version')->limit(20)->get() : collect();
        $compareWith = $this->comparing !== null ? $versions->firstWhere('version', $this->comparing) : null;

        return view('livewire.admin.comms.templates', [
            'campaigns' => $this->tab === 'campaigns'
                ? CommunicationTemplate::query()->where('event', CommunicationTemplate::CAMPAIGN_EVENT)->orderBy('name')->get() : collect(),
            'template' => $template,
            'channel' => $this->editingChannel(),
            'versions' => $versions,
            'diff' => $compareWith !== null ? app(TextDiff::class)->compare(self::lines($compareWith->body), self::lines($this->body)) : null,
            'preview' => $this->preview(),
            'placeholders' => TemplateCopy::PLACEHOLDERS,
            'channelOptions' => CommsChannel::options(),
            'testPhones' => count((array) config('oppam.comms.test_phones', [])),
        ])->layout('layouts::admin', ['title' => __('Message templates')]);
    }

    private function open(string $key): void
    {
        $this->resetEditor();
        $this->editing = $key;
        $template = $this->current();
        $version = $template !== null
            ? CommunicationTemplateVersion::query()->where('template_id', $template->id)->where('version', $template->current_version)->first()
            : null;

        $this->subject = (string) $version?->subject;
        $this->body = (string) $version?->body;
        $this->dltTemplateId = (string) $version?->dlt_template_id;
        if ($template !== null && $template->event === CommunicationTemplate::CAMPAIGN_EVENT) {
            $this->campaignKey = substr($template->key, strlen('campaign.'));
            $this->campaignName = $template->name;
            $this->campaignChannel = $template->channel->value;
        }

        $this->dispatch('open-drawer', name: 'template-editor');
    }

    /** @return array{string, CommsChannel, ?string, ?string} event, channel, campaign key, campaign name */
    private function target(): array
    {
        if ($this->editing === null) {
            abort(404);
        }

        if ($this->editing === '' || str_starts_with($this->editing, 'campaign.')) {
            $channel = $this->editing === '' ? (CommsChannel::tryFrom($this->campaignChannel) ?? CommsChannel::Email) : ($this->current()->channel ?? abort(404));
            $key = $this->editing === '' ? $this->campaignKey : substr($this->editing, strlen('campaign.'));

            return [CommunicationTemplate::CAMPAIGN_EVENT, $channel, $key, $this->campaignName];
        }

        [$event, $channel] = explode('.', $this->editing, 2) + [1 => ''];

        return [$event, CommsChannel::tryFrom($channel) ?? abort(404), null, null];
    }

    private function current(): ?CommunicationTemplate
    {
        return $this->editing !== null && $this->editing !== ''
            ? CommunicationTemplate::query()->where('key', $this->editing)->first()
            : null;
    }

    private function editingChannel(): ?CommsChannel
    {
        if ($this->editing === null) {
            return null;
        }
        if ($this->editing === '') {
            return CommsChannel::tryFrom($this->campaignChannel) ?? CommsChannel::Email;
        }

        return $this->current()->channel ?? CommsChannel::tryFrom(Str::afterLast($this->editing, '.'));
    }

    /** What members would see, with sample values — escaped (email: Markdown with raw HTML escaped). */
    private function preview(): ?HtmlString
    {
        $channel = $this->editingChannel();
        if ($channel === null || trim($this->body) === '') {
            return null;
        }

        $text = TemplateCopy::sample($this->body);

        return $channel === CommsChannel::Email
            ? TemplateCopy::markdown($text)
            : new HtmlString(nl2br(e($text)));
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn (string $l): bool => $l !== ''));
    }

    private function resetEditor(): void
    {
        $this->reset('editing', 'comparing', 'campaignKey', 'campaignName', 'campaignChannel', 'subject', 'body', 'dltTemplateId');
        $this->resetErrorBag();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return Auth::guard('admin')->user();
    }
}
