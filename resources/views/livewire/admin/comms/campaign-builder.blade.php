{{--
    A14 campaign builder (P8.3b1): who (segment, live estimate), what (channel + copy), when and how
    fast. Saves a draft; testing and sending happen on the campaign page.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ $editing ? __('Edit campaign') : __('New campaign') }}</h1>
            <p>{{ __('Saved as a draft. Nothing is sent until you press Send on the campaign page.') }}</p>
        </div>
        <x-ui.button :href="route('admin.comms.campaigns.index')" variant="ghost" icon="fa-arrow-left" wire:navigate>{{ __('All campaigns') }}</x-ui.button>
    </div>

    <form wire:submit="save" novalidate id="campaignForm" x-data>
        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <x-ui.card class="mb-3">
                    <x-slot:header><h2 class="h5 mb-0">{{ __('1. Who gets it') }}</h2></x-slot:header>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <x-ui.select :label="__('Plan')" name="plan" id="cmpPlan" wire:model.live="plan" :options="$plans" :placeholder="__('Any plan')" :selected="$plan" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.select :label="__('Gender')" name="gender" id="cmpGender" wire:model.live="gender" :options="$genders" :placeholder="__('Any')" :selected="$gender" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.select :label="__('Religion')" name="religionId" id="cmpReligion" wire:model.live="religionId" :options="$religions" :placeholder="__('Any')" :selected="$religionId" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.select :label="__('District')" name="districtId" id="cmpDistrict" wire:model.live="districtId" :options="$districts" :placeholder="__('Any')" :selected="$districtId" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.input :label="__('Profile at least (%)')" name="completenessMin" id="cmpCompleteness" type="number" min="0" max="100" wire:model.live.debounce.400ms="completenessMin" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.input :label="__('Active in the last (days)')" name="activeWithinDays" id="cmpActiveWithin" type="number" min="1" wire:model.live.debounce.400ms="activeWithinDays" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.input :label="__('Inactive for at least (days)')" name="inactiveForDays" id="cmpInactiveFor" type="number" min="1" wire:model.live.debounce.400ms="inactiveForDays" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Registered from')" name="registeredFrom" id="cmpRegFrom" type="date" wire:model.live="registeredFrom" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Registered until')" name="registeredTo" id="cmpRegTo" type="date" wire:model.live="registeredTo" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.select :label="__('Has an approved photo')" name="hasPhoto" id="cmpHasPhoto" wire:model.live="hasPhoto" :options="$yesNo" :placeholder="__('Either')" :selected="$hasPhoto" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.select :label="__('ID verified')" name="verified" id="cmpVerified" wire:model.live="verified" :options="$yesNo" :placeholder="__('Either')" :selected="$verified" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-ui.select :label="__('Came through a bureau')" name="viaBureau" id="cmpViaBureau" wire:model.live="viaBureau" :options="$yesNo" :placeholder="__('Either')" :selected="$viaBureau"
                                         :hint="__('Registered with a bureau code, or claimed a profile a bureau created.')" />
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card class="mb-3">
                    <x-slot:header><h2 class="h5 mb-0">{{ __('2. What it says') }}</h2></x-slot:header>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Campaign name (only admins see it)')" name="name" id="cmpName" wire:model="name" required maxlength="150" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.select :label="__('Channel')" name="channel" id="cmpChannel" wire:model.live="channel" :options="$channels" :selected="$channel" required />
                        </div>

                        @if ($templates !== [])
                            <div class="col-12">
                                <label class="form-label" for="cmpTemplate">{{ __('Start from a campaign text') }}</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <select id="cmpTemplate" name="cmpTemplate" class="form-select w-auto" x-ref="tpl">
                                        @foreach ($templates as $id => $label)
                                            <option value="{{ $id }}" @selected($templateId === $id)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="button" variant="outline" size="sm" x-on:click="$wire.useTemplate($refs.tpl.value)" loading="useTemplate">{{ __('Use this text') }}</x-ui.button>
                                </div>
                            </div>
                        @endif

                        @if ($channelEnum !== \App\Enums\CommsChannel::Sms)
                            <div class="col-12">
                                <x-ui.input :label="$channelEnum === \App\Enums\CommsChannel::Email ? __('Email subject') : __('Title')" name="subject" id="cmpSubject" wire:model.blur="subject" required
                                            :maxlength="$channelEnum === \App\Enums\CommsChannel::Email ? 200 : 150" />
                            </div>
                        @else
                            <div class="col-12">
                                <x-ui.input :label="__('DLT template id')" name="dltTemplateId" id="cmpDlt" wire:model.blur="dltTemplateId" required inputmode="numeric"
                                            :hint="__('The approved promotional template. MSG91 sends the registered text; a different text here would be rejected.')" />
                            </div>
                        @endif

                        <div class="col-12">
                            <x-ui.textarea :label="match ($channelEnum) { \App\Enums\CommsChannel::Email => __('Email text (Markdown)'), \App\Enums\CommsChannel::Sms => __('Registered SMS text'), default => __('Message') }"
                                           name="message" id="cmpMessage" wire:model.blur="message" rows="7" required
                                           :hint="__('{first_name} is filled in for each member. Nothing else can be.')" />
                        </div>

                        @if ($channelEnum !== \App\Enums\CommsChannel::Sms)
                            <div class="col-12 col-md-6">
                                <x-ui.input :label="__('Button text')" name="actionText" id="cmpActionText" wire:model.blur="actionText" maxlength="50" :hint="__('Optional.')" />
                            </div>
                            <div class="col-12 col-md-6">
                                <x-ui.input :label="__('Button link')" name="actionUrl" id="cmpActionUrl" wire:model.blur="actionUrl" maxlength="255" :hint="__('A page like /plans, or an https:// address. Clicks are counted.')" />
                            </div>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card class="mb-3">
                    <x-slot:header><h2 class="h5 mb-0">{{ __('3. When and how fast') }}</h2></x-slot:header>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Start (IST)')" name="scheduledAt" id="cmpScheduled" type="datetime-local" wire:model="scheduledAt" :hint="__('Empty = as soon as it is sent (and approved, if it needs it).')" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Messages per minute')" name="throttlePerMinute" id="cmpThrottle" type="number" min="1" wire:model="throttlePerMinute" required />
                        </div>
                    </div>
                    @if ($channelEnum === \App\Enums\CommsChannel::Sms)
                        <p class="small text-muted-brand mt-3 mb-0">{{ __('SMS never goes out during quiet hours: a campaign waits and continues when they end.') }}</p>
                    @endif
                </x-ui.card>
            </div>

            <div class="col-12 col-xl-4">
                <x-ui.card class="mod-decision">
                    <x-slot:header><h2 class="h5 mb-0">{{ __('Audience now') }}</h2></x-slot:header>
                    <p class="admin-stat__value mb-1" aria-live="polite" wire:loading.class="opacity-50">{{ number_format($this->audience) }}</p>
                    <p class="small text-muted-brand">{{ __('Members matching these filters who can receive this channel and haven’t switched offers off. The list is fixed when you send it; anyone who opts out or leaves before their turn is skipped.') }}</p>

                    <h3 class="h6 mt-3">{{ __('Preview') }}</h3>
                    <div class="ui-card tpl-preview">
                        @if ($channelEnum === \App\Enums\CommsChannel::Email)
                            <p class="fw-semibold mb-2">{{ str_replace('{first_name}', 'Anjali', $subject) ?: __('(subject)') }}</p>
                            @foreach (preg_split('/\R{2,}/', trim(str_replace('{first_name}', 'Anjali', $message))) ?: [] as $paragraph)
                                @if (trim($paragraph) !== ''){{ \App\Domain\Comms\TemplateCopy::markdown(trim($paragraph)) }}@endif
                            @endforeach
                        @else
                            @if ($channelEnum === \App\Enums\CommsChannel::InApp)<p class="fw-semibold mb-1">{{ str_replace('{first_name}', 'Anjali', $subject) ?: __('(title)') }}</p>@endif
                            <p class="mb-0">{{ str_replace('{first_name}', 'Anjali', $message) ?: __('(message)') }}</p>
                        @endif
                        @if ($channelEnum !== \App\Enums\CommsChannel::Sms && $actionText !== '')
                            <span class="chip mt-2">{{ $actionText }}</span>
                        @endif
                    </div>

                    <x-slot:footer>
                        <x-ui.button loading="save" class="w-100" dusk="campaign-save">{{ __('Save draft') }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </div>
        </div>
    </form>
</div>
