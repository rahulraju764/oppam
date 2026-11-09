{{--
    A14 template manager (P8.3a): notification copy per event × channel, and campaign texts. The
    editor (drawer) saves a new version, shows a live preview with sample values, the version
    history (compare / revert) and test-send.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Message templates') }}</h1>
            <p>{{ __('Change what members receive by email, SMS and in the app. Every save is a new version you can compare and bring back.') }}</p>
        </div>
        @if ($tab === 'campaigns')
            <x-ui.button type="button" icon="fa-plus" wire:click="newCampaign" dusk="template-new-campaign">{{ __('New campaign text') }}</x-ui.button>
        @endif
    </div>

    <div class="ui-card mb-4">
        <nav class="admin-tabs" aria-label="{{ __('Template types') }}">
            @foreach (['notifications' => __('Notification copy'), 'campaigns' => __('Campaign texts')] as $value => $label)
                <button type="button" @class(['admin-tab', 'active' => $tab === $value]) wire:click="$set('tab', '{{ $value }}')"
                        @if ($tab === $value) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    <div wire:loading.class="is-loading" wire:target="tab">
        @if ($tab === 'notifications')
            <x-admin.table :headers="[__('Notification'), __('Email'), __('SMS'), __('In-app')]" :caption="__('Notification copy')">
                @foreach ($this->eventRows as $row)
                    <tr wire:key="tpl-{{ $row['event']->value }}">
                        <th scope="row">{{ $row['event']->label() }}</th>
                        @foreach (\App\Enums\CommsChannel::cases() as $chan)
                            @php($cell = collect($row['channels'])->firstWhere('channel', $chan))
                            <td>
                                @if ($cell === null)
                                    <span class="text-muted-brand" aria-label="{{ __('Not sent on this channel') }}">—</span>
                                @else
                                    <x-ui.button size="sm" variant="ghost" type="button"
                                                 wire:click="editEvent('{{ $row['event']->value }}', '{{ $chan->value }}')"
                                                 wire:loading.attr="disabled" wire:target="editEvent">
                                        @if ($cell['template'])
                                            {{ __('Custom · v:v', ['v' => $cell['template']->current_version]) }}
                                        @else
                                            {{ __('Standard') }}
                                        @endif
                                    </x-ui.button>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </x-admin.table>
        @else
            <x-admin.table :headers="[__('Name'), __('Key'), __('Channel'), __('Version'), '']" :caption="__('Campaign texts')" :empty="$campaigns->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-file-text-o" :title="__('No campaign texts yet')" :message="__('Save a text here to reuse it in campaigns.')" />
                </x-slot:emptyState>
                @foreach ($campaigns as $row)
                    <tr wire:key="camp-{{ $row->key }}">
                        <td>{{ $row->name }}</td>
                        <td><code>{{ $row->key }}</code></td>
                        <td>{{ $row->channel->label() }}</td>
                        <td>v{{ $row->current_version }}</td>
                        <td class="text-end">
                            <x-ui.button size="sm" type="button" wire:click="editCampaign('{{ $row->key }}')" wire:loading.attr="disabled" wire:target="editCampaign">{{ __('Edit') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </div>

    <x-admin.drawer name="template-editor" :title="$template?->name ?? ($editing === '' ? __('New campaign text') : __('Template'))">
        @if ($editing !== null && $channel !== null)
            <form wire:submit="save" novalidate id="templateForm">
                @if ($editing === '' || str_starts_with((string) $editing, 'campaign.'))
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <x-ui.input :label="__('Name')" name="campaignName" id="tplName" wire:model="campaignName" required maxlength="100" />
                        </div>
                        <div class="col-md-6 col-12">
                            <x-ui.input :label="__('Key')" name="campaignKey" id="tplKey" wire:model="campaignKey" required maxlength="60"
                                        :hint="__('Lower-case letters, numbers, underscores.')" :disabled="$editing !== ''" />
                        </div>
                        <div class="col-md-6 col-12">
                            <x-ui.select :label="__('Channel')" name="campaignChannel" id="tplChannel" wire:model.live="campaignChannel"
                                         :options="$channelOptions" :selected="$campaignChannel" :disabled="$editing !== ''" />
                        </div>
                    </div>
                @else
                    <p class="small text-muted-brand mb-3">
                        {{ __('Channel: :channel.', ['channel' => $channel->label()]) }}
                        {{ $template ? __('Live: version :v.', ['v' => $template->current_version]) : __('Members get Oppam’s standard copy until you save one.') }}
                    </p>
                @endif

                @if ($channel === \App\Enums\CommsChannel::Email)
                    <x-ui.input :label="__('Subject')" name="subject" id="tplSubject" wire:model.live.debounce.400ms="subject" required maxlength="200" />
                @endif
                @if ($channel === \App\Enums\CommsChannel::Sms)
                    <x-ui.input :label="__('DLT template id')" name="dltTemplateId" id="tplDlt" wire:model="dltTemplateId" required maxlength="50"
                                :hint="__('The id approved on the DLT portal. The text below must match the registered template.')" />
                @endif
                <x-ui.textarea :label="match ($channel) {
                        \App\Enums\CommsChannel::Email => __('Message (Markdown)'),
                        \App\Enums\CommsChannel::Sms => __('SMS text (as registered)'),
                        default => __('First line = title, then the text'),
                    }" name="body" id="tplBody" wire:model.live.debounce.400ms="body" rows="8" required />

                <details class="mb-3">
                    <summary>{{ __('Placeholders you can use') }}</summary>
                    <ul class="small mb-0">
                        @foreach ($placeholders as $name => $meaning)
                            <li><code>{{ '{'.$name.'}' }}</code> — {{ $meaning }}</li>
                        @endforeach
                    </ul>
                </details>

                @if ($preview)
                    <h3 class="h6">{{ __('Preview (sample values)') }}</h3>
                    <div class="ui-card tpl-preview mb-3" aria-live="polite">
                        @if ($channel === \App\Enums\CommsChannel::Email && $subject !== '')
                            <p><strong>{{ \App\Domain\Comms\TemplateCopy::sample($subject) }}</strong></p>
                        @endif
                        {{ $preview }}
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <x-ui.button loading="save" dusk="template-save">{{ __('Save as new version') }}</x-ui.button>
                    @if ($template)
                        @if ($channel !== \App\Enums\CommsChannel::InApp)
                            <x-ui.button type="button" variant="outline" wire:click="testSend" loading="testSend"
                                         :disabled="$channel === \App\Enums\CommsChannel::Sms && $testPhones === 0">
                                {{ $channel === \App\Enums\CommsChannel::Email ? __('Send a test to me') : __('Send a test SMS') }}
                            </x-ui.button>
                        @endif
                    @endif
                </div>
                @if ($channel === \App\Enums\CommsChannel::Sms && $testPhones === 0)
                    <p class="small text-muted-brand">{{ __('No test numbers are set (COMMS_TEST_PHONES), so SMS can only be previewed.') }}</p>
                @endif
            </form>

            @if ($versions->isNotEmpty())
                <h3 class="h6">{{ __('Versions') }}</h3>
                <ul class="list-unstyled tpl-versions">
                    @foreach ($versions as $v)
                        <li class="d-flex flex-wrap align-items-center gap-2 py-1" wire:key="v-{{ $v->version }}">
                            <strong>v{{ $v->version }}</strong>
                            <span class="small text-muted-brand">{{ $v->created_at->timezone('Asia/Kolkata')->format('d M Y, g:i a') }} · {{ $v->author?->name ?? '—' }}</span>
                            @if ($template && $v->version === $template->current_version)
                                <x-ui.badge variant="success">{{ __('Live') }}</x-ui.badge>
                            @else
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="compare({{ $v->version }})">{{ $comparing === $v->version ? __('Hide changes') : __('Compare with the editor') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="revert({{ $v->version }})" wire:confirm="{{ __('Make version :v live again?', ['v' => $v->version]) }}" loading="revert">{{ __('Bring back') }}</x-ui.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($diff !== null)
                    <ol class="legal-diff" aria-label="{{ __('Changes from version :v to the editor', ['v' => $comparing]) }}">
                        @foreach ($diff as $line)
                            <li @class(['legal-diff__line', 'is-added' => $line->type === 'added', 'is-removed' => $line->type === 'removed'])>
                                <span class="visually-hidden">{{ match ($line->type) { 'added' => __('Added:'), 'removed' => __('Removed:'), default => __('Unchanged:') } }}</span>
                                <span aria-hidden="true">{{ match ($line->type) { 'added' => '+', 'removed' => '−', default => ' ' } }}</span>
                                {{ $line->text }}
                            </li>
                        @endforeach
                    </ol>
                @endif
            @endif
        @endif
    </x-admin.drawer>
</div>
