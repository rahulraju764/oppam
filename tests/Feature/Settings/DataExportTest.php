<?php

declare(strict_types=1);

use App\Actions\Profile\RequestDataExport;
use App\Enums\DataExportStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Jobs\Profile\ExpireDataExports;
use App\Jobs\Profile\GenerateDataExportJob;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\Like;
use App\Models\User;
use App\Notifications\Account\DataExportReady;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2c — /settings → Hide / delete: "download my data (JSON + photos zip, queued, 24 h)" (PRD M14,
| DPDP). Built in the background onto the PRIVATE disk; downloaded by the signed-in owner only, by
| code; no token anywhere (not in the queued job, the notification or the email); deleted after 24 h.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    fakeMediaDisks();
    Storage::fake(GenerateDataExportJob::disk());
    Notification::fake();
});

function dxZipEntries(DataExport $export): array
{
    $local = tempnam(sys_get_temp_dir(), 'dx-');
    file_put_contents($local, Storage::disk(GenerateDataExportJob::disk())->get((string) $export->file_path));
    $zip = new ZipArchive;
    $zip->open($local);
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($local);

    return $entries;
}

it('R-M14 export: a zip on the private disk with data.json + photos, READY for 24 h; the member is told (no token)', function (): void {
    $bride = bride();
    $groom = groom();
    addTestPhoto($bride);
    Favorite::query()->forceCreate(['profile_id' => $bride->profile->id, 'favorite_profile_id' => $groom->profile->id, 'note' => 'Met his family']);
    Like::query()->forceCreate(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);
    Interest::factory()->accepted()->create(['from_profile_id' => $bride->profile->id, 'to_profile_id' => $groom->profile->id]);

    $export = app(RequestDataExport::class)->handle($bride)->refresh();

    expect($export->status)->toBe(DataExportStatus::Ready)
        ->and($export->code)->toMatch('/^DEX[A-Z0-9]{10}$/')
        ->and($export->file_path)->toBe('exports/'.$export->code.'.zip')
        ->and($export->expires_at?->diffInHours($export->completed_at, true))->toEqualWithDelta(24, 0.1);

    $entries = dxZipEntries($export);
    $json = $entries['data.json'];
    $data = json_decode($json, true);
    expect(array_keys($entries))->toContain('photos/photo-1.jpg')
        ->and($data['account']['mobile'])->toBe($bride->phone)
        ->and($data['profile']['code'])->toBe($bride->profile->code)
        ->and($data['profile']['favourites'][0])->toMatchArray(['profile' => $groom->profile->code, 'note' => 'Met his family'])
        ->and($data['profile']['likes_received'][0]['profile'])->toBe($groom->profile->code)
        ->and($data['profile']['interests_sent'][0]['profile'])->toBe($groom->profile->code)
        // no internal ids, no secrets
        ->and($json)->not->toContain((string) $bride->id)->not->toContain((string) $bride->profile->id)
        ->not->toContain((string) $groom->profile->id)->not->toContain('"password"')->not->toContain($groom->phone);

    Notification::assertSentTo($bride, DataExportReady::class, function (DataExportReady $n) use ($bride, $export): bool {
        $payload = serialize($n).json_encode($n->content($bride));

        return ! str_contains($payload, (string) $export->file_path) && str_contains(json_encode($n->content($bride)), 'tab=manage');
    });
});

it('the queued job carries only the export id — no token, no path', function (): void {
    Queue::fake();
    $bride = bride();

    $export = app(RequestDataExport::class)->handle($bride);

    Queue::assertPushedOn('media', GenerateDataExportJob::class, fn (GenerateDataExportJob $job): bool => $job->exportId === $export->id
        && ! str_contains(serialize($job), $export->code));
    expect(AuditLog::query()->where('action', 'account.data_export_requested')->count())->toBe(1);
});

it('download: the owner gets the zip (no-store, audited); another member, a guest or a made-up code get nothing', function (): void {
    $bride = bride();
    $export = app(RequestDataExport::class)->handle($bride)->refresh();
    $url = memberUrl('/settings/data-export/'.$export->code);

    $this->get($url)->assertRedirect(memberUrl('/login'));
    $this->actingAs(groom(), 'web')->get($url)->assertNotFound();
    $this->actingAs($bride, 'web')->get(memberUrl('/settings/data-export/DEXAAAAAAAAAA'))->assertNotFound();

    $response = $this->actingAs($bride, 'web')->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($export->refresh()->downloaded_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'account.data_export_downloaded')->count())->toBe(1);
});

