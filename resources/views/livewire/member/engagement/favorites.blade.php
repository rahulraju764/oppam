{{--
    /favorites (PRD M06): private bookmarks with a private note each. The bookmark on a row's photo
    removes that one; the checkboxes + "Remove selected" remove several. 20 per page, newest first.
    Same frame as /likes: list col-9, ad rail col-3.
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="d-head daily-head">
                        <h1>{{ __('Favorites') }}</h1>
                        <p class="daily-head__expiry">
                            {{ __('Only you can see this list.') }}
                            @if ($limit !== null)
                                {{ __(':used of :limit used', ['used' => number_format($used), 'limit' => number_format($limit)]) }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                    @if ($hidden)
                        {{-- R-M06-6: an admin support session sees the number only, never whom or the notes. --}}
                        <x-ui.empty-state icon="fa-lock" :title="trans_choice(':count favourite|:count favourites', $used, ['count' => number_format($used)])"
                                          :message="__('Favourites and their notes are private and are not shown during a support session.')" />
                    @else
                    @if ($unavailable > 0)
                        <x-ui.alert type="info">
                            {{ trans_choice(':count saved profile is no longer available but still uses one of your favourites.|:count saved profiles are no longer available but still use your favourites.', $unavailable, ['count' => number_format($unavailable)]) }}
                            <x-ui.button type="button" variant="link" size="sm" wire:click="removeUnavailable" loading="removeUnavailable">{{ __('Remove them') }}</x-ui.button>
                        </x-ui.alert>
                    @endif

                    @if ($rows !== [])
                        <div class="fav-toolbar" x-data>
                            <span class="fav-toolbar__count" aria-live="polite"
                                  x-text="$wire.selected.length ? @js(__(':n selected')).replace(':n', $wire.selected.length) : @js(__('Tick profiles to act on several at once'))">{{ __('Tick profiles to act on several at once') }}</span>
                            <span class="fav-toolbar__actions">
                                <x-ui.button type="button" size="sm" icon="fa-envelope" wire:click="interestSelected" loading="interestSelected"
                                             x-bind:disabled="$wire.selected.length === 0">{{ __('Send interest to selected') }}</x-ui.button>
                                <x-ui.button type="button" variant="outline" size="sm" icon="fa-trash-o" wire:click="removeSelected" loading="removeSelected"
                                             x-bind:disabled="$wire.selected.length === 0">{{ __('Remove selected') }}</x-ui.button>
                            </span>
                        </div>
                    @endif

                    <div class="profile-grid">
                        @forelse ($rows as $row)
                            @php($code = $row['card']->code)
                            <x-profile.row :profile="$row['card']" wire:key="favorite-{{ $code }}">
                                <x-slot:actions>
                                    <div class="fav-row">
                                        <div class="form-check fav-row__pick">
                                            <input class="form-check-input" type="checkbox" id="fav-pick-{{ $code }}" name="selected[]" value="{{ $code }}" wire:model="selected">
                                            <label class="form-check-label" for="fav-pick-{{ $code }}">{{ __('Select') }}<span class="visually-hidden"> {{ $row['card']->name }}</span></label>
                                        </div>
                                        <form class="fav-row__note" x-data="{ note: @js($row['note']), saving: false }"
                                              x-on:submit.prevent="saving = true; $wire.saveNote(@js($code), note).finally(() => saving = false)">
                                            <label class="visually-hidden" for="fav-note-{{ $code }}">{{ __('Private note about :name', ['name' => $row['card']->name]) }}</label>
                                            <input type="text" class="form-control form-control-sm @error('note.'.$code) is-invalid @enderror" id="fav-note-{{ $code }}" name="note"
                                                   maxlength="{{ $noteMax }}" x-model="note" placeholder="{{ __('Add a private note') }}"
                                                   @error('note.'.$code) aria-invalid="true" aria-describedby="fav-note-{{ $code }}-error" @enderror>
                                            {{-- Busy state per row (a shared wire:target would spin every row's button). --}}
                                            <x-ui.button variant="ghost" size="sm" x-bind:disabled="saving" x-bind:aria-busy="saving.toString()">{{ __('Save') }}</x-ui.button>
                                            @error('note.'.$code)
                                                <p class="invalid-feedback d-block" id="fav-note-{{ $code }}-error">{{ $message }}</p>
                                            @enderror
                                        </form>
                                    </div>
                                </x-slot:actions>
                            </x-profile.row>
                        @empty
                            <x-ui.empty-state icon="fa-star-o" :title="__('No favourites yet')"
                                              :message="__('Tap the star on a profile to keep it here. Nobody is told.')">
                                <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See my matches') }}</x-ui.button>
                            </x-ui.empty-state>
                        @endforelse
                    </div>

                    <x-ui.pagination :paginator="$page" :label="__('Favorites pages')" />
                    @endif
                </div>

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
