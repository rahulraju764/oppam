{{-- A06 orders list and the monthly GST export. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Orders') }}</h1>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <x-ui.input :label="__('Search')" name="q" id="ord-q" type="search" wire:model.live.debounce.400ms="q" :hint="__('Order code (ORD…) or profile code (OPM…)')" />
            </div>
            <div class="col-12 col-md-3">
                <x-ui.select :label="__('Status')" name="status" id="ord-status" wire:model.live="status" :options="$statuses" :placeholder="__('Any')" />
            </div>
        </div>
        @can('billing.export')
            <form method="GET" action="{{ route('admin.billing.gst-export') }}" class="row g-3 align-items-end mt-1" data-dusk="gst-export">
                <div class="col-8 col-md-3">
                    <x-ui.input :label="__('GST month')" name="month" id="ord-gst-month" type="month" :value="$lastMonth" required />
                </div>
                <div class="col-4 col-md-3">
                    <x-ui.button variant="outline" type="submit" icon="fa-download">{{ __('GST CSV') }}</x-ui.button>
                </div>
            </form>
        @endcan
    </div>

    <x-admin.table :headers="[__('Order'), __('Member'), __('Plan'), __('Total'), __('Status'), __('Created (IST)')]" :caption="__('Orders')" :empty="$orders->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-file-text-o" :title="__('No orders found')" :message="__('Try another code or status.')" />
        </x-slot:emptyState>

        @foreach ($orders as $order)
            <tr wire:key="order-{{ $order->code }}">
                <td><a href="{{ route('admin.billing.orders.show', ['order' => $order->code]) }}" wire:navigate><strong>{{ $order->code }}</strong></a></td>
                <td>{{ $order->profile?->code }}</td>
                <td>{{ $order->plan?->name }}</td>
                <td>₹{{ $order->total()->format() }}</td>
                <td><x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Paid ? 'success' : 'muted'">{{ $order->status->label() }}</x-ui.badge></td>
                <td>{{ $order->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $orders->links() }}
</div>
