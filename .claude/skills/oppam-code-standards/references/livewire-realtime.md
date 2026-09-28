# Livewire 4 & real-time (Reverb/Echo) rules

The project runs **Livewire 4** (4.4.x). Class-based components as shown here are fully supported;
don't mix in single-file/multi-file component styles without a decision in `docs/decisions.md`.

## Livewire rules

1. **One concern per component.** A component owns one page concern or one interaction (the chat
   thread, the like button, the filter rail). Pages compose children.
2. **Thin components.** Substantial workflows go into Actions/Services. A component method is
   typically: validate → call Action → update local state → dispatch UI event. ~150 lines max.
3. **Validate on the server** (Form Objects / `#[Validate]` / `$this->validate()`), even when the
   browser also validates.
4. **Authorize every action** with `$this->authorize()` or inside the Action. A hidden button is
   not security.
5. **`#[Locked]`** on every public property holding an id, code, owner, price, role or status.
   Prefer passing **codes** in and resolving the model server-side with an owner-scoped query.
6. **Never expose models with sensitive attributes** as public properties (they serialise to the
   browser). Use `#[Computed]` for derived data, or DTO arrays with only display fields.
7. **UI states are mandatory** for every interactive element:
   - loading: `wire:loading` / `wire:loading.attr="disabled"` / skeletons (`#[Lazy]` placeholder)
   - disabled while submitting (no double submits)
   - error: field errors under inputs + a toast for action failures
   - success: toast or inline confirmation
   - empty: a designed empty state with a next step ("No matches yet — complete your preferences")
8. **Paginate** everything unbounded: search results, lists, notifications, chat history (cursor).
   Use `WithPagination` or cursor/`loadMore()`; never load all rows.
9. **`wire:navigate`** for member/broker navigation (keeps the WebSocket and Alpine stores alive);
   add `@persist` for elements that must survive navigation (audio, presence store).
10. **URL state** for filters and tabs: `#[Url]` with `except` defaults to keep URLs clean.
11. **Debounce** text inputs (`wire:model.live.debounce.400ms`), use `.blur` for forms that don't
    need live feedback.
12. **Keys** on every looped child component (`:key="'like-'.$profile->code"`).
13. **Events**: use Livewire `dispatch()` for component-to-component UI signals on the same page;
    use browser/WebSocket events only when the feature genuinely needs cross-tab/cross-user
    updates (chat, notifications, likes to the other person, admin queues).
14. **No polling as a substitute for WebSockets.** `wire:poll` is allowed only as the documented
    fallback (`realtime.polling_fallback`) or for slow admin health panels (≥ 30 s).
15. **Exceptions → user messages**: catch domain exceptions in the component and show a friendly
    message; let unexpected exceptions bubble to the handler (logged, generic error toast).

### Component template

```php
final class InterestButton extends Component
{
    #[Locked] public string $profileCode;
    public bool $sent = false;

    public function send(SendInterest $action): void
    {
        $target = Profile::active()->whereCode($this->profileCode)->firstOrFail();

        try {
            $action->handle(auth()->user()->profile, $target, $this->note);
            $this->sent = true;
            $this->dispatch('toast', type: 'success', message: __('Interest sent'));
        } catch (QuotaExceeded) {
            $this->dispatch('open-upgrade-modal', reason: 'interests');
        }
    }

    public function render(): View { return view('livewire.shared.interest-button'); }
}
```

## Real-time contract (PRD §9)

- **Persist first, broadcast after commit.** The database is the source of truth; the socket is
  only a notification. Every screen that shows live data must be correct after a full reload.
- **Channel authorization re-checks the DB** on every subscribe (participant, not blocked, active
  bureau staff, admin permission). No channel is protected only by its name.
- **Payload minimalism**: codes + display fields. Anything private is re-fetched via an authorized
  Livewire call after the event arrives.
- **Idempotent UI**: events may arrive twice or out of order. Insert by id (ULID sort), de-dupe by
  `client_id`/id.
- **Reconnect back-fill**: on Echo `connected` after a drop, components call `loadSince($lastId)`.
- **Chat events** use `ShouldBroadcastNow`; everything else is queued on `broadcasts`.
- **toOthers()** when the actor's tab already rendered the change (optimistic UI).
- **Typing/presence** use whispers and presence channels — never write to the DB per keystroke.
- **Listener declaration** in components:
  `#[On('echo-private:chat.{conversation.id},.message.sent')]` or `getListeners()` for dynamic ids.
- **Rate limits** on server sends (messages 30/min, likes per settings) and client events.
- **Tests**: every event has a payload test (no private fields) and every channel has allow/deny
  tests (see `oppam-testing`).
