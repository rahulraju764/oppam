<div>
    <div class="admin-page-head d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Entitlements Matrix') }}</h1>
            <p class="text-muted mb-0">{{ __('Live member-facing comparison table across plans (PRD §7.3, A15). To modify allowances, edit the plan in A06.') }}</p>
        </div>
        <div>
            <a href="{{ route('admin.billing.plans') }}" class="btn btn-outline-primary" wire:navigate>
                <i class="fa fa-tags me-1" aria-hidden="true"></i> {{ __('Manage Plans & Pricing (A06)') }}
            </a>
        </div>
    </div>

    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
        <i class="fa fa-info-circle fa-lg me-3" aria-hidden="true"></i>
        <div>
            {{ __('Plan allowances (chat replies, contact numbers, daily recommendations, incognito browsing) are stored in ') }}
            <code>plan_features</code>{{ __('. Edits made in the Plans editor immediately reflect here and on the member upgrade page.') }}
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0 text-center">
                <thead class="table-light">
                    <tr>
                        <th class="text-start" style="width: 32%;">{{ __('Feature / Entitlement') }}</th>
                        @foreach ($comparison->plans as $idx => $planName)
                            <th style="width: {{ 68 / max(1, count($comparison->plans)) }}%;" @class(['bg-light-primary fw-bold' => $idx === $comparison->featuredColumn])>
                                {{ $planName }}
                                @if ($idx === $comparison->featuredColumn)
                                    <span class="badge bg-primary ms-1 small">{{ __('Featured') }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($comparison->rows as $row)
                        <tr>
                            <td class="text-start fw-medium">{{ $row['label'] }}</td>
                            @foreach ($row['cells'] as $idx => $cell)
                                <td @class(['bg-light-primary' => $idx === $comparison->featuredColumn])>
                                    @if ($cell === true)
                                        <i class="fa fa-check text-success fs-5" aria-label="Yes"></i>
                                    @elseif ($cell === false)
                                        <i class="fa fa-times text-muted" aria-label="No"></i>
                                    @else
                                        <span class="fw-bold">{{ $cell }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

