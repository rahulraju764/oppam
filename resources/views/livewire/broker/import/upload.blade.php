{{--
    /broker/import (§11A B.9 step 1) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Template downloads and the upload form (sheet + optional photo zip).
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Bulk import') }}</h1>
                <p>{{ __('Add many clients at once from a spreadsheet. Every row is checked before anything is created.') }}</p>
            </div>

            @if (! $enabled)
                <x-ui.alert type="info">{{ __('Bulk import is coming soon. You can add profiles one at a time meanwhile.') }}</x-ui.alert>
            @elseif ($paused)
                <x-ui.alert type="warning">{{ __('Imports are paused for your bureau. Please contact Oppam support. You can still add profiles one at a time.') }}</x-ui.alert>
            @else
                <h2 class="managed-form__heading">{{ __('1. Download the template') }}</h2>
                <p class="form-text">{{ __('It lists the columns in the right order, with drop-downs for religion, mother tongue, education and more.') }}</p>
                <div class="managed-detail__actions mb-4">
                    <a href="{{ route('broker.import.template.xlsx') }}" class="ui-btn ui-btn--outline" download>
                        <i class="fa fa-file-excel-o" aria-hidden="true"></i> {{ __('Excel template (.xlsx)') }}
                    </a>
                    <a href="{{ route('broker.import.template.csv') }}" class="ui-btn ui-btn--ghost" download>
                        <i class="fa fa-file-text-o" aria-hidden="true"></i> {{ __('CSV version') }}
                    </a>
                </div>

                <h2 class="managed-form__heading">{{ __('2. Upload it') }}</h2>
                <form wire:submit="startImport" novalidate class="import-upload">
                    <div class="form-group">
                        <label for="importSheet">{{ __('Filled-in sheet (.xlsx or .csv)') }} *</label>
                        <input id="importSheet" name="sheet" type="file" accept=".xlsx,.csv" wire:model="sheet"
                               @class(['form-control', 'is-invalid' => $errors->has('sheet')])
                               aria-describedby="importSheet-hint @error('sheet') importSheet-error @enderror">
                        <small id="importSheet-hint" class="form-text">{{ __('Up to :rows rows and 10 MB.', ['rows' => $maxRows]) }}</small>
                        <div wire:loading wire:target="sheet" class="form-text">{{ __('Uploading…') }}</div>
                        @error('sheet') <p class="invalid-feedback d-block" id="importSheet-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-group">
                        <label for="importPhotos">{{ __('Photos (.zip, optional)') }}</label>
                        <input id="importPhotos" name="photos" type="file" accept=".zip" wire:model="photos"
                               @class(['form-control', 'is-invalid' => $errors->has('photos')])
                               aria-describedby="importPhotos-hint @error('photos') importPhotos-error @enderror">
                        <small id="importPhotos-hint" class="form-text">{{ __('JPG, PNG, WebP or HEIC, each up to 10 MB; the zip up to 200 MB. Name them in the photo_files column.') }}</small>
                        <div wire:loading wire:target="photos" class="form-text">{{ __('Uploading…') }}</div>
                        @error('photos') <p class="invalid-feedback d-block" id="importPhotos-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <x-ui.button loading="startImport" icon="fa-upload" wire:loading.attr="disabled" wire:target="startImport,sheet,photos">{{ __('Upload and check') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('How it works') }}</h2>
            <ol class="broker-help__steps">
                <li>{{ __('Upload the sheet: we check every row with the same rules as the “Add a profile” form.') }}</li>
                <li>{{ __('See which rows are ready, which need a look, and download the errors to fix them.') }}</li>
                <li>{{ __('Nothing is created until you confirm the clients’ consent and import.') }}</li>
            </ol>
            <p class="small mb-0">{{ __('Only add clients who have agreed to be on Oppam Matrimony.') }}</p>
        </div>
    </div>
</div>
