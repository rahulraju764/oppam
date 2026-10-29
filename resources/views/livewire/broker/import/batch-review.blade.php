{{--
    /broker/import/{batch} (§11A B.9 steps 2–6) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Live progress, the column-matching form, the row preview with badges, messages and inline fixes, the fixed-file
    re-upload, consent + import, the imported profiles and "Submit all complete", and cancel.
--}}
{{-- While a job runs: live events drive the bar; a slow visible-only poll back-fills a batch that finished
     before the socket subscribed (or the 5 s fallback when the polling flag is on). Stops once the job is done. --}}
<div class="broker-page" @if ($working) {{ $poll ? 'wire:poll.5s' : 'wire:poll.10s.visible' }} @endif>
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Batch :code', ['code' => $batch->code]) }}</h1>
                <p>
                    <x-ui.badge :variant="$batch->status->badgeVariant()">{{ $batch->status->label() }}</x-ui.badge>
                    · {{ $batch->created_at?->timezone(config('oppam.display_timezone'))->format('j M Y, g:i A') }}
                </p>
            </div>

            @if ($paused)
                <x-ui.alert type="warning">{{ __('Imports are paused for your bureau.') }} {{ $pauseReason?->label() }} {{ __('You can still add profiles one at a time.') }}</x-ui.alert>
            @endif
            @if (! $canHandle)
                <x-ui.alert type="warning">{{ __('Your bureau can’t import profiles right now (KYC or account status). You can cancel this batch.') }}</x-ui.alert>
            @endif
            @if ($duplicateOf)
                <x-ui.alert type="info">{{ __('You uploaded this same file before (batch :code). Check you are not adding the same clients twice.', ['code' => $duplicateOf]) }}</x-ui.alert>
            @endif
            @if ($batch->stop_reason && $batch->status !== \App\Enums\ImportBatchStatus::Importing)
                <x-ui.alert type="info">{{ $batch->stop_reason->message() }}</x-ui.alert>
            @endif
            @if ($batch->error_message && $batch->status === \App\Enums\ImportBatchStatus::Ready)
                <x-ui.alert type="warning">{{ $batch->error_message }}</x-ui.alert>
            @endif

            @if ($working)
                <div class="import-progress" role="status" aria-live="polite">
                    <p class="mb-2">
                        @if ($batch->status === \App\Enums\ImportBatchStatus::Importing)
                            {{ __('Importing: :done of :total profiles added', ['done' => $batch->rows_imported, 'total' => $batch->rows_imported + $toImport]) }}
                        @elseif ($batch->rows_total > 0)
                            {{ __('Checking rows: :done of :total', ['done' => $batch->rows_valid + $batch->rows_warning + $batch->rows_error, 'total' => $batch->rows_total]) }}
                        @else
                            {{ __('Reading the file…') }}
                        @endif
                    </p>
                    <div class="import-progress__bar" aria-hidden="true"><span class="import-progress__fill" style="--progress: {{ $percent }}%"></span></div>
                </div>
            @elseif ($batch->status === \App\Enums\ImportBatchStatus::Failed)
                <x-ui.alert type="danger">{{ $batch->error_message ?? __('This file could not be checked.') }}</x-ui.alert>
                <p class="mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Upload another file') }}</a></p>
            @elseif ($batch->status === \App\Enums\ImportBatchStatus::Mapping && $canHandle)
                <x-ui.alert type="warning">{{ __('Some column names in your sheet don’t match the template. Tell us which column holds each field.') }}</x-ui.alert>
                <form wire:submit="saveMapping" novalidate class="import-mapping">
                    @foreach ($fields as $field => $meta)
                        <div class="form-group" wire:key="map-{{ $field }}">
                            <label for="map-{{ $field }}">{{ $meta['header'] }}@if ($meta['required']) *@endif</label>
                            <select id="map-{{ $field }}" name="mapping[{{ $field }}]" wire:model="mapping.{{ $field }}"
                                    @class(['form-select', 'is-invalid' => $errors->has('mapping.'.$field)])>
                                <option value="">{{ __('Not in my sheet') }}</option>
                                @foreach ($batch->headers ?? [] as $header)
                                    <option value="{{ $header }}">{{ $header }}</option>
                                @endforeach
                            </select>
                            @error('mapping.'.$field) <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <x-ui.button loading="saveMapping" icon="fa-check">{{ __('Save and check the rows') }}</x-ui.button>
                </form>
            @elseif ($batch->status !== \App\Enums\ImportBatchStatus::Cancelled)
                <dl class="managed-detail__facts">
                    <div><dt>{{ __('Rows') }}</dt><dd>{{ $batch->rows_total }}</dd></div>
                    <div><dt>{{ __('Ready') }}</dt><dd>{{ $batch->rows_valid }}</dd></div>
                    <div><dt>{{ __('To check') }}</dt><dd>{{ $batch->rows_warning }}</dd></div>
                    <div><dt>{{ __('Errors') }}</dt><dd>{{ $batch->rows_error }}</dd></div>
                    <div><dt>{{ __('Imported') }}</dt><dd>{{ $batch->rows_imported }}</dd></div>
                    @if ($batch->rows_skipped > 0)
                        <div><dt>{{ __('Skipped') }}</dt><dd>{{ $batch->rows_skipped }}</dd></div>
                    @endif
                    @if ($batch->approvalRate() !== null)
                        <div><dt>{{ __('Approved at review') }}</dt><dd>{{ $batch->approvalRate() }}%</dd></div>
                    @endif
                </dl>
            @endif

            @if ($canHandle && $batch->status === \App\Enums\ImportBatchStatus::Ready)
                @if ($batch->rows_error + $batch->rows_warning > 0)
                    <div class="import-fixes mt-3">
                        <a href="{{ route('broker.import.errors', ['batch' => $batch->code]) }}" class="ui-btn ui-btn--outline" download>
                            <i class="fa fa-download" aria-hidden="true"></i> {{ __('Download rows to fix (errors.xlsx)') }}
                        </a>
                        <form wire:submit="uploadFixes" novalidate class="import-fixes__upload">
                            <label for="import-fixes" class="form-label">{{ __('Upload the fixed file (keep the “row” column)') }}</label>
                            <input type="file" id="import-fixes" name="fixes" wire:model="fixes" accept=".xlsx,.csv"
                                   @class(['form-control', 'is-invalid' => $errors->has('fixes')])
                                   @error('fixes') aria-invalid="true" aria-describedby="import-fixes-error" @enderror>
                            @error('fixes') <p class="invalid-feedback d-block" id="import-fixes-error" role="alert">{{ $message }}</p> @enderror
                            <x-ui.button variant="secondary" size="sm" loading="uploadFixes" icon="fa-upload">{{ __('Re-check these rows') }}</x-ui.button>
                        </form>
                    </div>
                @endif

                <div class="import-consent mt-4">
                    <h2>{{ __('Consent and import') }}</h2>
                    @if (! $isUploader)
                        <p class="mb-0">{{ __('Only the person who uploaded this file can confirm consent and import it.') }}</p>
                    @elseif ($batch->consent_attested_at === null)
                        <form wire:submit="attest" novalidate>
                            <x-ui.checkbox name="consent" wire:model="consent">
                                {{ __('I confirm that every client in this file has agreed to have their profile on Oppam Matrimony, managed by our bureau.') }}
                            </x-ui.checkbox>
                            <x-ui.button loading="attest" icon="fa-check">{{ __('Confirm consent') }}</x-ui.button>
                        </form>
                    @else
                        <p>{{ __('Consent confirmed :when.', ['when' => $batch->consent_attested_at->timezone(config('oppam.display_timezone'))->format('j M Y, g:i A')]) }}</p>
                        @if ($toImport === 0)
                            <p class="mb-0">{{ __('No rows are ready to import. Fix the rows with errors first.') }}</p>
                        @else
                            <p>
                                {{ trans_choice(':count row will become a draft profile.|:count rows will become draft profiles.', $toImport) }}
                                @if ($remaining !== null && $remaining < $toImport)
                                    {{ __('Your bureau can import :n more today; the rest can be imported tomorrow.', ['n' => $remaining]) }}
                                @endif
                                {{ __('Rows with errors are not imported.') }}
                            </p>
                            <x-ui.button type="button" wire:click="import" loading="import" icon="fa-cloud-upload" :disabled="$paused || $remaining === 0">{{ __('Import now') }}</x-ui.button>
                        @endif
                    @endif
                </div>
            @endif

            @if ($canSubmit && $batch->rows_imported > 0 && in_array($batch->status, [\App\Enums\ImportBatchStatus::Ready, \App\Enums\ImportBatchStatus::Completed], true))
                <div class="import-submit mt-4">
                    <h2>{{ __('Send for review') }}</h2>
                    <p>{{ __('Imported profiles are drafts. Complete each one (family, preferences, photos), then send the complete ones to Oppam’s review team.') }}</p>
                    <x-ui.button type="button" variant="secondary" wire:click="submitAll" loading="submitAll" icon="fa-paper-plane">{{ __('Submit all complete profiles') }}</x-ui.button>
                </div>
            @endif

            @if ($canCancel)
                <p class="mt-4 mb-0">
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancel" loading="cancel"
                        wire:confirm="{{ $batch->rows_imported > 0 ? __('Close this batch? Profiles already imported stay; the other rows are skipped.') : __('Cancel this batch? Its files are deleted and nothing is imported.') }}">
                        {{ $batch->rows_imported > 0 ? __('Close batch') : __('Cancel batch') }}
                    </x-ui.button>
                </p>
            @endif
        </div>

        @if ($rows !== null)
            <div class="dashboard-content">
                <nav class="client-inbox__tabs" aria-label="{{ __('Filter rows') }}">
                    @foreach (['all' => __('All'), 'valid' => __('Ready'), 'warning' => __('To check'), 'error' => __('Errors'), 'imported' => __('Imported'), 'skipped' => __('Skipped')] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')" wire:key="filter-{{ $key }}"
                            @class(['client-inbox__tab', 'is-active' => $filter === $key]) @if ($filter === $key) aria-current="page" @endif>{{ $label }}</button>
                    @endforeach
                </nav>

                <div wire:loading.class="is-loading" wire:target="filter, gotoPage, nextPage, previousPage, fixCell">
                    @if ($rows->isEmpty())
                        <x-ui.empty-state icon="fa-check-circle" :title="__('No rows here')" :message="__('Try another filter.')" />
                    @else
                        <ul class="import-rows">
                            @foreach ($rows as $row)
                                <li class="import-rows__item" wire:key="row-{{ $row->row_number }}-{{ $row->updated_at?->timestamp }}" x-data="{ open: false }">
                                    <button type="button" class="import-rows__head" x-on:click="open = ! open" :aria-expanded="open.toString()">
                                        <span class="import-rows__num">{{ __('Row :n', ['n' => $row->row_number]) }}</span>
                                        <span class="import-rows__who">{{ trim(($row->data['raw'][$batch->column_map['first_name'] ?? ''] ?? '').' '.($row->data['raw'][$batch->column_map['last_name'] ?? ''] ?? '')) ?: '—' }}</span>
                                        <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                                    </button>
                                    <div x-show="open" x-cloak>
                                        @if (isset($profiles[$row->imported_profile_id]))
                                            <p class="import-rows__profile"><a href="{{ route('broker.profiles.show', ['code' => $profiles[$row->imported_profile_id]]) }}" wire:navigate>{{ __('Open profile :code', ['code' => $profiles[$row->imported_profile_id]]) }}</a></p>
                                        @endif
                                        @if (($row->messages ?? []) !== [])
                                            <ul class="import-rows__messages">
                                                @foreach ($row->messages as $message)
                                                    <li @class(['is-error' => ($message['level'] ?? '') === 'error'])><strong>{{ $message['field'] ?? '' }}</strong>: {{ $message['text'] ?? '' }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if (($fixable[$row->row_number] ?? []) !== [])
                                            <div class="import-fix">
                                                @foreach ($fixable[$row->row_number] as $header => $value)
                                                    @php $fieldId = 'fix-'.$row->row_number.'-'.$loop->index; @endphp
                                                    <form class="import-fix__cell" wire:key="{{ $fieldId }}"
                                                          x-on:submit.prevent="$wire.fixCell({{ $row->row_number }}, @js($header), $refs.input{{ $loop->index }}.value)">
                                                        <label for="{{ $fieldId }}">{{ $header }}</label>
                                                        <input type="text" id="{{ $fieldId }}" name="{{ $fieldId }}" class="form-control" value="{{ $value }}"
                                                               maxlength="{{ \App\Actions\Broker\Import\FixImportRow::MAX_LENGTH }}" x-ref="input{{ $loop->index }}">
                                                        <x-ui.button size="sm" variant="outline" loading="fixCell">{{ __('Save') }}</x-ui.button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <x-ui.pagination :paginator="$rows" :label="__('Row pages')" />
            </div>
        @endif
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('How importing works') }}</h2>
            <ul class="broker-help__steps">
                <li><strong>{{ __('Ready') }}</strong> — {{ __('can be imported as it is') }}</li>
                <li><strong>{{ __('To check') }}</strong> — {{ __('can be imported, but look at the note (for example a missing photo)') }}</li>
                <li><strong>{{ __('Error') }}</strong> — {{ __('fix it here, or download the rows to fix and upload them again') }}</li>
                <li>{{ __('Imported profiles are drafts — nothing is published until Oppam’s team approves it.') }}</li>
            </ul>
            <p class="small mb-1"><a href="{{ route('broker.import.batches') }}" wire:navigate>{{ __('All batches') }}</a></p>
            <p class="small mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Upload another file') }}</a></p>
        </div>
    </div>
</div>
