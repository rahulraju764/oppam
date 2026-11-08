<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\SaveTemplate;
use App\Actions\Admin\Comms\SendTestTemplate;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Message template manager for Email, SMS, and In-App (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Communication Templates'])]
final class Templates extends Component
{
    use WithPagination;

    public string $channelFilter = 'all';

    public bool $showModal = false;

    #[Locked]
    public ?string $editingId = null;

    public string $key = '';

    public string $name = '';

    public string $channel = 'email';

    public string $subject = '';

    public string $dltTemplateId = '';

    public string $body = '';

    public string $variables = '';

    public function mount(): void
    {
        $this->authorize('templates.edit');
    }

    public function openCreateModal(): void
    {
        $this->reset('editingId', 'key', 'name', 'channel', 'subject', 'dltTemplateId', 'body', 'variables');
        $this->channel = 'email';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        /** @var CommunicationTemplate|null $template */
        $template = CommunicationTemplate::query()->findOrFail($id);

        $this->editingId = $template->id;
        $this->key = $template->key;
        $this->name = $template->name;
        $this->channel = $template->channel;
        $this->subject = (string) $template->subject;
        $this->dltTemplateId = (string) $template->dlt_template_id;
        $this->body = $template->body;
        $this->variables = is_array($template->variables) ? (string) json_encode($template->variables, JSON_PRETTY_PRINT) : '';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(SaveTemplate $action): void
    {
        $this->validate([
            'key' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:100'],
            'channel' => ['required', 'in:email,sms,in_app'],
            'subject' => ['nullable', 'string', 'max:200'],
            'dltTemplateId' => ['nullable', 'string', 'max:50'],
            'body' => ['required', 'string'],
        ]);

        $admin = $this->admin();
        $existing = $this->editingId ? CommunicationTemplate::query()->find($this->editingId) : null;

        $parsedVars = null;
        if (! empty(trim($this->variables))) {
            $decoded = json_decode($this->variables, true);
            if (is_array($decoded)) {
                $parsedVars = $decoded;
            }
        }

        try {
            $action->handle($admin, [
                'key' => $this->key,
                'name' => $this->name,
                'channel' => $this->channel,
                'subject' => $this->subject,
                'dlt_template_id' => $this->dltTemplateId,
                'body' => $this->body,
                'variables' => $parsedVars,
            ], $existing);

            $this->showModal = false;
            $this->dispatch('toast', type: 'success', message: __('Template saved successfully.'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $msgs) {
                foreach ($msgs as $m) {
                    $this->addError($key, $m);
                }
            }
        }
    }

    public function testSend(string $id, SendTestTemplate $action): void
    {
        $admin = $this->admin();
        /** @var CommunicationTemplate $template */
        $template = CommunicationTemplate::query()->findOrFail($id);

        $action->handle($admin, $template);
        $this->dispatch('toast', type: 'success', message: __('Test message sent to :recipient', ['recipient' => $admin->email]));
    }

    public function render(): View
    {
        $query = CommunicationTemplate::query()->latest();

        if ($this->channelFilter !== 'all') {
            $query->where('channel', $this->channelFilter);
        }

        return view('livewire.admin.comms.templates', [
            'templates' => $query->paginate(15),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
