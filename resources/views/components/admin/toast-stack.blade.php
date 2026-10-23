{{--
    <x-admin.toast-stack> — transient messages. Livewire components call
    $this->dispatch('toast', type: 'success', message: '…') (or `title:`; type 'error' / 'danger');
    errors are announced assertively. An optional `url` adds an "Open" link (the live P0 safety ping).
    Messages are rendered as text (x-text), never HTML.
--}}
<div class="admin-toast-stack" aria-live="polite"
     x-data="{ toasts: [], add(detail) { const id = Date.now() + Math.random(); this.toasts.push({ id, ...detail }); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), detail.sticky ? 20000 : 6000) } }"
     x-on:toast.window="add($event.detail)">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="alert ui-alert mb-0" x-bind:class="['error', 'danger'].includes(toast.type) ? 'ui-alert--danger' : 'ui-alert--success'"
             x-bind:role="['error', 'danger'].includes(toast.type) ? 'alert' : 'status'">
            <span x-text="toast.message ?? toast.title"></span>
            <template x-if="toast.url">
                <a class="ms-2" x-bind:href="toast.url">{{ __('Open') }}</a>
            </template>
        </div>
    </template>
</div>
