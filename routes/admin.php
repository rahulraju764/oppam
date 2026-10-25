<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BreakGlassImageController;
use App\Http\Controllers\Admin\BrokerKycDocumentController;
use App\Http\Controllers\Admin\ChatFlagImageController;
use App\Http\Controllers\Admin\EvidencePackController;
use App\Http\Controllers\Admin\MasterExportController;
use App\Http\Controllers\Admin\MemberExportController;
use App\Http\Controllers\Admin\VerificationDocumentController;
use App\Livewire\Admin\Auth\AcceptInvitation;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Auth\TwoFactorChallenge;
use App\Livewire\Admin\Auth\TwoFactorSetup;
use App\Livewire\Admin\Brokers\KycQueue as BrokerKycQueue;
use App\Livewire\Admin\Brokers\UnverifiedCodesQueue as BrokerUnverifiedCodesQueue;
use App\Livewire\Admin\Chat\BreakGlass;
use App\Livewire\Admin\Chat\BreakGlassViewer;
use App\Livewire\Admin\Chat\FlaggedQueue;
use App\Livewire\Admin\Chat\FlagReview;
use App\Livewire\Admin\Chat\Overview as ChatOverview;
use App\Livewire\Admin\Chat\Rules as ChatRules;
use App\Livewire\Admin\Chat\Signals as ChatSignals;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Masters\Index as MastersIndex;
use App\Livewire\Admin\Masters\ListEditor as MastersListEditor;
use App\Livewire\Admin\Members\Index as MembersIndex;
use App\Livewire\Admin\Members\Show as MembersShow;
use App\Livewire\Admin\Moderation\EditedFieldsQueue;
use App\Livewire\Admin\Moderation\Escalations;
use App\Livewire\Admin\Moderation\PhotoQueueGrid;
use App\Livewire\Admin\Moderation\ProfileQueue;
use App\Livewire\Admin\Moderation\ProfileReview;
use App\Livewire\Admin\Roles\Editor as RolesEditor;
use App\Livewire\Admin\Safety\CaseQueue;
use App\Livewire\Admin\Safety\CaseReview;
use App\Livewire\Admin\Sessions\Index as SessionsIndex;
use App\Livewire\Admin\Staff\Index as StaffIndex;
use App\Livewire\Admin\Support\CannedResponses;
use App\Livewire\Admin\Support\TicketQueue;
use App\Livewire\Admin\Support\TicketShow;
use App\Livewire\Admin\Verification\Queue as VerificationQueue;
use App\Livewire\Admin\Verification\Review as VerificationReview;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel — admin.oppam.in (config('oppam.admin_domain'))
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php inside: middleware(['web', 'ip.allowlist']), domain(admin),
| name('admin.'). Every page is a Livewire full-page component under App\Livewire\Admin; every
| data-changing action authorizes again inside its Action (a hidden link is not security).
*/

// Sign-in flow (A01). Password → (first time) 2FA enrolment | 2FA challenge → panel.
Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/two-factor', TwoFactorChallenge::class)->name('two-factor.challenge');
    Route::get('/two-factor/setup', TwoFactorSetup::class)->name('two-factor.setup');
    Route::get('/invitations/{token}', AcceptInvitation::class)->where('token', '[A-Za-z0-9]{64}')->name('invitations.accept');
});

