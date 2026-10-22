{{--
    A05 review. The document viewer is in-browser only: images arrive already watermarked
    (server-side), PDFs are drawn by pdf.js onto canvases with the same watermark — no download
    control, no right-click, links that die after 10 minutes, every fetch audited.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('ID review: :code', ['code' => $profile->code]) }}</h1>
            <p>
                {{ $request->doc_type->label() }} · {{ $request->maskedNumber() }} ·
                {{ __('sent :when', ['when' => $request->submitted_at->diffForHumans()]) }}
                <x-ui.badge :variant="$request->status->badgeVariant()">{{ $request->status->label() }}</x-ui.badge>
                @if ($request->is_priority)<x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>@endif
            </p>
        </div>
        <x-ui.button variant="ghost" :href="route('admin.verification.index')" icon="fa-arrow-left">{{ __('Back to queue') }}</x-ui.button>
    </div>

    @if ($claimedBy)
        <x-ui.alert type="warning">{{ __(':name is reviewing this request right now. You can look, but not decide or open the documents.', ['name' => $claimedBy]) }}</x-ui.alert>
    @endif
    @if ($request->status === \App\Enums\VerificationStatus::Escalated)
        <x-ui.alert type="info"><strong>{{ __('Escalated:') }}</strong> {{ $request->escalation_note }}</x-ui.alert>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Fraud signals --}}
            <div class="ui-card mb-4">
                <h2 class="h5">{{ __('Automatic checks') }}</h2>
                @forelse ($flags as $row)
                    <p class="mb-1">
                        <x-ui.badge :variant="$row['flag']->isSerious() ? 'danger' : 'warning'" icon="fa-flag">{{ $row['flag']->label() }}</x-ui.badge>
                        <span class="small">{{ $row['detail'] }}</span>
                    </p>
                @empty
                    <p class="mb-0 text-muted-brand"><i class="fa fa-check" aria-hidden="true"></i> {{ __('Nothing flagged.') }}</p>
                @endforelse
            </div>

            {{-- Documents --}}
            <div class="ui-card mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h2 class="h5 mb-0">{{ __('Documents') }}</h2>
                    @if ($documentUrls !== [])
                        <span class="small text-muted-brand" x-data="{ left: {{ max(0, $viewerUntil - now()->getTimestamp()) }} }"
                              x-init="const t = setInterval(() => { left = Math.max(0, left - 1); if (left === 0) { clearInterval(t); $wire.$refresh(); } }, 1000)">
                            <i class="fa fa-clock-o" aria-hidden="true"></i>
                            {{ __('Viewer closes in') }} <strong x-text="`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`" aria-live="off"></strong>
                        </span>
                    @endif
                </div>

                @if ($request->documents_purged_at)
                    <p class="text-muted-brand mb-0 mt-2">{{ __('Deleted on :date (90 days after the decision). Only the decision and the masked number are kept.', ['date' => $request->documents_purged_at->timezone($timezone)->format('d M Y')]) }}</p>
                @elseif (! $hasDocumentPermission)
                    <p class="text-muted-brand mb-0 mt-2">{{ __('Viewing ID documents needs the separate “view documents” permission.') }}</p>
                @elseif ($documentUrls === [])
                    <p class="small text-muted-brand mt-2">{{ __('Opening the viewer is logged, and so is every page it loads. The links work for :m minutes in this browser only.', ['m' => config('oppam.verification.link_minutes')]) }}</p>
                    @if ($canViewDocuments)
                        <x-ui.button type="button" variant="outline" icon="fa-eye" wire:click="viewDocuments" loading="viewDocuments" dusk="view-documents">{{ __('View documents') }}</x-ui.button>
                    @endif
                @else
                    <p class="small mt-2"><i class="fa fa-lock" aria-hidden="true"></i> {{ __('Access is logged. Don’t save, copy or photograph these documents.') }}</p>
                    <div class="vf-viewer-grid">
                        @foreach ($request->documents as $document)
                            @php($url = $documentUrls[$document->side->slug()] ?? null)
                            @continue($url === null)
                            <figure class="vf-viewer" wire:key="doc-{{ $document->side->value }}-{{ $viewerUntil }}" x-data x-on:contextmenu.prevent x-on:dragstart.prevent>
                                <figcaption>{{ $document->side->label() }}</figcaption>
                                @if ($document->isPdf())
                                    <div class="vf-viewer__pdf" x-data="pdfDocument(@js($url), @js($watermark))" role="img" aria-label="{{ __(':side of the document (PDF)', ['side' => $document->side->label()]) }}">
                                        <p class="small text-muted-brand" x-show="loading">{{ __('Loading…') }}</p>
                                        <p class="small" x-show="failed" x-cloak>{{ __('The document could not be shown. The link may have expired — close and open the viewer again.') }}</p>
                                        <div x-ref="pages"></div>
                                    </div>
                                @else
                                    <img src="{{ $url }}" alt="{{ __(':side of the document', ['side' => $document->side->label()]) }}" referrerpolicy="no-referrer" draggable="false">
                                @endif
                                <div class="vf-viewer__watermark" aria-hidden="true">
                                    @for ($i = 0; $i < 12; $i++)<span>{{ $watermark }}</span>@endfor
                                </div>
                            </figure>
                        @endforeach
                    </div>
                    <x-ui.button type="button" variant="ghost" size="sm" icon="fa-times" wire:click="closeViewer">{{ __('Close viewer') }}</x-ui.button>
                @endif
            </div>

            {{-- Member --}}
            <div class="ui-card mb-4">
                <h2 class="h5">{{ __('Member') }}</h2>
                <dl class="mod-facts">
                    <div><dt>{{ __('Profile name') }}</dt><dd>{{ $profile->fullName() }}</dd></div>
                    <div><dt>{{ __('Name as on the document') }}</dt><dd>{{ $request->name_on_document ?? '—' }}</dd></div>
                    <div><dt>{{ __('Date of birth') }}</dt><dd>{{ $profile->dob?->format('d M Y') }} ({{ $profile->age() }})</dd></div>
                    <div><dt>{{ __('Gender') }}</dt><dd>{{ $profile->gender->label() }}</dd></div>
                    <div><dt>{{ __('Profile status') }}</dt><dd>{{ $profile->status->label() }}</dd></div>
                    <div><dt>{{ __('Account') }}</dt><dd>{{ $member?->status->label() ?? '—' }}</dd></div>
                    <div><dt>{{ __('Plan') }}</dt><dd>{{ $plan ?? __('Free') }}</dd></div>
                    <div><dt>{{ __('Document number') }}</dt><dd>{{ $request->maskedNumber() }} <span class="small text-muted-brand">{{ __('(compare the last 4)') }}</span></dd></div>
                    <div><dt>{{ __('Consent given') }}</dt><dd>{{ $request->consented_at->timezone($timezone)->format('d M Y, g:i a') }}</dd></div>
                </dl>
                @can('members.view')
                    <a href="{{ route('admin.members.show', ['profile' => $profile->code]) }}" class="small">{{ __('Open member page') }}</a>
                @endcan
            </div>

            {{-- History --}}
            @if ($history->isNotEmpty())
                <div class="ui-card mb-4">
                    <h2 class="h5">{{ __('Earlier requests') }}</h2>
                    <ul class="list-unstyled mb-0">
                        @foreach ($history as $old)
                            <li class="mb-1" wire:key="old-{{ $old->id }}">
                                {{ $old->submitted_at->timezone($timezone)->format('d M Y') }} · {{ $old->doc_type->label() }} {{ $old->maskedNumber() }} ·
                                <x-ui.badge :variant="$old->status->badgeVariant()">{{ $old->status->label() }}</x-ui.badge>
                                @if ($old->reject_reason)<span class="small">{{ $old->reject_reason->label() }}</span>@endif
                                @if ($old->decidedBy)<span class="small text-muted-brand">— {{ $old->decidedBy->name }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Malayali naming variants (A05) --}}
            <div class="ui-card mb-4">
                <h2 class="h5">{{ __('Kerala naming variants — not a mismatch') }}</h2>
                <ul class="small mb-0">
                    <li>{{ __('Initials for the house name or father’s name: “Anjali K.”, “K. Anjali”, “P.V. Thomas”.') }}</li>
                    <li>{{ __('The father’s name in place of the surname (“Anjali Krishnan” for Anjali Nair), or no surname at all — common on Aadhaar.') }}</li>
                    <li>{{ __('Spelling: Sree / Sri, ee / i, th / t, sh / s, double letters; words in another order.') }}</li>
                    <li>{{ __('Married women may show the husband’s name or house name. Ask, don’t reject, when only the surname differs.') }}</li>
                </ul>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="ui-card mod-decision">
                <h2 class="h5">{{ __('Decision') }}</h2>

                @if ($canDecide)
                    <x-ui.button class="w-100 mb-3" type="button" wire:click="approve" loading="approve" icon="fa-check" dusk="verify-approve">{{ __('Approve — add Verified badge') }}</x-ui.button>

                    <x-ui.select :label="__('Reason (reject)')" name="reason" wire:model="reason" :options="$reasons" :placeholder="__('Choose a reason')" />
                    <x-ui.textarea :label="__('Note to the member')" name="note" wire:model="note" rows="3" :hint="__('Shown below the reason. Required for “Other”.')" />
                    <x-ui.button class="w-100 mb-3" variant="danger" type="button" wire:click="reject" loading="reject" icon="fa-times">{{ __('Reject') }}</x-ui.button>

                    @if ($request->status !== \App\Enums\VerificationStatus::Escalated)
                        <x-ui.textarea :label="__('Escalation note (internal)')" name="escalationNote" wire:model="escalationNote" rows="2" :hint="__('For the super admin; never shown to the member.')" />
                        <x-ui.button class="w-100 mb-2" variant="ghost" type="button" wire:click="escalate" loading="escalate" icon="fa-level-up">{{ __('Escalate to super admin') }}</x-ui.button>
                    @endif
                    <x-ui.button class="w-100" variant="link" type="button" wire:click="release">{{ __('Skip (back to queue)') }}</x-ui.button>
                @elseif (! $request->status->isOpen())
                    <p class="mb-1"><x-ui.badge :variant="$request->status->badgeVariant()">{{ $request->status->label() }}</x-ui.badge></p>
                    <p class="small text-muted-brand mb-0">
                        {{ $request->decidedBy?->name }} · {{ $request->decided_at?->timezone($timezone)->format('d M Y, g:i a') }}
                        @if ($request->reject_reason)<br>{{ $request->reject_reason->label() }}@endif
                        @if ($request->member_note)<br>“{{ $request->member_note }}”@endif
                    </p>
                @elseif ($request->status === \App\Enums\VerificationStatus::Escalated)
                    <p class="text-muted-brand mb-0">{{ __('Escalated requests are decided by a super admin.') }}</p>
                @else
                    <p class="text-muted-brand mb-0">{{ __('You can view this request but not decide it.') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>
