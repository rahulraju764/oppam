<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Database\LazyLoadingGuard;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Log;

/*
| P9.3c — the N+1 guard: outside production a lazy load throws (tests and local fail fast); in production it
| is only LOGGED (model + relation, no ids or data) so an N+1 the tests missed shows up in the logs.
*/

afterEach(function (): void {
    LazyLoadingGuard::configure(production: false);   // back to the strict test default for the next test
});

it('N+1 guard: outside production a lazy load throws', function (): void {
    LazyLoadingGuard::configure(production: false);
    memberWithPhone();
    memberWithPhone('+919800000002');

    // Laravel flags a lazy load only on a model that came in a result of several rows (the N+1 shape).
    $member = User::query()->get()->first();   // loaded without its profile

    expect(fn () => $member->profile)->toThrow(LazyLoadingViolationException::class);
});

it('N+1 guard: in production a lazy load is logged (model + relation only) and the page still works', function (): void {
    LazyLoadingGuard::configure(production: true);
    Log::spy();
    memberWithPhone();
    memberWithPhone('+919800000002');

    $member = User::query()->get()->first();

    expect($member->profile)->not->toBeNull();   // loaded, not refused

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Lazy loading')
        && $context === ['model' => User::class, 'relation' => 'profile']);
});

it('N+1 guard: an eager-loaded relation is never reported', function (): void {
    LazyLoadingGuard::configure(production: true);
    Log::spy();
    memberWithPhone();
    memberWithPhone('+919800000002');

    expect(User::query()->with('profile')->get()->first()->profile)->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('N+1 guard: an N+1 over several rows logs ONE line per model + relation, not one per row (review P9.3c)', function (): void {
    LazyLoadingGuard::configure(production: true);
    Log::spy();
    foreach (range(1, 4) as $i) {
        memberWithPhone('+91980000010'.$i);
    }

    User::query()->get()->each(fn (User $member) => $member->profile);   // the classic N+1: four lazy loads

    Log::shouldHaveReceived('warning')->once();
});
