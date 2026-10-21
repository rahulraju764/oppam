{{--
    A13 break-glass: choose the conversation (two member codes, or from a flag), give the reason and
    the reference, then read it in the time-limited viewer. Only metadata before opening.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Break-glass read') }}</h1>
            <p>{{ __('Read a whole conversation only for a flagged case or a legal request. Every page you read is recorded with your reason; the member can ask to see this log.') }}</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="ui-card">
                <h2 class="admin-section-title mt-0">{{ __('1. The conversation') }}</h2>
                <form wire:submit="find" class="row g-3">
                    <div class="col-sm-6">
                        <x-ui.input :label="__('First member’s code')" name="codeA" id="bgCodeA" wire:model="codeA" placeholder="OPM12370" autocomplete="off" />
                    </div>
                    <div class="col-sm-6">
                        <x-ui.input :label="__('Second member’s code')" name="codeB" id="bgCodeB" wire:model="codeB" placeholder="OPM12371" autocomplete="off" />
                    </div>
                    <div class="col-12">
                        <x-ui.button variant="outline" icon="fa-search" loading="find">{{ __('Find conversation') }}</x-ui.button>
                    </div>
                </form>

                @if ($thread)
                    <dl class="mod-facts mt-3">
                        <dt>{{ __('Members') }}</dt><dd>{{ $codeA }} · {{ $codeB }}</dd>
                        <dt>{{ __('Status') }}</dt><dd>{{ $thread->status->label() }}</dd>
                        <dt>{{ __('Started') }}</dt><dd>{{ $thread->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y') }}</dd>
                        <dt>{{ __('Messages') }}</dt><dd>{{ number_format($messageCount) }}</dd>
                    </dl>
                @endif
            </div>
        </div>

        <div class="col-lg-6">
            <div class="ui-card">
                <h2 class="admin-section-title mt-0">{{ __('2. Why you need to read it') }}</h2>
                @if (! $thread)
                    <x-ui.empty-state icon="fa-lock" :title="__('Find the conversation first')" />
                @else
                    <form wire:submit="open" class="d-grid gap-3">
                        <x-ui.select :label="__('Reference')" name="referenceType" id="bgRefType" wire:model.live="referenceType" error="reference_type" :options="$referenceTypes" :placeholder="__('Choose…')" required />
                        @if ($referenceType === \App\Enums\BreakGlassReference::Flag->value)
                            <x-ui.select :label="__('Flagged message')" name="reference" id="bgRefFlag" wire:model="reference" :options="$flags" :placeholder="$flags === [] ? __('This conversation has no flags') : __('Choose…')" required />
                        @elseif ($referenceType === \App\Enums\BreakGlassReference::Legal->value)
                            <x-ui.input :label="__('Request reference number')" name="reference" id="bgRefLegal" wire:model="reference" required />
                        @endif
                        <x-ui.textarea :label="__('Reason (at least 20 characters; recorded with every page you read)')" name="reason" id="bgReason" wire:model="reason" rows="3" required />
                        <div>
                            <x-ui.button variant="danger" icon="fa-unlock-alt" loading="open">{{ __('Open for :minutes minutes', ['minutes' => $minutes]) }}</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <h2 class="admin-section-title">{{ __('Latest break-glass sessions') }}</h2>
    <x-admin.table :headers="[__('Opened (IST)'), __('By'), __('Members'), __('Reference'), __('Reason'), __('Status')]" :caption="__('Latest break-glass sessions')" :empty="$recent->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-unlock-alt" :title="__('No conversation has been read this way')" />
        </x-slot:emptyState>
        @foreach ($recent as $grant)
            <tr wire:key="grant-{{ $grant->id }}">
                <td>{{ $grant->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</td>
                <td>{{ $grant->admin?->name ?? '—' }}</td>
                <td>{{ $grant->conversation?->participants->map(fn ($p) => $p->profile?->code ?? '—')->sort()->implode(' · ') ?? '—' }}</td>
                <td>{{ $grant->reference_type->label() }}<span class="d-block small text-muted">{{ $grant->reference }}</span></td>
                <td class="small">{{ \Illuminate\Support\Str::limit($grant->reason, 120) }}</td>
                <td>
                    @if ($grant->isActive())
                        <x-ui.badge variant="warning">{{ __('Open') }}</x-ui.badge>
                        @if ($grant->admin_user_id === auth('admin')->id())
                            <a href="{{ route('admin.chat.break-glass.read', ['grant' => $grant->id]) }}" wire:navigate class="d-block small">{{ __('Continue reading') }}</a>
                        @endif
                    @else
                        <x-ui.badge variant="muted">{{ __('Ended') }}</x-ui.badge>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>
</div>
