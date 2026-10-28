{{--
    /broker/import/{batch} (§11A B.9 steps 2–3) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Live progress, the column-matching form, and the row preview with badges and messages.
--}}
{{-- While the job runs: live events drive the bar; a slow visible-only poll back-fills a batch that finished
     before the socket subscribed (or the 5 s fallback when the polling flag is on). Stops once the batch is done. --}}
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

            @if ($duplicateOf)
                <x-ui.alert type="info">{{ __('You uploaded this same file before (batch :code). Check you are not adding the same clients twice.', ['code' => $duplicateOf]) }}</x-ui.alert>
            @endif

            @if ($working)
                <div class="import-progress" role="status" aria-live="polite">
                    <p class="mb-2">{{ $batch->rows_total > 0
                        ? __('Checking rows: :done of :total', ['done' => $batch->rows_valid + $batch->rows_warning + $batch->rows_error, 'total' => $batch->rows_total])
                        : __('Reading the file…') }}</p>
                    <div class="import-progress__bar" aria-hidden="true"><span class="import-progress__fill" style="--progress: {{ $percent }}%"></span></div>
                </div>
            @elseif ($batch->status === \App\Enums\ImportBatchStatus::Failed)
                <x-ui.alert type="danger">{{ $batch->error_message ?? __('This file could not be checked.') }}</x-ui.alert>
                <p class="mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Upload another file') }}</a></p>
            @elseif ($batch->status === \App\Enums\ImportBatchStatus::Mapping)
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
            @else
                <dl class="managed-detail__facts">
                    <div><dt>{{ __('Rows') }}</dt><dd>{{ $batch->rows_total }}</dd></div>
                    <div><dt>{{ __('Ready') }}</dt><dd>{{ $batch->rows_valid }}</dd></div>
                    <div><dt>{{ __('To check') }}</dt><dd>{{ $batch->rows_warning }}</dd></div>
                    <div><dt>{{ __('Errors') }}</dt><dd>{{ $batch->rows_error }}</dd></div>
                </dl>
                @if ($batch->rows_error + $batch->rows_warning > 0)
                    <p class="mt-3 mb-0">
                        <a href="{{ route('broker.import.errors', ['batch' => $batch->code]) }}" class="ui-btn ui-btn--outline" download>
                            <i class="fa fa-download" aria-hidden="true"></i> {{ __('Download rows to fix (errors.xlsx)') }}
                        </a>
                    </p>
                @endif
            @endif
        </div>

        @if ($rows !== null)
            <div class="dashboard-content">
                <nav class="client-inbox__tabs" aria-label="{{ __('Filter rows') }}">
                    @foreach (['all' => __('All'), 'valid' => __('Ready'), 'warning' => __('To check'), 'error' => __('Errors')] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')" wire:key="filter-{{ $key }}"
                            @class(['client-inbox__tab', 'is-active' => $filter === $key]) @if ($filter === $key) aria-current="page" @endif>{{ $label }}</button>
                    @endforeach
                </nav>

                <div wire:loading.class="is-loading" wire:target="filter, gotoPage, nextPage, previousPage">
                    @if ($rows->isEmpty())
                        <x-ui.empty-state icon="fa-check-circle" :title="__('No rows here')" :message="__('Try another filter.')" />
                    @else
                        <ul class="import-rows">
                            @foreach ($rows as $row)
                                <li class="import-rows__item" wire:key="row-{{ $row->row_number }}" x-data="{ open: false }">
                                    <button type="button" class="import-rows__head" x-on:click="open = ! open" :aria-expanded="open.toString()">
                                        <span class="import-rows__num">{{ __('Row :n', ['n' => $row->row_number]) }}</span>
                                        <span class="import-rows__who">{{ trim(($row->data['raw'][$batch->column_map['first_name'] ?? ''] ?? '').' '.($row->data['raw'][$batch->column_map['last_name'] ?? ''] ?? '')) ?: '—' }}</span>
                                        <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                                    </button>
                                    @if (($row->messages ?? []) !== [])
                                        <ul class="import-rows__messages" x-show="open" x-cloak>
                                            @foreach ($row->messages as $message)
                                                <li @class(['is-error' => ($message['level'] ?? '') === 'error'])><strong>{{ $message['field'] ?? '' }}</strong>: {{ $message['text'] ?? '' }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
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
            <h2>{{ __('What the badges mean') }}</h2>
            <ul class="broker-help__steps">
                <li><strong>{{ __('Ready') }}</strong> — {{ __('can be imported as it is') }}</li>
                <li><strong>{{ __('To check') }}</strong> — {{ __('can be imported, but look at the note (for example a missing photo)') }}</li>
                <li><strong>{{ __('Error') }}</strong> — {{ __('must be fixed first; download the rows to fix') }}</li>
            </ul>
            <p class="small mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Upload another file') }}</a></p>
        </div>
    </div>
</div>
