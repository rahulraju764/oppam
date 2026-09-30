{{--
    <x-admin.toast-stack> — transient messages. Livewire components call
    $this->dispatch('toast', type: 'success', message: '…'); errors are announced assertively.
    Messages are rendered as text (x-text), never HTML.
--}}
<div class="admin-toast-stack" aria-live="polite"
     x-data="{ toasts: [], add(detail) { const id = Date.now() + Math.random(); this.toasts.push({ id, ...detail }); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 6000) } }"
     x-on:toast.window="add($event.detail)">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="alert ui-alert mb-0" x-bind:class="toast.type === 'error' ? 'ui-alert--danger' : 'ui-alert--success'"
             x-bind:role="toast.type === 'error' ? 'alert' : 'status'" x-text="toast.message"></div>
    </template>
</div>
