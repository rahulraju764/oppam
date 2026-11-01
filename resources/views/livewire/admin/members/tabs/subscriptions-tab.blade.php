<div>
    <x-admin.table :headers="[__('Plan'), __('Source'), __('Status'), __('Starts (IST)'), __('Ends (IST)')]" :caption="__('Subscriptions')" :empty="$subscriptions->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-credit-card" :title="__('No subscriptions')" :message="__('This member is on the Free plan.')" />
        </x-slot:emptyState>

        @foreach ($subscriptions as $subscription)
            <tr wire:key="sub-{{ $subscription->id }}">
                <td>{{ $subscription->plan?->name }}</td>
                <td>{{ $subscription->source->label() }}</td>
                <td>
                    {{ $subscription->status->label() }}
                    @if ($subscription->paused_at)
                        <x-ui.badge variant="warning" icon="fa-pause">{{ __('Paused since :d', ['d' => $subscription->paused_at->timezone($timezone)->format('d M Y')]) }}</x-ui.badge>
                    @elseif ($subscription->ends_at->isPast())
                        <x-ui.badge variant="muted">{{ __('Ended') }}</x-ui.badge>
                    @endif
                </td>
                <td>{{ $subscription->starts_at->timezone($timezone)->format('d M Y') }}</td>
                <td>{{ $subscription->ends_at->timezone($timezone)->format('d M Y') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($orders !== null && $orders->isNotEmpty())
        <h2 class="h6 mt-4">{{ __('Orders') }}</h2>
        <x-admin.table :headers="[__('Order'), __('Plan'), __('Total'), __('Status'), __('Created (IST)')]" :caption="__('This member’s orders')">
            @foreach ($orders as $order)
                <tr wire:key="order-{{ $order->code }}">
                    <td><a href="{{ route('admin.billing.orders.show', ['order' => $order->code]) }}" wire:navigate><strong>{{ $order->code }}</strong></a></td>
                    <td>{{ $order->plan?->name }}</td>
                    <td>₹{{ $order->total()->format() }}</td>
                    <td>{{ $order->status->label() }}</td>
                    <td>{{ $order->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif
</div>
