{{--
    /broker/referrals (B.11) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Privacy-limited columns only (R-M13-7): profile code, join date, stage, and commission for the Owner.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Referrals') }}</h1>
                <p>{{ __('Members who joined with your code :code.', ['code' => $code]) }}</p>
            </div>

            <div class="broker-filter">
                <label for="referralStage" class="form-label">{{ __('Show') }}</label>
                <select id="referralStage" name="stage" wire:model.live="stage" class="form-select">
                    <option value="">{{ __('All referrals') }}</option>
                    @foreach ($stages as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="dashboard-content" wire:loading.class="is-loading" wire:target="stage, gotoPage, nextPage, previousPage">
            @if ($referrals->isEmpty())
                <x-ui.empty-state icon="fa-share-alt" :title="$stage === '' ? __('No referrals yet') : __('No referrals at this stage')"
                    :message="__('Share your link or QR poster from Materials. Families who register with your code appear here.')" />
                <p class="text-center mb-0"><a href="{{ route('broker.materials') }}" wire:navigate>{{ __('Open materials') }}</a></p>
            @else
                <div class="table-responsive">
                    <table class="table broker-table align-middle mb-0">
                        <caption class="visually-hidden">{{ __('Your referrals') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Profile') }}</th>
                                <th scope="col">{{ __('Joined') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                @if ($showCommission)
                                    <th scope="col" class="text-end">{{ __('Commission') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($referrals as $referral)
                                @php($rowStage = $referral->getAttribute('stage'))
                                <tr wire:key="referral-{{ $referral->getAttribute('profile_code') }}">
                                    <td>{{ $referral->getAttribute('profile_code') }}</td>
                                    <td>{{ $referral->created_at->timezone(config('oppam.display_timezone'))->format('j M Y') }}</td>
                                    <td><x-ui.badge :variant="$rowStage->badge()">{{ $rowStage->label() }}</x-ui.badge></td>
                                    @if ($showCommission)
                                        <td class="text-end">{{ $rowStage === \App\Enums\ReferralStage::Converted ? $referral->commission()->formatWithSymbol() : '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-ui.pagination :paginator="$referrals" :label="__('Referral pages')" />
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('About referrals') }}</h2>
            <ul class="broker-help__steps">
                <li>{{ __('Joined: registered with your code.') }}</li>
                <li>{{ __('Profile live: approved by our team.') }}</li>
                <li>{{ __('Converted: bought their first plan — commission is earned.') }}</li>
                <li>{{ __('Refunded: the plan was refunded, so the commission is reversed.') }}</li>
            </ul>
            <p class="text-muted-brand small mb-0">{{ __('For their privacy you see profile codes only — never names, photos or contact details.') }}</p>
        </div>
    </div>
</div>
