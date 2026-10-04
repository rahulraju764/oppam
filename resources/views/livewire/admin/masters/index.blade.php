<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Master data') }}</h1>
            <p>{{ __('The lists behind every dropdown in the profile wizard and search. Codes never change; labels can. Rows in use are deactivated, never deleted.') }}</p>
        </div>
    </div>

    <div class="row g-4">
        @foreach ($sections as $section => $lists)
            <div class="col-12 col-md-6 col-xl-4">
                <div class="ui-card h-100">
                    <h2 class="h6">{{ __($section) }}</h2>
                    <ul class="list-unstyled mb-0 admin-master-lists">
                        @foreach ($lists as $list)
                            <li>
                                <a href="{{ route('admin.masters.edit', ['list' => $list['key']]) }}" wire:navigate>{{ __($list['label']) }}</a>
                                <span class="text-muted-brand small">{{ trans_choice(':count row|:count rows', $list['count']) }}@if ($list['scoped']) · {{ __('by parent') }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endforeach
    </div>
</div>
