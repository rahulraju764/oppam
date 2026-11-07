<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Contact;

use App\Actions\Admin\Contact\ManageContactSubmission;
use App\Enums\ContactStatus;
use App\Exceptions\Admin\ContactRefused;
use App\Models\AdminUser;
use App\Models\ContactSubmission;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A08 contact inbox (P8.1b): the public contact form's messages. Tabs Open / Replied / Converted /
 * Spam; open one in a drawer to read it, reply by email, assign, convert to a ticket or mark spam.
 * Viewing needs support.view (route); every change re-checks support.respond in the Action.
 */
final class Inbox extends Component
{
    use WithPagination;

    public const TABS = ['open', 'replied', 'converted', 'spam'];

    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Locked]
    public ?string $openCode = null;

    #[Locked]
    public ?int $openVersion = null;

    public string $reply = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'open';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search'], true)) {
            if (! in_array($this->tab, self::TABS, true)) {
                $this->tab = 'open';
            }
            $this->resetPage();
        }
    }

    public function open(string $code): void
    {
        $submission = $this->find($code);
        $this->openCode = $submission->code;
        $this->openVersion = $submission->lock_version;
        $this->reset('reply');
        $this->resetErrorBag();
        $this->dispatch('open-drawer', name: 'contact-message');
    }

    public function assignToMe(ManageContactSubmission $manage): void
    {
        $this->step(fn () => $manage->assign($this->admin(), $this->current(), $this->admin(), $this->openVersion), __('Assigned to you.'));
    }

    public function sendReply(ManageContactSubmission $manage): void
    {
        if ($this->step(fn () => $manage->reply($this->admin(), $this->current(), $this->reply, $this->openVersion), __('Reply sent by email.'))) {
            $this->reset('reply');
        }
    }

    public function convert(ManageContactSubmission $manage): void
    {
        $this->step(fn () => $manage->convert($this->admin(), $this->current(), $this->openVersion), __('Converted to a support ticket.'));
    }

    public function markSpam(ManageContactSubmission $manage, bool $spam = true): void
    {
        $this->step(fn () => $manage->markSpam($this->admin(), $this->current(), $spam, $this->openVersion), $spam ? __('Moved to spam.') : __('Moved back to the inbox.'));
    }

    /** @return LengthAwarePaginator<int, ContactSubmission> */
    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        $statuses = match ($this->tab) {
            'replied' => [ContactStatus::Replied],
            'converted' => [ContactStatus::Converted],
            'spam' => [ContactStatus::Spam],
            default => [ContactStatus::New, ContactStatus::Assigned],
        };
        $search = trim($this->search);

        return ContactSubmission::query()->with('assignee:id,name')
            ->whereIn('status', $statuses)
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('code', strtoupper($search))->orWhere('email', mb_strtolower($search))))
            ->latest('created_at')->latest('id')
            ->paginate(25, ['id', 'code', 'name', 'email', 'category', 'status', 'spam_reason', 'assigned_admin_id', 'created_at']);
    }

    public function render(): View
    {
        $current = $this->openCode !== null ? ContactSubmission::query()->with(['replies.admin:id,name', 'assignee:id,name', 'ticket:id,code'])->where('code', $this->openCode)->first() : null;

        return view('livewire.admin.contact.inbox', [
            'current' => $current,
            'tabs' => [
                'open' => __('Inbox'),
                'replied' => __('Replied'),
                'converted' => __('Converted'),
                'spam' => __('Spam'),
            ],
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::admin', ['title' => __('Contact inbox')]);
    }

    /** @param  Closure(): mixed  $action */
    private function step(Closure $action, string $done): bool
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ContactRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return false;
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }

        $this->openVersion = ContactSubmission::query()->where('code', $this->openCode)->value('lock_version');
        unset($this->submissions);
        $this->dispatch('toast', type: 'success', title: $done);

        return true;
    }

    private function current(): ContactSubmission
    {
        abort_if($this->openCode === null, 404);

        return $this->find($this->openCode);
    }

    private function find(string $code): ContactSubmission
    {
        return ContactSubmission::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