it('an expired copy → 410; the hourly job deletes the file and marks it EXPIRED', function (): void {
    $bride = bride();
    $export = app(RequestDataExport::class)->handle($bride)->refresh();
    $path = (string) $export->file_path;

    $this->travel(25)->hours();
    $this->actingAs($bride, 'web')->get(memberUrl('/settings/data-export/'.$export->code))->assertStatus(410);

    (new ExpireDataExports)->handle();

    expect($export->refresh()->status)->toBe(DataExportStatus::Expired)->and($export->file_path)->toBeNull();
    Storage::disk(GenerateDataExportJob::disk())->assertMissing($path);
});

it('once a day; one still being prepared blocks a second; a failed one may be asked again at once', function (): void {
    $bride = bride();
    Queue::fake();
    app(RequestDataExport::class)->handle($bride);
    expect(fn () => app(RequestDataExport::class)->handle($bride))->toThrow(SettingsRefused::class);

    DataExport::query()->update(['status' => DataExportStatus::Failed->value]);
    app(RequestDataExport::class)->handle($bride);
    DataExport::query()->where('status', DataExportStatus::Queued->value)->update(['status' => DataExportStatus::Ready->value]);

    expect(fn () => app(RequestDataExport::class)->handle($bride))->toThrow(SettingsRefused::class);
    $this->travel(25)->hours();
    app(RequestDataExport::class)->handle($bride);
    expect(DataExport::query()->where('user_id', $bride->id)->count())->toBe(3);
});

it('a job for an export that is no longer QUEUED, or a member who is gone, builds nothing', function (): void {
    Queue::fake();
    $bride = bride();
    $export = app(RequestDataExport::class)->handle($bride);

    $bride->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();
    (new GenerateDataExportJob($export->id))->handle(app(App\Domain\Account\MemberDataExport::class));

    expect($export->refresh()->status)->toBe(DataExportStatus::Failed)->and($export->file_path)->toBeNull();
    (new GenerateDataExportJob($export->id))->handle(app(App\Domain\Account\MemberDataExport::class));   // re-run: no-op
    expect(Storage::disk(GenerateDataExportJob::disk())->allFiles('exports'))->toBe([]);
});

it('a lost job never locks the member out: failed() marks it FAILED; a QUEUED row older than an hour stops blocking', function (): void {
    Queue::fake();
    $bride = bride();
    $first = app(RequestDataExport::class)->handle($bride);

    (new GenerateDataExportJob($first->id))->failed(new RuntimeException('worker killed'));
    expect($first->refresh()->status)->toBe(DataExportStatus::Failed);

    $second = app(RequestDataExport::class)->handle($bride);   // a failed one may be asked again at once
    expect(fn () => app(RequestDataExport::class)->handle($bride))->toThrow(SettingsRefused::class);

    $this->travel(61)->minutes();
    (new ExpireDataExports)->handle();
    expect($second->refresh()->status)->toBe(DataExportStatus::Failed);

    // …and even without the hourly job, a stale QUEUED row doesn't block a new request.
    $third = app(RequestDataExport::class)->handle($bride);
    $this->travel(61)->minutes();
    app(RequestDataExport::class)->handle($bride);
    expect($third->refresh()->status)->toBe(DataExportStatus::Failed);
});

it('rule 5 / §7.3: "who liked me" in the export follows the plan — Free gets a count only, Gold gets the codes', function (): void {
    $bride = bride();
    $groom = groom();
    Like::query()->forceCreate(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);

    $free = app(App\Domain\Account\MemberDataExport::class)->build($bride);
    expect($free['profile']['likes_received'])->toMatchArray(['count' => 1])
        ->and(json_encode($free))->not->toContain($groom->profile->code);

    chatOnPlan($bride, App\Enums\PlanCode::Gold);
    app(App\Services\Entitlements\EntitlementService::class)->forget($bride->profile);
    $gold = app(App\Domain\Account\MemberDataExport::class)->build($bride->refresh());
    expect($gold['profile']['likes_received'][0]['profile'])->toBe($groom->profile->code);
});

it('refused in an admin support session; downloads too; a suspended member gets a 404', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');
    $export = app(RequestDataExport::class)->handle($bride)->refresh();

    chatImpersonate($bride);
    expect(fn () => app(RequestDataExport::class)->handle($bride))->toThrow(ImpersonationRestricted::class);
    expect($this->get(memberUrl('/settings/data-export/'.$export->code))->getStatusCode())->not->toBe(200)
        ->and($export->refresh()->downloaded_at)->toBeNull();

    $other = User::factory()->create(['status' => App\Enums\UserStatus::Suspended]);
    expect(fn () => app(RequestDataExport::class)->handle($other))->toThrow(NotFoundHttpException::class);
});
