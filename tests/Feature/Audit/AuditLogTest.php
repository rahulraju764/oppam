<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Exceptions\Audit\AuditLogIsImmutable;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| P0.5 — the append-only audit trail (PRD A12, CLAUDE.md security rule 8).
*/

beforeEach(function (): void {
    seedAdminRoles();
});

it('records the signed-in admin as the actor, with subject, reason and request data', function (): void {
    $admin = adminWithRole();
    $this->actingAs($admin, 'admin');
    $profile = Profile::factory()->create();

    $log = app(AuditLogger::class)->record('profiles.suspended', $profile, ['status' => 'ACTIVE'], ['status' => 'SUSPENDED'], 'Fake photos', subjectLabel: $profile->code);

    expect($log->actor_type)->toBe(AuditActorType::Admin)
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->actor_label)->toBe($admin->email)
        ->and($log->subject_type)->toBe('Profile')
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->subject_label)->toBe($profile->code)
        ->and($log->reason)->toBe('Fake photos')
        ->and($log->after)->toBe(['status' => 'SUSPENDED']);
});

it('records system actions when no one is signed in', function (): void {
    expect(app(AuditLogger::class)->record('jobs.daily_matches')->actor_type)->toBe(AuditActorType::System);
});

it('never stores secrets and masks phone numbers', function (): void {
    $log = app(AuditLogger::class)->record('test', after: [
        'password' => 'hunter2',
        'two_factor_secret' => 'ABC',
        'phone' => '+919847012345',
        'nested' => ['token' => 'xyz', 'name' => 'Divya'],
    ]);

    expect($log->after)->toBe([
        'password' => '[redacted]',
        'two_factor_secret' => '[redacted]',
        'phone' => '••••2345',
        'nested' => ['token' => '[redacted]', 'name' => 'Divya'],
    ]);
});

it('refuses to update or delete an entry through the model', function (string $operation): void {
    $log = app(AuditLogger::class)->record('test');

    $operation === 'update' ? $log->forceFill(['action' => 'changed'])->save() : $log->delete();
})->with(['update', 'delete'])->throws(AuditLogIsImmutable::class);

it('refuses to update or delete an entry at the database level too', function (string $operation): void {
    $log = app(AuditLogger::class)->record('test');
    $query = DB::table('audit_logs')->where('id', $log->id);

    $operation === 'update' ? $query->update(['action' => 'changed']) : $query->delete();
})->with(['update', 'delete'])
    ->throws(QueryException::class)
    ->skip(fn (): bool => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Triggers need MySQL/MariaDB');
