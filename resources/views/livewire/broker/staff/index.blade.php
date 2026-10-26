{{--
    /broker/staff (§11A B.8) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Seats, invite form, the bureau's logins and open invitations. Owner only.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Staff') }}</h1>
                <p>{{ __('Give each person their own login, with only the access their work needs.') }}</p>
            </div>

            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="staff-seats" role="status">
                <span class="staff-seats__count">{{ __(':used of :limit seats used', ['used' => $seats['used'], 'limit' => $seats['limit']]) }}</span>
                @if ($seats['open_invitations'] > 0)
                    <span class="form-text">{{ trans_choice('{1} (including 1 open invitation)|[2,*] (including :count open invitations)', $seats['open_invitations'], ['count' => $seats['open_invitations']]) }}</span>
                @endif
            </div>

            @if (! $enabled)
                <x-ui.alert type="info">{{ __('Staff accounts are coming soon. You can keep working with your own login meanwhile.') }}</x-ui.alert>
            @elseif (! $verified)
                <x-ui.alert type="warning">{{ __('Complete your bureau’s KYC before inviting staff.') }}</x-ui.alert>
            @elseif ($seats['used'] > $seats['limit'])
                {{-- B.19: a lowered limit deactivates nobody; the Owner chooses who to deactivate. --}}
                <x-ui.alert type="warning">{{ trans_choice('{1} You are 1 seat over your limit of :limit. Deactivate a staff member to invite again.|[2,*] You are :count seats over your limit of :limit. Deactivate staff members to invite again.', $seats['used'] - $seats['limit'], ['count' => $seats['used'] - $seats['limit'], 'limit' => $seats['limit']]) }}</x-ui.alert>
            @elseif ($seats['free'] < 1)
                <x-ui.alert type="warning">{{ __('All seats are in use. Deactivate a staff member or cancel an invitation to free a seat.') }}</x-ui.alert>
            @else
                <h2 class="managed-form__heading">{{ __('Invite a staff member') }}</h2>
                <form wire:submit="invite" novalidate>
                    <div class="row">
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="staffName">{{ __('Name') }} *</label>
                                <input id="staffName" name="name" type="text" maxlength="100" autocomplete="off" wire:model="name"
                                       @class(['form-control', 'is-invalid' => $errors->has('name')])
                                       @error('name') aria-invalid="true" aria-describedby="staffName-error" @enderror>
                                @error('name') <p class="invalid-feedback d-block" id="staffName-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="staffPhone">{{ __('Mobile number') }} *</label>
                                <input id="staffPhone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="off" wire:model="phone"
                                       @class(['form-control', 'is-invalid' => $errors->has('phone')])
                                       @error('phone') aria-invalid="true" aria-describedby="staffPhone-error" @enderror>
                                @error('phone') <p class="invalid-feedback d-block" id="staffPhone-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="staffRole">{{ __('Role') }} *</label>
                                <select id="staffRole" name="role" wire:model.live="role" @class(['form-select', 'is-invalid' => $errors->has('role')])>
                                    @foreach ($roles as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('role') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="staffScope">{{ __('Profile access') }} *</label>
                                <select id="staffScope" name="scope" wire:model="scope" @class(['form-select', 'is-invalid' => $errors->has('scope')])>
                                    @foreach ($scopes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @if (count($scopes) === 1)
                                    <small class="form-text">{{ __('Telecallers see only the clients assigned to them.') }}</small>
                                @endif
                                @error('scope') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                    <p class="form-text">{{ __('They get an SMS link (valid 72 hours) to set a password and confirm their mobile.') }}</p>
                    <x-ui.button loading="invite" icon="fa-paper-plane">{{ __('Send invitation') }}</x-ui.button>
                </form>
            @endif
        </div>

        <div class="dashboard-content mb-4" wire:loading.class="is-loading" wire:target="cancel">
            <h2 class="managed-form__heading">{{ __('Your team') }}</h2>
            @if ($members->count() <= 1)
                <x-ui.empty-state icon="fa-id-badge" :title="__('Only you so far')"
                    :message="__('Invite managers, data entry staff and telecallers so each works with their own login.')" />
            @endif
            <ul class="staff-list">
                @foreach ($members as $member)
                    <li class="staff-list__item" wire:key="staff-{{ $member->code }}">
                        <div class="staff-list__who">
                            @if ($member->isOwner())
                                <strong>{{ $member->displayName() }}</strong>
                            @else
                                <a href="{{ route('broker.staff.show', ['code' => $member->code]) }}" wire:navigate><strong>{{ $member->displayName() }}</strong></a>
                            @endif
                            <span class="staff-list__meta">{{ $member->code }} · {{ $member->role->label() }} · {{ $member->effectiveScope()->label() }}</span>
                        </div>
                        <div class="staff-list__meta">
                            <x-ui.badge :variant="match ($member->status) { \App\Enums\BureauStaffStatus::Active => 'success', \App\Enums\BureauStaffStatus::Invited => 'warning', default => 'muted' }">{{ $member->status->label() }}</x-ui.badge>
                            <span>{{ trans_choice('{0} no clients assigned|{1} 1 client assigned|[2,*] :count clients assigned', $member->assigned_count, ['count' => $member->assigned_count]) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($invitations->isNotEmpty())
            <div class="dashboard-content" wire:loading.class="is-loading" wire:target="cancel">
                <h2 class="managed-form__heading">{{ __('Open invitations') }}</h2>
                <ul class="staff-list">
                    @foreach ($invitations as $invitation)
                        <li class="staff-list__item" wire:key="invite-{{ $invitation->id }}">
                            <div class="staff-list__who">
                                <strong>{{ $invitation->name }}</strong>
                                <span class="staff-list__meta">
                                    {{ \App\ValueObjects\PhoneNumber::tryParse($invitation->phone)?->masked() }} · {{ $invitation->role->label() }}
                                    · {{ __('expires :date', ['date' => $invitation->expires_at->timezone(config('oppam.display_timezone'))->format('j M, g:i A')]) }}
                                </span>
                            </div>
                            <x-ui.button type="button" size="sm" variant="outline" wire:click="cancel('{{ $invitation->id }}')" loading="cancel('{{ $invitation->id }}')">{{ __('Cancel') }}</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('Roles') }}</h2>
            <ul class="broker-help__steps">
                <li><strong>{{ __('Manager') }}</strong> — {{ __('all profiles, imports, exports, assignment, client inbox; no money or staff') }}</li>
                <li><strong>{{ __('Data entry') }}</strong> — {{ __('adds and edits profiles, imports, notes') }}</li>
                <li><strong>{{ __('Telecaller') }}</strong> — {{ __('answers the client inbox and keeps notes for their clients') }}</li>
            </ul>
            <p class="small mb-0">{{ __('Deactivating someone signs them out everywhere at once.') }}</p>
        </div>
    </div>
</div>
