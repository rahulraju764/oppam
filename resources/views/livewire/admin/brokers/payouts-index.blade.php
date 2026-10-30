{{--
    A07 payout runs (§11A B.10, B.12): create a run for a period (it opens as the preview), and the
    list of runs. One run is open at a time; a period a paid run already covers needs a reason.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Payout runs') }}</h1>
            <p>{{ __('Monthly commission payouts to verified bureaus. Balances under the minimum roll forward; a large payout needs a second approver.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <h2 class="h6">{{ __('Create a run') }}</h2>
        <form wire:submit="create" novalidate>
            <div class="row g-3 align-items-end">
                <div class="col-6 col-lg-3">
                    <x-ui.input :label="__('Period from')" name="periodStart" id="pr-start" type="date" wire:model="periodStart" required />
                </div>
                <div class="col-6 col-lg-3">
                    <x-ui.input :label="__('Period to')" name="periodEnd" id="pr-end" type="date" wire:model="periodEnd" required />
                </div>
                @if ($needsOverride)
                    <div class="col-12 col-lg-4">
                        <x-ui.textarea :label="__('Override reason (audited)')" name="overrideReason" id="pr-override" wire:model="overrideReason" rows="2" required />
                    </div>
                @endif
                <div class="col-12 col-lg-2">
                    <x-ui.button class="w-100" type="submit" icon="fa-calculator" loading="create" dusk="payout-run-create">{{ __('Preview run') }}</x-ui.button>
                </div>
            </div>
        </form>
    </div>

    <x-admin.table :headers="[__('Run'), __('Period'), __('Lines'), __('To pay'), __('Status'), __('Created (IST)')]" :caption="__('Payout runs')" :empty="$runs->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-bank" :title="__('No payout runs yet')" :message="__('Create the first run for last month above.')" />
        </x-slot:emptyState>

        @foreach ($runs as $run)
            <tr wire:key="run-{{ $run->code }}">
                <td><a href="{{ route('admin.brokers.payouts.show', ['run' => $run->code]) }}" wire:navigate><strong>{{ $run->code }}</strong></a></td>
                <td>{{ $run->label() }}</td>
                <td>{{ $run->items_count }}</td>
                <td>{{ \App\ValueObjects\Money::paise((int) $run->getAttribute('net_total_paise'))->formatWithSymbol() }}</td>
                <td><x-ui.badge :variant="$run->status->badgeVariant()">{{ $run->status->label() }}</x-ui.badge></td>
                <td>{{ $run->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $runs->links() }}
</div>