// The panel: signed in, live registered session, active account, 2FA passed (PRD §11.0).
Route::middleware(['auth:admin', 'admin.session', 'admin.active', '2fa.confirmed'])->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    // WebSocket channel sign-in for the panel (admin guard; e.g. admin.queues), PRD §9.3.
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->name('broadcasting.auth');
    Route::get('/staff', StaffIndex::class)->middleware('can:staff.view')->name('staff.index');
    Route::get('/roles', RolesEditor::class)->middleware('can:roles.view')->name('roles.edit');
    Route::get('/sessions', SessionsIndex::class)->name('sessions.index');

    // Members (A03). Viewing needs members.view; every action re-checks its own permission.
    Route::middleware('can:members.view')->prefix('members')->name('members.')->group(function (): void {
        Route::get('/', MembersIndex::class)->name('index');
        // CSV download: a short-lived signed link built by the list (ExportMembers checks members.export).
        Route::get('/export', MemberExportController::class)->middleware('signed')->name('export');
        Route::get('/{profile}', MembersShow::class)->where('profile', 'OPM[0-9]+')->name('show');
    });

    // Master data (A11). Viewing needs masters.view; every change re-checks masters.edit.
    Route::middleware('can:masters.view')->prefix('masters')->name('masters.')->group(function (): void {
        Route::get('/', MastersIndex::class)->name('index');
        Route::get('/{list}', MastersListEditor::class)->where('list', '[a-z0-9-]+')->name('edit');
        Route::get('/{list}/export', MasterExportController::class)->where('list', '[a-z0-9-]+')->name('export');
    });

    // Moderation (A04). Viewing needs moderation.view; every decision re-checks moderation.act.
    Route::middleware('can:moderation.view')->prefix('moderation')->name('moderation.')->group(function (): void {
        Route::get('/profiles', ProfileQueue::class)->name('profiles');
        Route::get('/profiles/{profile}', ProfileReview::class)->where('profile', 'OPM[0-9]+')->name('profiles.review');
        Route::get('/photos', PhotoQueueGrid::class)->name('photos');
        Route::get('/edits', EditedFieldsQueue::class)->name('edits');
        Route::get('/escalations', Escalations::class)->name('escalations');
    });

    // ID verification (A05). The queue and review need verification.queue.view; decisions re-check
    // verification.approve in their Actions. Documents: a 10-minute signed link bound to the session
    // that opened the viewer (verification.document.view), audited on every fetch — never a file URL.
    Route::middleware('can:verification.queue.view')->prefix('verification')->name('verification.')->group(function (): void {
        Route::get('/', VerificationQueue::class)->name('index');
        Route::get('/{profile}', VerificationReview::class)->where('profile', 'OPM[0-9]+')->name('review');
        Route::get('/{profile}/document/{side}', VerificationDocumentController::class)
            ->where(['profile' => 'OPM[0-9]+', 'side' => 'front|back'])
            ->middleware(['signed', 'can:verification.document.view', 'throttle:60,1'])->name('document');
    });

    // Bureaus (A07, §11A B.12). P7.1: the applications & KYC queue; the PAN image only through a
    // 10-minute signed link bound to the session that opened it, audited on every fetch.
    Route::middleware('can:brokers.kyc')->prefix('brokers')->name('brokers.')->group(function (): void {
        Route::get('/kyc', BrokerKycQueue::class)->name('kyc');
        Route::get('/kyc/{broker}/pan', BrokerKycDocumentController::class)->where('broker', 'BRK[0-9]+')
            ->middleware(['signed', 'throttle:60,1'])->name('kyc.pan');
    });

    // P7.2: mistyped / unknown referral codes — link or clear, audited (R-M13-3).
    Route::get('/brokers/unverified-codes', BrokerUnverifiedCodesQueue::class)->middleware('can:brokers.edit')->name('brokers.unverified');

    // Safety cases (A10, P6.2). Viewing needs safety.reports.view; every decision re-checks its own
    // permission. The evidence pack: a 5-minute signed link, super admin + legal hold (ExportEvidencePack).
    Route::middleware('can:safety.reports.view')->prefix('safety')->name('safety.')->group(function (): void {
        Route::get('/cases', CaseQueue::class)->name('cases');
        Route::get('/cases/{case}', CaseReview::class)->where('case', 'CASE[0-9]+')->name('cases.show');
        Route::get('/cases/{case}/evidence-pack', EvidencePackController::class)->where('case', 'CASE[0-9]+')
            ->middleware(['signed', 'throttle:10,1'])->name('cases.evidence-pack');
    });

    // Support desk (A10, P6.2). Viewing needs support.view; replies and changes re-check support.respond.
    Route::middleware('can:support.view')->prefix('support')->name('support.')->group(function (): void {
        Route::get('/tickets', TicketQueue::class)->name('tickets');
        Route::get('/tickets/{ticket}', TicketShow::class)->where('ticket', 'TKT[0-9]+')->name('tickets.show');
        Route::get('/canned', CannedResponses::class)->middleware('can:support.respond')->name('canned');
    });

    // Chat safety (A13). Viewing needs chat.view_flagged; every decision re-checks its own permission.
    Route::middleware('can:chat.view_flagged')->prefix('chat')->name('chat.')->group(function (): void {
        Route::get('/flags', FlaggedQueue::class)->name('flags');
        Route::get('/flags/{flag}', FlagReview::class)->where('flag', '[0-9A-Za-z]{26}')->name('flags.review');
        // The flagged photo only, after this admin's audited reveal (FlagReview).
        Route::get('/flags/{flag}/image', ChatFlagImageController::class)->where('flag', '[0-9A-Za-z]{26}')->name('flags.image');
        Route::get('/signals', ChatSignals::class)->name('signals');
        Route::get('/overview', ChatOverview::class)->name('overview');
        Route::get('/rules', ChatRules::class)->name('rules');
    });

    // Break-glass reads (A13): super admins by default; each page read is audited inside its Action.
    Route::middleware('can:chat.read_conversation')->prefix('chat/break-glass')->name('chat.')->group(function (): void {
        Route::get('/', BreakGlass::class)->name('break-glass');
        Route::get('/{grant}', BreakGlassViewer::class)->where('grant', '[0-9A-Za-z]{26}')->name('break-glass.read');
        Route::get('/{grant}/media/{message}', BreakGlassImageController::class)
            ->where(['grant' => '[0-9A-Za-z]{26}', 'message' => '[0-9A-Za-z]{26}'])->name('break-glass.image');
    });
});

Route::fallback(fn () => abort(404))->name('fallback');
