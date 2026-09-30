<?php

declare(strict_types=1);

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use App\Services\Profile\ProfileCodeGenerator;
use App\Services\Sequences\SequenceAllocator;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| P0.4 — core profile schema: public codes, ULID keys, constraints (PRD §7.2).
*/

it('issues sequential OPM profile codes starting at OPM10001', function (): void {
    $generator = app(ProfileCodeGenerator::class);

    expect([$generator->next(), $generator->next(), $generator->next()])->toBe(['OPM10001', 'OPM10002', 'OPM10003']);
});

it('never reuses a profile code, even after the profile is deleted', function (): void {
    $first = Profile::factory()->create();
    $first->forceDelete();

    $second = Profile::factory()->create();

    expect($second->code)->not->toBe($first->code)
        ->and($second->code)->toBe('OPM10002');
});

it('keeps separate counters per sequence name', function (): void {
    $sequences = app(SequenceAllocator::class);

    expect($sequences->next('a', 1))->toBe(1)
        ->and($sequences->next('b', 500))->toBe(500)
        ->and($sequences->next('a', 1))->toBe(2);
});

it('never takes a code, status or verification flag from input (mass assignment)', function (string $field, mixed $value): void {
    // Strict mode turns a silently discarded attribute into an exception outside production.
    (new Profile)->fill([$field => $value]);
})->with([
    ['code', 'OPM99999'],
    ['status', ProfileStatus::Active->value],
    ['is_verified', true],
    ['is_premium', true],
    ['user_id', '01J00000000000000000000000'],
])->throws(MassAssignmentException::class);

it('never takes a role or status from input on a user', function (string $field): void {
    (new User)->fill([$field => 'BROKER']);
})->with(['role', 'status'])->throws(MassAssignmentException::class);

it('uses ULID primary keys and resolves profiles by public code in URLs', function (): void {
    $profile = Profile::factory()->create();

    expect($profile->id)->toMatch('/^[0-9a-z]{26}$/i')
        ->and($profile->user_id)->toMatch('/^[0-9a-z]{26}$/i')
        ->and($profile->getRouteKeyName())->toBe('code')
        ->and($profile->getRouteKey())->toBe($profile->code);
});

it('allows one account per mobile number (R-M01-1)', function (): void {
    User::factory()->create(['phone' => '+919000012345']);

    User::factory()->create(['phone' => '+919000012345']);
})->throws(QueryException::class);

it('allows one profile per user', function (): void {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create();

    Profile::factory()->for($user)->create();
})->throws(QueryException::class);

it('refuses a status the enum does not know, at the database level', function (): void {
    $profile = Profile::factory()->create();

    DB::table('profiles')->where('id', $profile->id)->update(['status' => 'APPROVED']);
})->throws(QueryException::class)->skip(fn (): bool => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'CHECK constraints need MySQL/MariaDB');

it('keeps a factory-made profile caste within its religion', function (): void {
    $profile = Profile::factory()->create();

    expect($profile->caste->religion_id)->toBe($profile->religion_id);
});

it('only lists ACTIVE profiles as searchable (R-M02-2)', function (): void {
    Profile::factory()->active()->create();
    Profile::factory()->pendingReview()->create();
    Profile::factory()->draft()->create();
    Profile::factory()->suspended()->create();

    expect(Profile::query()->searchable()->count())->toBe(1);
});

it('computes a profile age from the IST date', function (): void {
    $profile = Profile::factory()->withAge(27)->create();

    expect($profile->age())->toBe(27);
});
