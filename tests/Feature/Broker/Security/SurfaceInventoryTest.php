<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Component;

/*
| P7.9 — broker security sweep, coverage guard (PRD §11A B.22 "every role × every route/action").
| Every route named broker.* / admin.brokers.* and every action of every bureau and A07 Livewire
| component must be listed here with the test files that attack it (wrong role, other bureau,
| tampered id / #[Locked] prop, revoked mid-flow). A new route or action without a row fails —
| so the sweep can't silently fall behind the code. The report: docs/security/broker-sweep-p7.9.md.
*/

const SWEEP_MATRIX = 'Feature/Broker/BureauPermissionMatrixTest';
const SWEEP_ROUTES = 'Feature/Broker/Security/RouteAttackTest';
const SWEEP_MIDFLOW = 'Feature/Broker/Security/MidFlowRevocationTest';

/** route name => test files that attack it */
const SWEEP_ROUTE_COVERAGE = [
    'broker.apply' => ['Feature/Broker/BrokerApplicationTest', 'Feature/Broker/AdminBureauTest', SWEEP_ROUTES],
    'broker.validate' => ['Feature/Broker/ReferralAttributionTest', SWEEP_ROUTES],
    'broker.staff.accept' => ['Feature/Broker/StaffLifecycleTest', 'Livewire/Broker/StaffScreensTest', SWEEP_ROUTES],
    'broker.device' => ['Feature/Broker/BrokerDeviceTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.dashboard' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.kyc' => ['Feature/Broker/BrokerKycTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.activity' => ['Feature/Broker/BureauActivityTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.earnings' => ['Livewire/Broker/EarningsTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.earnings.statement' => ['Livewire/Broker/EarningsTest', SWEEP_ROUTES],
    'broker.referrals' => ['Livewire/Broker/ReferralScreensTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.materials' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.materials.poster' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.materials.qr.png' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.materials.qr.svg' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.profiles.index' => ['Feature/Broker/IdorScopingTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.profiles.create' => [SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.profiles.show' => ['Feature/Broker/IdorScopingTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.profiles.edit' => ['Feature/Broker/IdorScopingTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.profiles.inbox' => ['Feature/Broker/IdorScopingTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.profiles.notes' => ['Feature/Broker/IdorScopingTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.profiles.export.biodata' => ['Feature/Broker/ExportAuthorizationTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.profiles.export.photos' => ['Feature/Broker/ExportAuthorizationTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.import' => ['Feature/Broker/ImportUploadTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.import.batches' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.import.batch' => [SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.import.errors' => [SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.import.template.csv' => ['Feature/Broker/ImportTemplateTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.import.template.xlsx' => ['Feature/Broker/ImportTemplateTest', SWEEP_MATRIX, SWEEP_ROUTES],
    'broker.staff.index' => ['Feature/Broker/StaffLifecycleTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'broker.staff.show' => ['Feature/Broker/StaffLifecycleTest', SWEEP_MATRIX, SWEEP_ROUTES, SWEEP_MIDFLOW],
    'admin.brokers.index' => ['Livewire/Admin/BrokerAdminScreensTest', 'Livewire/Admin/BrokerSignalsScreensTest'],
    'admin.brokers.show' => ['Livewire/Admin/BrokerAdminScreensTest', 'Livewire/Admin/BrokerOversightScreensTest'],
    'admin.brokers.kyc' => ['Feature/Broker/BrokerKycTest', 'Livewire/Broker/BrokerScreensTest'],
    'admin.brokers.kyc.pan' => ['Feature/Broker/BrokerKycTest'],
    'admin.brokers.unverified' => ['Livewire/Admin/UnverifiedCodesQueueTest'],
    'admin.brokers.payouts' => ['Feature/Broker/PayoutRunTest', 'Livewire/Admin/BrokerAdminScreensTest'],
    'admin.brokers.payouts.show' => ['Feature/Broker/PayoutRunTest', 'Livewire/Admin/BrokerAdminScreensTest'],
    'admin.brokers.payouts.statement' => ['Livewire/Admin/BrokerAdminScreensTest'],
    'admin.brokers.disputes' => ['Feature/Broker/BrokerOversightTest', 'Livewire/Admin/BrokerOversightScreensTest'],
    'admin.brokers.exports' => ['Feature/Broker/BrokerOversightTest', 'Livewire/Admin/BrokerOversightScreensTest'],
    'admin.brokers.imports' => ['Feature/Broker/BrokerOversightTest', 'Livewire/Admin/BrokerOversightScreensTest'],
    'admin.brokers.imports.source' => ['Feature/Broker/BrokerOversightTest'],
];

/** Livewire component => its actions => test files attacking them (framework pagination / upload hooks excluded). */
/** @return array<string, array<string, list<string>>> */
function sweepActionCoverage(): array
{
    return [
        'Broker\Apply' => ['apply' => ['Feature/Broker/BrokerApplicationTest', 'Feature/Broker/AdminBureauTest']],
        'Broker\Auth\VerifyDevice' => ['send' => ['Feature/Broker/BrokerDeviceTest'], 'verify' => ['Feature/Broker/BrokerDeviceTest']],
        'Broker\ComingSoon' => [],
        'Broker\Dashboard' => [],
        'Broker\Activity' => [],
        'Broker\Earnings' => [],
        'Broker\Materials' => [],
        'Broker\Referrals' => ['updatedStage' => []],   // updatedX hooks mapped to [] only reset a filter / page: no write
        'Broker\Kyc' => ['submit' => ['Feature/Broker/BrokerKycTest']],
        'Broker\Import\Upload' => ['startImport' => ['Feature/Broker/ImportUploadTest', SWEEP_MIDFLOW]],
        'Broker\Import\Batches' => [],
        'Broker\Import\BatchReview' => [
            'updatedFilter' => [],
            'progressed' => ['Livewire/Broker/ImportScreensTest'], 'completed' => ['Livewire/Broker/ImportScreensP77Test'],
            'saveMapping' => [SWEEP_MATRIX], 'fixCell' => [SWEEP_MATRIX, 'Feature/Broker/ImportFixTest'], 'uploadFixes' => [SWEEP_MATRIX],
            'attest' => [SWEEP_MATRIX], 'import' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'submitAll' => [SWEEP_MATRIX], 'cancel' => [SWEEP_MATRIX],
        ],
        'Broker\ManagedProfiles\ClientInbox' => ['accept' => ['Feature/Broker/IdorScopingTest', 'Feature/Broker/ManagedProfiles/ClientInboxTest'],
            'decline' => ['Feature/Broker/IdorScopingTest', 'Feature/Broker/ManagedProfiles/ClientInboxTest'], 'updatedTab' => [],
            'send' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'claimed' => ['Feature/Broker/ClaimFlowTest']],
        'Broker\ManagedProfiles\Form' => ['open' => ['Livewire/Broker/ManagedProfileScreensTest'], 'save' => [SWEEP_MATRIX, SWEEP_MIDFLOW],
            'autosave' => ['Livewire/Broker/ManagedProfileScreensTest'], 'saveClientPhone' => [SWEEP_MATRIX, 'Feature/Broker/IdorScopingTest'],
            'submit' => [SWEEP_MATRIX, 'Feature/Broker/IdorScopingTest'], 'photosChanged' => [], 'toggleChoice' => [], 'clearChoice' => []],
        'Broker\ManagedProfiles\Index' => ['assignSelected' => [SWEEP_MATRIX, SWEEP_MIDFLOW]],
        'Broker\ManagedProfiles\Notes' => ['add' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'complete' => [SWEEP_MATRIX, SWEEP_MIDFLOW]],
        'Broker\ManagedProfiles\Photos' => ['savePhoto' => [SWEEP_MIDFLOW], 'deletePhoto' => [SWEEP_MIDFLOW], 'updatedHoroscope' => [SWEEP_MIDFLOW],
            'movePhoto' => [SWEEP_MIDFLOW], 'updateCaption' => [SWEEP_MIDFLOW], 'deleteHoroscope' => [SWEEP_MIDFLOW]],
        'Broker\ManagedProfiles\Show' => ['claimed' => ['Feature/Broker/ClaimFlowTest'], 'change' => [SWEEP_MATRIX, SWEEP_MIDFLOW],
            'submit' => [SWEEP_MATRIX], 'assign' => [SWEEP_MATRIX]],
        'Broker\Staff\AcceptInvite' => ['start' => ['Feature/Broker/StaffLifecycleTest'], 'verify' => ['Feature/Broker/StaffLifecycleTest'], 'back' => ['Livewire/Broker/StaffScreensTest']],
        'Broker\Staff\Index' => ['invite' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'cancel' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'updatedRole' => []],
        'Broker\Staff\Show' => ['save' => [SWEEP_MATRIX, 'Feature/Broker/StaffLifecycleTest'], 'deactivate' => [SWEEP_MATRIX, SWEEP_MIDFLOW], 'updatedMonth' => []],
        'Admin\Brokers\BrokerShow' => array_fill_keys(['revokeKyc', 'pauseCreation', 'resumeCreation'], ['Livewire/Admin/BrokerOversightScreensTest', 'Feature/Broker/BrokerOversightTest'])
            + array_fill_keys(['saveTerms', 'suspend', 'deactivate', 'reactivate', 'holdPayouts', 'releasePayouts'], ['Livewire/Admin/BrokerAdminScreensTest', 'Feature/Broker/BrokerDeactivationTest', 'Feature/Broker/AdminBureauTest'])
        + ['updatedTab' => ['Livewire/Admin/BrokerOversightScreensTest']],
        'Admin\Brokers\BrokersIndex' => ['clearFilters' => [], 'create' => ['Livewire/Admin/BrokerAdminScreensTest', 'Feature/Broker/AdminBureauTest']],
        'Admin\Brokers\Disputes' => array_fill_keys(['record', 'decide', 'uphold', 'dismiss'], ['Livewire/Admin/BrokerOversightScreensTest', 'Feature/Broker/BrokerOversightTest']),
        'Admin\Brokers\ExportsAudit' => ['clearFilters' => []],
        'Admin\Brokers\ImportsMonitor' => array_fill_keys(['confirm', 'pause', 'resume', 'cancelBatch'], ['Livewire/Admin/BrokerOversightScreensTest', 'Feature/Broker/BrokerOversightTest']),
        'Admin\Brokers\KycQueue' => array_fill_keys(['show', 'select', 'viewPan', 'closePan', 'approve', 'rejectKyc', 'rejectApplication'], ['Feature/Broker/BrokerKycTest', 'Livewire/Broker/BrokerScreensTest']),
        'Admin\Brokers\PayoutRunShow' => array_fill_keys(['openLine', 'approve', 'markPaid', 'rollForward', 'discard'], ['Livewire/Admin/BrokerAdminScreensTest', 'Feature/Broker/PayoutRunTest']),
        'Admin\Brokers\PayoutsIndex' => ['create' => ['Livewire/Admin/BrokerAdminScreensTest', 'Feature/Broker/PayoutRunTest']],
        'Admin\Brokers\SignalsPanel' => array_fill_keys(['confirm', 'resolve', 'voidAll'], ['Livewire/Admin/BrokerSignalsScreensTest', 'Feature/Broker/FraudSignalsTest']),
        'Admin\Brokers\Tabs\ActivityTab' => [], 'Admin\Brokers\Tabs\EarningsTab' => ['updatedStatus' => []], 'Admin\Brokers\Tabs\PayoutsTab' => [],
        'Admin\Brokers\Tabs\ProfilesTab' => ['updatedStatus' => []], 'Admin\Brokers\Tabs\ReferralsTab' => ['updatedStage' => []],
        'Admin\Brokers\Tabs\StaffTab' => array_fill_keys(['confirm', 'deactivate', 'resetDevices'], ['Livewire/Admin/BrokerOversightScreensTest', 'Feature/Broker/BrokerOversightTest']),
        'Admin\Brokers\UnverifiedCodesQueue' => array_fill_keys(['select', 'close', 'link', 'clear'], ['Livewire/Admin/UnverifiedCodesQueueTest']),
    ];
}

/** Livewire's own trait methods (pagination, uploads) — not application actions. */
const SWEEP_FRAMEWORK_METHODS = ['queryStringHandlesPagination', 'getPage', 'previousPage', 'nextPage', 'gotoPage', 'resetPage', 'setPage',
    '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload'];

it('★ B.22: every broker / A07 route is in the sweep, each with existing test files attacking it', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter(
        fn (?string $n): bool => $n !== null && (str_starts_with($n, 'broker.') || str_starts_with($n, 'admin.brokers.')))->sort()->values()->all();

    expect($routes)->toEqualCanonicalizing(array_keys(SWEEP_ROUTE_COVERAGE));
    foreach (SWEEP_ROUTE_COVERAGE as $name => $files) {
        expect($files)->not->toBeEmpty("{$name} has no attack test");
        foreach ($files as $file) {
            expect(base_path('tests/'.$file.'.php'))->toBeFile();
        }
    }
});

it('★ B.22: every action of every bureau / A07 Livewire component is in the sweep, with existing test files', function (): void {
    $found = [];
    foreach (['app/Livewire/Broker' => 'App\\Livewire\\Broker\\', 'app/Livewire/Admin/Brokers' => 'App\\Livewire\\Admin\\Brokers\\'] as $dir => $ns) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir))) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Concerns')) {
                continue;
            }
            $class = $ns.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', substr($file->getPathname(), strlen(base_path($dir)) + 1)));
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
                continue;
            }
            $actions = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $m): bool => ! $m->isStatic() && str_starts_with($m->getDeclaringClass()->getName(), 'App\\')
                    && $m->getAttributes(Livewire\Attributes\Computed::class) === [])   // computed values aren't callable actions
                ->map(fn (ReflectionMethod $m): string => $m->getName())
                ->reject(fn (string $n): bool => in_array($n, ['mount', 'render', 'boot', 'booted', 'hydrate', 'dehydrate', 'placeholder', 'getListeners', 'updated', 'updating', 'rendering', 'rendered', 'exception'], true)
                    || str_starts_with($n, '__') || in_array($n, SWEEP_FRAMEWORK_METHODS, true))   // updatedX hooks stay listed: one may write
                ->values()->all();
            $found[substr($class, strlen('App\\Livewire\\'))] = $actions;
        }
    }

    expect(array_keys($found))->toEqualCanonicalizing(array_keys(sweepActionCoverage()));
    foreach ($found as $component => $actions) {
        expect($actions)->toEqualCanonicalizing(array_keys(sweepActionCoverage()[$component]), "{$component}: actions changed — add their attack rows");
        foreach (sweepActionCoverage()[$component] as $files) {
            foreach ($files as $file) {
                expect(base_path('tests/'.$file.'.php'))->toBeFile();
            }
        }
    }
});
