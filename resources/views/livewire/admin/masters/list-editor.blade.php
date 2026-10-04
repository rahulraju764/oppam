<div>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.masters.index') }}" wire:navigate><i class="fa fa-angle-left" aria-hidden="true"></i> {{ __('Master data') }}</a></p>
            <h1>{{ __($list->label) }}</h1>
            <p>
                @if ($list->isWordList)
                    {{ __('Words the automatic moderation flags look for (whole words, any case). Changes apply to new checks at once.') }}
                @else
                    {{ __('Codes never change. Rows in use can be deactivated (hidden from new choices) but not deleted.') }}
                @endif
            </p>
        </div>
        <x-ui.button variant="outline" icon="fa-download" :href="route('admin.masters.export', array_filter(['list' => $list->key, 'parent' => $list->hasParent() ? $parent : null]))">{{ __('Export CSV') }}</x-ui.button>
    </div>

    <div class="ui-card mb-3">
        <div class="row g-3">
            @if ($list->hasParent())
                <div class="col-12 col-md-6">
                    <x-ui.select :label="$parentLabel" name="parent" wire:model.live="parent" :options="$parents" />
                </div>
            @endif
            <div class="col-12 col-md-6">
                <x-ui.input :label="__('Find')" name="q" type="search" wire:model.live.debounce.400ms="q" :hint="__('Label or code. Clear it to reorder.')" />
            </div>
        </div>
    </div>

    @if ($canAdd)
        <form class="ui-card mb-3" wire:submit="add">
            <h2 class="h6">{{ __('Add a row') }}</h2>
            <div class="row g-3 align-items-end">
                @unless ($list->isWordList)
                    <div class="col-12 col-md-3"><x-ui.input :label="__('Code')" name="newCode" wire:model="newCode" :hint="__('CAPITALS_AND_UNDERSCORES — can\'t be changed later')" required /></div>
                @endunless
                <div class="col-12 col-md-4"><x-ui.input :label="$list->isWordList ? __('Word') : __('Label')" name="newLabel" wire:model="newLabel" required /></div>
                @unless ($list->isWordList)
                    <div class="col-12 col-md-3"><x-ui.input :label="__('Malayalam label')" name="newLabelMl" wire:model="newLabelMl" lang="ml" /></div>
                @endunless
                <div class="col-12 col-md-2"><x-ui.button type="submit" icon="fa-plus" loading="add">{{ __('Add') }}</x-ui.button></div>
            </div>
        </form>
    @endif

    <div wire:loading.class="opacity-50" wire:target="parent,q,move,reorder,toggle,delete">
        <x-admin.table :headers="array_values(array_filter([$canEdit && $sortable ? __('Order') : null, __('Code'), $list->isWordList ? __('Word') : __('Label'), $list->isWordList ? null : __('Malayalam'), __('Status'), __('Used by'), $canEdit ? '' : null]))"
                       :caption="__($list->label)" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-list" :title="__('No rows')" :message="$q !== '' ? __('Nothing matches your search.') : __('Add the first row above.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                @php($id = (int) $row->getKey())
                <tr wire:key="row-{{ $id }}" data-id="{{ $id }}"
                    @if ($canEdit && $sortable) draggable="true" x-data x-on:dragstart="$dispatch('master-drag', { id: {{ $id }} })" x-on:dragover.prevent x-on:drop.prevent="$dispatch('master-drop', { id: {{ $id }} })" @endif>
                    @if ($canEdit && $sortable)
                        <td class="text-nowrap">
                            <span class="admin-drag-handle" aria-hidden="true"><i class="fa fa-arrows-v"></i></span>
                            <button type="button" class="btn btn-sm btn-link" wire:click="move({{ $id }}, -1)" @disabled($loop->first) aria-label="{{ __('Move :label up', ['label' => $row->label]) }}"><i class="fa fa-arrow-up" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-link" wire:click="move({{ $id }}, 1)" @disabled($loop->last) aria-label="{{ __('Move :label down', ['label' => $row->label]) }}"><i class="fa fa-arrow-down" aria-hidden="true"></i></button>
                        </td>
                    @endif
                    <td><code>{{ $row->code }}</code></td>
                    @if ($editId === $id)
                        <td><x-ui.input :label="__('Label')" name="editLabel" :id="'edit-label-'.$id" wire:model="editLabel" /></td>
                        @unless ($list->isWordList)
                            <td><x-ui.input :label="__('Malayalam label')" name="editLabelMl" :id="'edit-label-ml-'.$id" wire:model="editLabelMl" lang="ml" /></td>
                        @endunless
                    @else
                        <td>{{ $row->label }}</td>
                        @unless ($list->isWordList)
                            <td lang="ml">{{ $row->label_ml ?? '—' }}</td>
                        @endunless
                    @endif
                    <td>
                        <x-ui.badge :variant="$row->is_active ? 'success' : 'muted'">{{ $row->is_active ? __('Active') : __('Inactive') }}</x-ui.badge>
                    </td>
                    <td>{{ $list->references === [] ? '—' : ($usage[$id] ?? 0) }}</td>
                    @if ($canEdit)
                        <td class="text-end text-nowrap">
                            @if ($editId === $id)
                                <x-ui.button size="sm" type="button" wire:click="saveEdit" loading="saveEdit">{{ __('Save') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="edit({{ $id }})" icon="fa-pencil">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="toggle({{ $id }})">{{ $row->is_active ? __('Deactivate') : __('Activate') }}</x-ui.button>
                                @if (($usage[$id] ?? 0) === 0)
                                    <x-ui.button size="sm" variant="ghost" type="button" wire:click="delete({{ $id }})" wire:confirm="{{ __('Delete :label? This can\'t be undone.', ['label' => $row->label]) }}" icon="fa-trash">{{ __('Delete') }}</x-ui.button>
                                @endif
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    @if ($canEdit && $sortable && $rows->count() > 1)
        {{-- Drag-and-drop: the rows above dispatch master-drag / master-drop; this sends the new order. --}}
        <div x-data="masterSort" x-on:master-drag.window="drag($event.detail.id)" x-on:master-drop.window="drop($event.detail.id)" hidden></div>
        <p class="small text-muted-brand mt-2">{{ __('Drag rows to reorder, or use the arrow buttons.') }}</p>
    @endif

    @if ($canEdit)
        <div class="ui-card mt-4">
            <h2 class="h6">{{ __('Import from CSV') }}</h2>
            <p class="small text-muted-brand">{{ __('Columns: code, label, label_ml, sort_order, is_active (yes/no). New codes are added, known codes updated. An import never deletes rows or changes codes. Export the list first to get the format.') }}</p>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="mat-label" for="master-csv">{{ __('CSV file') }}</label>
                    <input type="file" id="master-csv" name="csv" class="form-control" accept=".csv,text/csv" wire:model="csv">
                    @error('csv')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="col-12 col-md-6">
                    <x-ui.button type="button" variant="outline" wire:click="previewImport" loading="previewImport" icon="fa-eye" wire:loading.attr="disabled" wire:target="csv,previewImport">{{ __('Preview changes') }}</x-ui.button>
                    <span class="small text-muted-brand" wire:loading wire:target="csv">{{ __('Uploading…') }}</span>
                </div>
            </div>

            @if ($preview !== [])
                @php($errorCount = collect($preview)->where('status', 'error')->count())
                <p class="mt-3 mb-2" role="status">
                    {{ __(':new new, :update to update, :same unchanged, :error with errors.', [
                        'new' => collect($preview)->where('status', 'new')->count(), 'update' => collect($preview)->where('status', 'update')->count(),
                        'same' => collect($preview)->where('status', 'same')->count(), 'error' => $errorCount,
                    ]) }}
                </p>
                <x-admin.table :headers="[__('Line'), __('Code'), __('Label'), __('What happens')]" :caption="__('Import preview')">
                    @foreach ($preview as $row)
                        @continue($row['status'] === 'same')
                        <tr wire:key="preview-{{ $row['line'] }}">
                            <td>{{ $row['line'] }}</td>
                            <td><code>{{ $row['code'] }}</code></td>
                            <td>{{ $row['label'] }}</td>
                            <td>
                                @if ($row['status'] === 'error')
                                    <x-ui.badge variant="warning">{{ __('Error') }}</x-ui.badge> {{ implode(' ', $row['errors']) }}
                                @elseif ($row['status'] === 'new')
                                    <x-ui.badge variant="success">{{ __('New') }}</x-ui.badge>
                                @else
                                    <x-ui.badge>{{ __('Update') }}</x-ui.badge> {{ implode(', ', $row['changes']) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-ui.button class="mt-3" type="button" wire:click="applyImport" loading="applyImport" icon="fa-check" :disabled="$errorCount > 0" wire:loading.attr="disabled" wire:target="csv,applyImport">{{ __('Apply import') }}</x-ui.button>
            @endif
        </div>
    @endif
</div>
