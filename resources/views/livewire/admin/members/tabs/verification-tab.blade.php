<div>
    @if ($verified && $canRevoke)
        <div class="ui-card mb-3">
            <h2 class="h6">{{ __('Force re-verification') }}</h2>
            <p class="small text-muted-brand">{{ __('Removes the Verified badge and asks the member to send an ID again. The reason is recorded in the audit log, not shown to the member.') }}</p>
            <x-ui.textarea :label="__('Reason')" name="revokeReason" wire:model="revokeReason" rows="2" required />
            <x-ui.button size="sm" variant="danger" type="button" wire:click="revoke" loading="revoke" icon="fa-undo"
                         wire:confirm="{{ __('Remove the Verified badge from this member?') }}">{{ __('Remove badge') }}</x-ui.button>
        </div>
    @endif

    <x-admin.table :headers="[__('Sent (IST)'), __('Document'), __('Status'), __('Decided'), '']" :caption="__('ID verification requests')" :empty="$requests->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-id-card-o" :title="__('No ID sent')" :message="__('This member has not sent a document for verification.')" />
        </x-slot:emptyState>

        @foreach ($requests as $request)
            <tr wire:key="vr-{{ $request->id }}">
                <td>{{ $request->submitted_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td>{{ $request->doc_type->label() }} <span class="small text-muted-brand">{{ $request->maskedNumber() }}</span></td>
                <td>
                    <x-ui.badge :variant="$request->status->badgeVariant()">{{ $request->status->label() }}</x-ui.badge>
                    @if ($request->reject_reason)<div class="small">{{ $request->reject_reason->label() }}</div>@endif
                </td>
                <td class="small">
                    @if ($request->decided_at)
                        {{ $request->decidedBy?->name ?? __('System') }} · {{ $request->decided_at->timezone($timezone)->format('d M Y') }}
                    @endif
                    @if ($request->documents_purged_at)
                        <div class="text-muted-brand">{{ __('Files deleted :d', ['d' => $request->documents_purged_at->timezone($timezone)->format('d M Y')]) }}</div>
                    @endif
                </td>
                <td class="text-end">
                    @if ($canOpenQueue && $loop->first)
                        <x-ui.button size="sm" variant="outline" :href="route('admin.verification.review', ['profile' => $code])">{{ __('Open review') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>
</div>
