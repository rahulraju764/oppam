{{-- A06 stuck orders: unpaid with money captured or a mismatch, or open past their expiry. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Stuck orders') }}</h1>
            <p>{{ __('Unpaid orders with a captured payment or an amount mismatch, and orders still open after they expired. Open one to re-poll, mark it failed or force-activate it.') }}</p>
        </div>
    </div>

    <x-admin.table :headers="[__('Order'), __('Member'), __('Plan'), __('Total'), __('Status'), __('Why'), __('Created (IST)')]" :caption="__('Stuck orders')" :empty="$orders->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-check" :title="__('No stuck orders')" :message="__('Every order is settled.')" />
        </x-slot:emptyState>

        @foreach ($orders as $order)
            <tr wire:key="stuck-{{ $order->code }}">
                <td><a href="{{ route('admin.billing.orders.show', ['order' => $order->code]) }}" wire:navigate><strong>{{ $order->code }}</strong></a></td>
                <td>{{ $order->profile?->code }}</td>
                <td>{{ $order->plan?->name }}</td>
                <td>₹{{ $order->total()->format() }}</td>
                <td>{{ $order->status->label() }}</td>
                <td>{{ $order->failure_reason ?? __('Past expiry, or a payment is held') }}</td>
                <td>{{ $order->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $orders->links() }}
</div>
