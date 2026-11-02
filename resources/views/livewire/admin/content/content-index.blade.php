{{-- A08 (P8.1a): one content type: list by status, edit dialog, workflow steps, signed preview. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ $contentType->label() }}</h1>
            <p>{{ __('Only published items are on the site. Changes to a published item wait until they are published.') }}</p>
        </div>
        @if ($contentType->canCreate())
            @can('content.edit')
                <x-ui.button type="button" icon="fa-plus" wire:click="create" loading="create" dusk="content-new">{{ __('New :item', ['item' => $contentType->singular()]) }}</x-ui.button>
            @endcan
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <x-ui.select :label="__('Status')" name="statusFilter" id="content-status" wire:model.live="statusFilter"
                         :options="['' => __('All')] + collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
        </div>
    </div>

    <x-admin.table :headers="[__('Item'), __('Status'), __('Order'), __('Actions')]" :caption="$contentType->label()" :empty="$rows->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-file-text-o" :title="__('Nothing here yet')" :message="__('Nothing matches this status.')" />
        </x-slot:emptyState>

        @foreach ($rows as $row)
            <tr wire:key="content-{{ $row->code }}">
                <td>
                    <strong>{{ \Illuminate\Support\Str::limit($row->title(), 80) }}</strong>
                    @if ($row instanceof \App\Models\SuccessStory)
                        <br><small class="text-muted-brand">
                            {{ $row->isWithdrawn() ? __('Withdrawn: needs fresh consent and a new photo') : ($row->hasBothConsents() ? __('Both partners consented') : __('Consent missing')) }}
                        </small>
                    @endif
                </td>
                <td>
                    <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                    <small class="text-muted-brand">{{ __('Version :v', ['v' => $row->lock_version]) }}</small>
                    @if ($row->hasPendingChanges())
                        <x-ui.badge variant="warning">{{ __('Changes waiting') }}</x-ui.badge>
                    @endif
                    @if ($row->scheduled_at !== null)
                        <br><small class="text-muted-brand">{{ $row->scheduled_at->timezone($timezone)->format('d M Y, h:i A') }} {{ __('IST') }}</small>
                    @endif
                </td>
                <td>{{ $row->sort_order }}</td>
                <td class="text-nowrap">
                    @php($status = $row->status)
                    @can('content.edit')
                        @unless ($row instanceof \App\Models\SuccessStory && $row->isWithdrawn())
                        <x-ui.button size="sm" variant="outline" type="button" loading="edit" wire:click="edit('{{ $row->code }}')" dusk="content-edit-{{ $row->code }}">{{ __('Edit') }}</x-ui.button>
                        @endunless
                    @endcan
                    @unless ($row instanceof \App\Models\SuccessStory && $row->isWithdrawn())
                        <x-ui.button size="sm" variant="ghost" type="button" loading="preview" wire:click="preview('{{ $row->code }}')" dusk="content-preview-{{ $row->code }}">{{ __('Preview') }}</x-ui.button>
                    @endunless

                    @can('content.edit')
                        @if ($status === \App\Enums\ContentStatus::Draft)
                            <x-ui.button size="sm" variant="ghost" type="button" loading="submit" wire:click="submit('{{ $row->code }}')" dusk="content-submit-{{ $row->code }}">{{ __('Send for review') }}</x-ui.button>
                        @elseif ($status !== \App\Enums\ContentStatus::Published)
                            <x-ui.button size="sm" variant="ghost" type="button" loading="backToDraft" wire:click="backToDraft('{{ $row->code }}')">{{ $status === \App\Enums\ContentStatus::Archived ? __('Restore to draft') : __('Back to draft') }}</x-ui.button>
                        @endif
                        @if ($row->hasPendingChanges())
                            <x-ui.button size="sm" variant="ghost" type="button" loading="discardChanges" wire:click="discardChanges('{{ $row->code }}')" wire:confirm="{{ __('Discard the waiting changes?') }}">{{ __('Discard changes') }}</x-ui.button>
                        @endif
                    @endcan

                    @can('content.publish')
                        @if (in_array($status, [\App\Enums\ContentStatus::InReview, \App\Enums\ContentStatus::Scheduled], true))
                            <x-ui.button size="sm" type="button" loading="publish" wire:click="publish('{{ $row->code }}', {{ $row->lock_version }})" dusk="content-publish-{{ $row->code }}">{{ __('Publish now') }}</x-ui.button>
                        @endif
                        @if ($status === \App\Enums\ContentStatus::InReview)
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $row->code }}', 'content-schedule', {{ $row->lock_version }})">{{ __('Schedule') }}</x-ui.button>
                        @endif
                        @if ($row->hasPendingChanges())
                            <x-ui.button size="sm" type="button" loading="publishChanges" wire:click="publishChanges('{{ $row->code }}', {{ $row->lock_version }})" dusk="content-publish-changes-{{ $row->code }}">{{ __('Publish changes') }}</x-ui.button>
                        @endif
                        @if ($status !== \App\Enums\ContentStatus::Archived)
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $row->code }}', 'content-archive')">{{ __('Archive') }}</x-ui.button>
                        @endif
                        @if ($row instanceof \App\Models\SuccessStory && ! $row->isWithdrawn())
                            <x-ui.button size="sm" variant="danger" type="button" wire:click="confirm('{{ $row->code }}', 'content-withdraw')">{{ __('Withdraw') }}</x-ui.button>
                        @endif
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $rows->links() }}

    @can('content.edit')
    <x-ui.modal name="content-form" :title="$editing !== null ? __('Edit :code', ['code' => $editing]) : __('New :item', ['item' => $contentType->singular()])">
        <form wire:submit="save" id="content-form" novalidate>
            <div class="row g-3">
                @include('livewire.admin.content.fields.'.$contentType->value)

                @if ($blockKey === null || $contentType !== \App\Enums\ContentType::HomeBlock)
                    <div class="col-6 col-md-4"><x-ui.input :label="__('Order')" name="sort_order" id="content-order" inputmode="numeric" wire:model="fields.sort_order" required /></div>
                @endif

                @if ($contentType->hasImage())
                    <div class="col-12">
                        <label class="form-label" for="content-image">{{ __('Image (JPG, PNG or WebP, up to 5 MB)') }}</label>
                        <input type="file" class="form-control @error('image') is-invalid @enderror" id="content-image" name="image" accept="image/jpeg,image/png,image/webp" wire:model="image" @error('image') aria-invalid="true" aria-describedby="content-image-error" @enderror>
                        <div wire:loading wire:target="image" class="form-text">{{ __('Uploading…') }}</div>
                        @error('image')<p class="invalid-feedback d-block" id="content-image-error">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="content-form" loading="save, image" dusk="content-save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endcan

    <x-ui.modal name="content-preview" :title="__('Preview link')">
        <p>{{ __('This link shows the real page with the unpublished text. It works for 30 minutes for anyone who has it.') }}</p>
        @if ($previewUrl !== null)
            <x-ui.button :href="$previewUrl" target="_blank" rel="noopener noreferrer" dusk="content-preview-link">{{ __('Open preview') }}</x-ui.button>
        @endif
    </x-ui.modal>

    @can('content.publish')
    <x-ui.modal name="content-schedule" :title="__('Schedule publishing')">
        <x-ui.input :label="__('Publish at (IST)')" name="scheduleAt" id="content-schedule-at" type="datetime-local" wire:model="scheduleAt" required />
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" wire:click="schedule" loading="schedule">{{ __('Schedule') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-admin.confirm-modal name="content-archive" :title="__('Archive this item?')" action="archive" :confirm-label="__('Archive')">
        <p>{{ __('It leaves the site if it was published. Waiting changes are dropped.') }}</p>
    </x-admin.confirm-modal>

    <x-admin.confirm-modal name="content-withdraw" :title="__('Withdraw this story?')" action="withdraw" :confirm-label="__('Withdraw')">
        <p>{{ __('It leaves the site at once, its photo is deleted and the recorded consent is cleared. It can only return with fresh consent and a new photo. Please don’t name anyone in the reason: the audit log can’t be erased.') }}</p>
    </x-admin.confirm-modal>
    @endcan
</div>
