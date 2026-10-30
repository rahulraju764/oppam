{{--
    A07 bulk-import monitor (§11A B.12): paused bureaus first (the auto-pause review queue), then every
    batch with its rates. Buttons are hidden by @can; each Action checks brokers.imports.manage again.
--}}
<div>
    @unless ($embedded)
        <div class="admin-page-head">
            <div>
                <h1>{{ __('Bulk imports') }}</h1>
                <p>{{ __('Every bureau’s import batches, rejection rates and paused bureaus. Daily caps are changed on the bureau’s page.') }}</p>
            </div>
        </div>
    @endunless

    @if ($paused->isNotEmpty())
        <div class="ui-card mb-3">
            <h2 class="h6">{{ __('Paused imports') }}</h2>
            <ul class="list-unstyled mb-0">
                @foreach ($paused as $pausedBureau)
                    <li class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2" wire:key="paused-{{ $pausedBureau->code }}">
                        <div>
                            <a href="{{ route('admin.brokers.show', ['broker' => $pausedBureau->code, 'tab' => 'imports']) }}" wire:navigate><strong>{{ $pausedBureau->code }}</strong></a>
                            · {{ $pausedBureau->displayName() }}
                            <div class="small text-muted-brand">
                                {{ $pausedBureau->imports_paused_reason?->label() }} · {{ $pausedBureau->imports_paused_at?->timezone($timezone)->format('d M Y, H:i') }}
                            </div>
                        </div>
                        @can('brokers.imports.manage')
                            <x-ui.button size="sm" type="button" icon="fa-play" wire:click="confirm('imports-resume', '{{ $pausedBureau->code }}')">{{ __('Resume imports') }}</x-ui.button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif ($embedded)
        @can('brokers.imports.manage')
            <div class="mb-3">
                <x-ui.button size="sm" variant="outline" type="button" icon="fa-pause" wire:click="confirm('imports-pause', '{{ $bureau }}')">{{ __('Pause imports') }}</x-ui.button>
            </div>
        @endcan
    @endif

    <div class="ui-card mb-3">
        <div class="row g-3">
            @unless ($embedded)
                <div class="col-6 col-lg-3">
                    <x-ui.input :label="__('Bureau code')" name="broker" id="im-broker" wire:model.live.debounce.400ms="broker" placeholder="BRK1001" />
                </div>
            @endunless
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Status')" name="status" id="im-status" wire:model.live="status" :options="$statuses" :placeholder="__('Any')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="broker,status,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="array_values(array_filter([__('Batch'), $embedded ? null : __('Bureau'), __('Uploaded'), __('Rows'), __('Imported'), __('Reviewed'), __('Status'), '']))"
                       :caption="__('Import batches')" :empty="$batches->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-upload" :title="__('No import batches')" :message="__('No batch matches these filters.')" />
            </x-slot:emptyState>

            @foreach ($batches as $batch)
                @php($rate = $batch->approvalRate())
                <tr wire:key="batch-{{ $batch->id }}">
                    <td><strong>{{ $batch->code }}</strong></td>
                    @unless ($embedded)
                        <td>
                            <a href="{{ route('admin.brokers.show', ['broker' => $batch->broker?->code, 'tab' => 'imports']) }}" wire:navigate>{{ $batch->broker?->code }}</a>
                            @if ($batch->broker?->imports_paused_at)<div><x-ui.badge variant="warning" icon="fa-pause">{{ __('Paused') }}</x-ui.badge></div>@endif
                        </td>
                    @endunless
                    <td>
                        {{ $batch->created_at->timezone($timezone)->format('d M Y') }}
                        <div class="small text-muted-brand">{{ $batch->uploaderStaff?->code ?? '—' }}</div>
                    </td>
                    <td>{{ $batch->rows_total }}
                        <div class="small text-muted-brand">{{ __(':v valid · :w warnings · :e errors', ['v' => $batch->rows_valid, 'w' => $batch->rows_warning, 'e' => $batch->rows_error]) }}</div>
                    </td>
                    <td>{{ $batch->rows_imported }} <div class="small text-muted-brand">{{ __(':n sent for review', ['n' => $batch->rows_submitted]) }}</div></td>
                    <td>
                        @if ($rate === null)
                            —
                        @else
                            {{ __(':a approved · :r rejected', ['a' => $batch->rows_approved, 'r' => $batch->rows_rejected]) }}
                            <div class="small text-muted-brand">{{ __(':p% approved', ['p' => $rate]) }}</div>
                        @endif
                    </td>
                    <td>
                        <x-ui.badge :variant="$batch->status->badgeVariant()">{{ $batch->status->label() }}</x-ui.badge>
                        {{-- P7.8c (B.12 "flag batch"): rows sharing a mobile pattern; photos matching other profiles --}}
                        @if ((int) ($batch->signal_flags['mobile_pattern'] ?? 0) > 0)
                            <div><x-ui.badge variant="danger" icon="fa-flag">{{ __(':n mobiles share a pattern', ['n' => $batch->signal_flags['mobile_pattern']]) }}</x-ui.badge></div>
                        @endif
                        @if ((int) ($batch->signal_flags['photo_matches'] ?? 0) > 0)
                            <div><x-ui.badge variant="danger" icon="fa-flag">{{ __(':n photos match other profiles', ['n' => $batch->signal_flags['photo_matches']]) }}</x-ui.badge></div>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('brokers.imports.manage')
                            <div class="d-flex flex-wrap justify-content-end gap-1">
                                @if ($batch->files_purged_at === null && $batch->sheetType() !== null)
                                    <x-ui.button size="sm" variant="ghost" icon="fa-file-excel-o"
                                                 :href="route('admin.brokers.imports.source', ['broker' => $batch->broker?->code, 'batch' => $batch->code, 'file' => 'sheet'])">{{ __('Sheet') }}</x-ui.button>
                                    @if ($batch->photos_zip_path)
                                        <x-ui.button size="sm" variant="ghost" icon="fa-file-archive-o"
                                                     :href="route('admin.brokers.imports.source', ['broker' => $batch->broker?->code, 'batch' => $batch->code, 'file' => 'photos'])">{{ __('Photos') }}</x-ui.button>
                                    @endif
                                @endif
                                @if (! $embedded && $batch->broker && ! $batch->broker->imports_paused_at)
                                    <x-ui.button size="sm" variant="ghost" type="button" icon="fa-pause"
                                                 wire:click="confirm('imports-pause', '{{ $batch->broker->code }}')">{{ __('Pause bureau') }}</x-ui.button>
                                @endif
                                @if ($batch->isCancellable())
                                    <x-ui.button size="sm" variant="outline" type="button" icon="fa-times"
                                                 wire:click="confirm('imports-cancel', '{{ $batch->broker?->code }}', '{{ $batch->code }}')">{{ __('Cancel') }}</x-ui.button>
                                @endif
                            </div>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $batches->links() }}
    </div>

    @can('brokers.imports.manage')
        <x-admin.confirm-modal name="imports-pause" :title="__('Pause imports of :code', ['code' => $targetBroker])" action="pause" :confirmLabel="__('Pause imports')">
            <p>{{ __('The bureau can’t upload or import sheets until you resume. Adding single profiles still works.') }}</p>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="imports-resume" :title="__('Resume imports of :code', ['code' => $targetBroker])" action="resume" :confirmLabel="__('Resume')" :danger="false">
            <p>{{ __('The bureau can upload and import sheets again.') }}</p>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="imports-cancel" :title="__('Cancel batch :code', ['code' => $targetBatch])" action="cancelBatch" :confirmLabel="__('Cancel batch')">
            <p>{{ __('Nothing imported yet: the batch is cancelled and its files deleted. Partly imported: the profiles stay and the remaining rows are skipped.') }}</p>
        </x-admin.confirm-modal>
    @endcan
</div>
