{{-- A10 canned responses: list (left) and the editor (right). --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Canned responses') }}</h1>
            <p><a href="{{ route('admin.support.tickets') }}" wire:navigate><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back to tickets') }}</a></p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-admin.table :headers="[__('Title'), __('Topic'), '']" :caption="__('Canned responses')" :empty="$responses->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-commenting-o" :title="__('No canned responses yet')" :message="__('Save replies you send often, then insert them into a ticket.')" />
                </x-slot:emptyState>
                @foreach ($responses as $response)
                    <tr wire:key="canned-{{ $response->id }}">
                        <td><strong>{{ $response->title }}</strong><div class="small text-muted-brand">{{ \Illuminate\Support\Str::limit($response->body, 90) }}</div></td>
                        <td>{{ $response->category?->label() ?? __('Any') }}</td>
                        <td class="text-end">
                            <x-ui.button size="sm" variant="outline" type="button" wire:click="edit('{{ $response->id }}')">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="delete('{{ $response->id }}')" loading="delete('{{ $response->id }}')">{{ __('Delete') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </div>

        <div class="col-lg-5">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ $editingId ? __('Edit canned response') : __('New canned response') }}</h2>
                <form wire:submit="save" novalidate>
                    <x-ui.input :label="__('Title')" name="title" id="cannedTitle" wire:model="title" error="title" maxlength="100" required />
                    <div class="mt-3">
                        <x-ui.select :label="__('Topic')" name="category" id="cannedCategory" wire:model="category" :options="$categories" :placeholder="__('Any topic')" error="category" />
                    </div>
                    <div class="mt-3">
                        <x-ui.textarea :label="__('Text')" name="body" id="cannedBody" wire:model="body" error="body" rows="8" maxlength="5000" required
                                       :hint="__('Variables: :list', ['list' => implode(' ', $variables)])" />
                    </div>
                    <div class="admin-decisions mt-3">
                        <x-ui.button type="submit" loading="save">{{ __('Save') }}</x-ui.button>
                        @if ($editingId)
                            <x-ui.button variant="ghost" type="button" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
