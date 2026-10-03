<div class="ui-card">
    <h2 class="h6">{{ __('Photos') }}</h2>
    @if ($photos->isEmpty())
        <x-ui.empty-state icon="fa-picture-o" :title="__('No photos')" :message="__('This member has not added any photos.')" />
    @else
        <ul class="mod-photo-grid list-unstyled">
            @foreach ($photos as $photo)
                <li wire:key="photo-{{ $photo->id }}">
                    @if ($photo->hasGeneratedConversion('thumb'))
                        <img src="{{ $photo->getUrl('thumb') }}" alt="{{ __('Photo :n', ['n' => $loop->iteration]) }}" width="200" height="200" loading="lazy">
                    @else
                        <span class="text-muted-brand small">{{ __('Processing…') }}</span>
                    @endif
                    <div class="small mt-1">
                        <x-ui.badge :variant="$photo->moderation_status->badgeVariant()">{{ $photo->moderation_status->label() }}</x-ui.badge>
                        @if ($loop->first)<x-ui.badge>{{ __('Main') }}</x-ui.badge>@endif
                        @if ($photo->caption)<span class="d-block">{{ $photo->caption }}</span>@endif
                        @if ($photo->rejection_reason)<span class="d-block text-muted-brand">{{ $photo->rejection_reason }}</span>@endif
                    </div>
                </li>
            @endforeach
        </ul>
        @can('moderation.view')
            <p class="small mt-3 mb-0"><a href="{{ route('admin.moderation.photos') }}" wire:navigate>{{ __('Decide waiting photos in the photo queue') }}</a></p>
        @endcan
    @endif
</div>
