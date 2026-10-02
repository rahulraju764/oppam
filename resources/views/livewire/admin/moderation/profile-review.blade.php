<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Review :code', ['code' => $profile->code]) }}</h1>
            <p>{{ $profile->fullName() }} · {{ $profile->gender->label() }}, {{ $profile->age() }} · {{ __('submitted :when', ['when' => $item->submitted_at->diffForHumans()]) }}
                @if ($item->is_priority) · <x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid lane') }}</x-ui.badge>@endif
            </p>
        </div>
        <x-ui.button variant="ghost" :href="route('admin.moderation.profiles')" icon="fa-arrow-left">{{ __('Back to queue') }}</x-ui.button>
    </div>

    @if ($claimedBy)
        <x-ui.alert type="warning">{{ __(':name is reviewing this profile right now. You can look, but not decide.', ['name' => $claimedBy]) }}</x-ui.alert>
    @endif
    @if ($item->status === \App\Enums\ModerationStatus::Escalated)
        <x-ui.alert type="info"><strong>{{ __('Escalated:') }}</strong> {{ $item->reason_note }}</x-ui.alert>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Pre-flags --}}
            <div class="ui-card mb-4">
                <h2 class="h5">{{ __('Automatic checks') }}</h2>
                @forelse ($flags as $flag)
                    <p class="mb-1"><x-ui.badge variant="warning" icon="fa-flag">{{ $flag->message }}</x-ui.badge></p>
                @empty
                    <p class="mb-0 text-muted-brand"><i class="fa fa-check" aria-hidden="true"></i> {{ __('Nothing flagged.') }}</p>
                @endforelse
            </div>

            {{-- Photos: blurred until revealed --}}
            <div class="ui-card mb-4" x-data="{ revealed: false }">
                <div class="d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">{{ __('Photos (:n)', ['n' => $photos->count()]) }}</h2>
                    @if ($photos->isNotEmpty())
                        <button type="button" class="ui-link-btn" x-on:click="revealed = ! revealed" x-text="revealed ? @js(__('Blur photos')) : @js(__('Show photos'))"></button>
                    @endif
                </div>
                <ul class="mod-photo-grid">
                    @foreach ($photos as $photo)
                        <li wire:key="photo-{{ $photo->uuid }}">
                            <img src="{{ $photo->hasGeneratedConversion('card') ? $photo->getUrl('card') : $placeholder }}" alt="{{ __('Photo :n', ['n' => $loop->iteration]) }}"
                                 width="200" height="250" loading="lazy" x-bind:class="{ 'is-blurred': ! revealed }" class="is-blurred">
                            <x-ui.badge :variant="$photo->moderation_status->badgeVariant()">{{ $photo->moderation_status->label() }}</x-ui.badge>
                            @if ($photo->caption)<p class="small mb-0">{{ $photo->caption }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($profile->about)
                <div class="ui-card mb-4">
                    <h2 class="h5">{{ __('About') }}</h2>
                    <p class="mb-0">{{ $profile->about }}</p>
                </div>
            @endif

            @foreach ($sections as $title => $rows)
                <div class="ui-card mb-4">
                    <h2 class="h5">{{ $title }}</h2>
                    <dl class="mod-facts">
                        @foreach ($rows as $label => $value)
                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>

        <div class="col-lg-4">
            <div class="ui-card mod-decision">
                <h2 class="h5">{{ __('Decision') }}</h2>

                @if ($canDecide)
                    <x-ui.button class="w-100 mb-3" type="button" wire:click="approve" loading="approve" icon="fa-check">{{ __('Approve') }}</x-ui.button>

                    <x-ui.select :label="__('Reason (reject / changes)')" name="reason" wire:model="reason" :options="$reasons" :placeholder="__('Choose a reason')" />
                    <x-ui.textarea :label="__('Note to the member')" name="note" wire:model="note" rows="3" :hint="__('Shown to the member. Required for “Other” and for escalations (then it is for the super admin).')" />

                    <x-ui.button class="w-100 mb-2" variant="danger" type="button" wire:click="reject" loading="reject" icon="fa-times">{{ __('Reject') }}</x-ui.button>

                    <x-ui.select :label="__('Step to fix')" name="step" wire:model="step" :options="$steps" />
                    <x-ui.button class="w-100 mb-2" variant="outline" type="button" wire:click="requestChanges" loading="requestChanges" icon="fa-pencil">{{ __('Request changes') }}</x-ui.button>

                    @if ($item->status !== \App\Enums\ModerationStatus::Escalated)
                        <x-ui.button class="w-100 mb-2" variant="ghost" type="button" wire:click="escalate" loading="escalate" icon="fa-level-up">{{ __('Escalate to super admin') }}</x-ui.button>
                    @endif
                    <x-ui.button class="w-100" variant="link" type="button" wire:click="release">{{ __('Skip (back to queue)') }}</x-ui.button>
                @elseif ($item->status === \App\Enums\ModerationStatus::Escalated)
                    <p class="text-muted-brand mb-0">{{ __('Escalated items are decided by a super admin.') }}</p>
                @else
                    <p class="text-muted-brand mb-0">{{ __('You can view this profile but not decide it.') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>
