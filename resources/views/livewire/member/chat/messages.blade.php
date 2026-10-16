{{--
    /messages (PRD M07, template messages.php): the same 3 / 6 / 3 split as the dashboard —
    conversations (col-3) | open thread (col-6) | ads (col-3). Below 992 px ONE pane at a time
    (template TODO): without ?with= the list, with it the thread (its header has a back arrow).
--}}
<div>
    <section class="dashboard-section messages-section">
        <div class="container">
            <h1 class="visually-hidden">{{ __('Messages') }}</h1>

            <div class="row dashboard-row">

                {{-- LEFT RAIL — the conversation list --}}
                <div @class(['col-lg-3 col-md-12 col-sm-12 col-12', 'd-none d-lg-block' => $open !== ''])>
                    <livewire:member.chat.inbox :current="$open" :key="'chat-inbox'" />
                </div>

                {{-- CENTRE — the open thread --}}
                <div @class(['col-lg-6 col-md-12 col-sm-12 col-12', 'd-none d-lg-block' => $open === ''])>
                    <div class="dashboard-content">
                        @if ($open !== '')
                            <livewire:member.chat.thread :with-code="$open" :key="'chat-thread-'.$open" />
                        @else
                            <div class="msg-panel msg-panel--empty">
                                <x-ui.empty-state icon="fa-comments-o" :title="__('Choose a conversation')"
                                                  :message="__('Pick someone from your conversations to read and reply. Accept an interest to start a new one.')" />
                            </div>
                        @endif
                    </div>
                </div>

                {{-- RIGHT RAIL — shared promos --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12 d-none d-lg-block">
                    <x-ads.rail />
                </div>

            </div>
        </div>
    </section>
</div>
