{{--
    /broker/import/batches (§11A B.9 batch history) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Import batches') }}</h1>
                <p>{{ __('Every file your bureau uploaded, what became of its rows, and how Oppam’s review went.') }}</p>
            </div>

            @if ($batches->isEmpty())
                <x-ui.empty-state icon="fa-upload" :title="__('No batches yet')"
                    :message="__('Upload a filled-in template to add many clients at once.')" />
                <p class="text-center mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Go to bulk import') }}</a></p>
            @else
                <div class="table-responsive" wire:loading.class="is-loading" wire:target="gotoPage, nextPage, previousPage">
                    <table class="table broker-table align-middle mb-0">
                        <caption class="visually-hidden">{{ __('Your import batches') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Batch') }}</th>
                                <th scope="col">{{ __('Uploaded') }}</th>
                                <th scope="col" class="text-end">{{ __('Ready / check / errors') }}</th>
                                <th scope="col" class="text-end">{{ __('Imported') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="text-end">{{ __('Approved') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($batches as $batch)
                                <tr wire:key="batch-{{ $batch->code }}">
                                    <td>
                                        <a href="{{ route('broker.import.batch', ['batch' => $batch->code]) }}" wire:navigate>{{ $batch->code }}</a>
                                        @if ($batch->sheetType())
                                            <span class="import-batches__type">{{ strtoupper($batch->sheetType()) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $batch->created_at->timezone(config('oppam.display_timezone'))->format('j M Y') }}
                                        <span class="import-batches__by">{{ $batch->uploaderStaff?->displayName() ?? '—' }}</span>
                                    </td>
                                    <td class="text-end">{{ $batch->rows_valid }} / {{ $batch->rows_warning }} / {{ $batch->rows_error }}</td>
                                    <td class="text-end">{{ $batch->rows_imported }}</td>
                                    <td><x-ui.badge :variant="$batch->status->badgeVariant()">{{ $batch->status->label() }}</x-ui.badge></td>
                                    <td class="text-end">{{ $batch->approvalRate() !== null ? $batch->approvalRate().'%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-ui.pagination :paginator="$batches" :label="__('Batch pages')" />
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('About batches') }}</h2>
            <ul class="broker-help__steps">
                <li>{{ __('Open a batch to fix rows, confirm consent and import.') }}</li>
                <li>{{ __('Approved: the share of the batch’s reviewed profiles that Oppam’s team approved.') }}</li>
                <li>{{ __('Uploaded files are deleted after 30 days; the rows and profiles stay.') }}</li>
            </ul>
            <p class="small mb-0"><a href="{{ route('broker.import') }}" wire:navigate>{{ __('Upload another file') }}</a></p>
        </div>
    </div>
</div>
