<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Actions\Admin\Chat\OpenBreakGlass;
use App\Enums\BreakGlassReference;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Profile;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Start a break-glass read (PRD A13): pick the conversation by the two members' codes — or arrive
 * from a flag review (?flag=…), which fills in the pair and the flag as the reference — then give
 * the reason and the reference (OpenBreakGlass validates and audits) and read in BreakGlassViewer.
 * Before opening, only metadata is shown (status, when it started, how many messages). Also lists
 * the latest break-glass sessions (who, which pair, why) for transparency. Needs
 * `chat.read_conversation` (route + Action). `threadId` is locked: the browser can't swap it.
 */
#[Layout('layouts::admin', ['title' => 'Break-glass read'])]
final class BreakGlass extends Component
{
    #[Url]
    public string $flag = '';

    public string $codeA = '';

    public string $codeB = '';

    #[Locked]
    public ?string $threadId = null;

    public string $reason = '';

    public string $referenceType = '';

    public string $reference = '';

    public function mount(): void
    {
        Gate::authorize('chat.read_conversation');

        $flag = preg_match('/^[0-9a-z]{26}$/i', $this->flag) === 1 ? MessageFlag::query()->find(strtolower($this->flag)) : null;
        if ($flag !== null) {
            $codes = Profile::withTrashed()->whereIn('id', Conversation::query()->find($flag->conversation_id)?->participants()->pluck('profile_id') ?? [])
                ->orderBy('code')->pluck('code')->all();
            [$this->codeA, $this->codeB] = [$codes[0] ?? '', $codes[1] ?? ''];
            $this->threadId = $flag->conversation_id;
            $this->referenceType = BreakGlassReference::Flag->value;
            $this->reference = $flag->id;
        }
    }

    public function find(): void
    {
        Gate::authorize('chat.read_conversation');
        $this->threadId = null;
        $this->codeA = strtoupper(trim($this->codeA));
        $this->codeB = strtoupper(trim($this->codeB));
        $this->validate([
            'codeA' => ['required', 'regex:/^OPM\d{1,10}$/'],
            'codeB' => ['required', 'regex:/^OPM\d{1,10}$/', 'different:codeA'],
        ], [], ['codeA' => __('first member'), 'codeB' => __('second member')]);

        $ids = Profile::withTrashed()->whereIn('code', [$this->codeA, $this->codeB])->pluck('id', 'code');
        $thread = $ids->count() === 2
            ? Conversation::query()->where('pair_key', Conversation::pairKey((string) $ids[$this->codeA], (string) $ids[$this->codeB]))->first()
            : null;

        if ($thread === null) {
            throw ValidationException::withMessages(['codeB' => __('These two members have no conversation.')]);
        }

        $this->threadId = $thread->id;
    }

    public function open(OpenBreakGlass $open): void
    {
        $thread = $this->threadId !== null ? (Conversation::query()->find($this->threadId) ?? abort(404)) : abort(404);

        try {
            $grant = $open->handle($this->admin(), $thread, $this->reason, $this->referenceType, $this->reference);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->redirectRoute('admin.chat.break-glass.read', ['grant' => $grant->id], navigate: true);
    }

    public function render(): View
    {
        $thread = $this->threadId !== null ? Conversation::query()->find($this->threadId, ['id', 'status', 'created_at', 'last_message_at']) : null;

        return view('livewire.admin.chat.break-glass', [
            'thread' => $thread,
            'messageCount' => $thread === null ? 0 : Message::query()->where('conversation_id', $thread->id)->count(),
            'flags' => $thread === null ? [] : MessageFlag::query()->where('conversation_id', $thread->id)->latest()->limit(20)
                ->get(['id', 'rule', 'created_at'])->mapWithKeys(fn (MessageFlag $f): array => [
                    $f->id => $f->rule->label().' · '.$f->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i'),
                ])->all(),
            'referenceTypes' => BreakGlassReference::options(),
            'minutes' => Settings::int(SettingKey::ChatBreakGlassMinutes),
            'recent' => BreakGlassGrant::query()->with(['admin:id,name', 'conversation.participants.profile:id,code'])
                ->latest()->limit(20)->get(),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
