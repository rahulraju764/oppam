{{--
    <x-ui.toast-stack> — the slide-in toasts (PRD M08). One per layout; fed by the window `toast`
    event (Livewire $this->dispatch('toast', title: …, body: …, url: …) or a live notification).
    Polite live region; each toast has a close button; text is x-text only (never HTML).
--}}
<div class="toast-stack" x-data="toastStack" x-on:toast.window="add($event.detail)" role="status" aria-live="polite" aria-atomic="false">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="toast-card" :class="'toast-card--' + toast.type"
             x-on:mouseenter="hold(toast)" x-on:mouseleave="arm(toast)" x-on:focusin="hold(toast)" x-on:focusout="arm(toast)">
            <span class="toast-card__icon" aria-hidden="true"><i class="fa" :class="toast.icon"></i></span>
            <div class="toast-card__text">
                <template x-if="toast.url">
                    <button type="button" class="toast-card__title toast-card__link" x-on:click="follow(toast)" x-text="toast.title"></button>
                </template>
                <template x-if="! toast.url">
                    <p class="toast-card__title" x-text="toast.title"></p>
                </template>
                <p class="toast-card__body" x-show="toast.body" x-text="toast.body"></p>
            </div>
            <button type="button" class="toast-card__close" x-on:click="dismiss(toast.id)" aria-label="{{ __('Dismiss') }}">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </template>
</div>
