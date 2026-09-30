<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Data\Auth\RegistrationData;
use App\Enums\CreatedFor;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\Support\FakeSmsGateway;

/*
| Member auth test helpers (P1.1). Loaded from tests/Pest.php.
*/

const TEST_MEMBER_PASSWORD = 'kerala2026';

/** Swap the SMS gateway for an in-memory fake and return it. */
function fakeSms(): FakeSmsGateway
{
    $fake = new FakeSmsGateway;
    app()->instance(SmsGateway::class, $fake);

    return $fake;
}

function registrationData(array $overrides = []): RegistrationData
{
    return new RegistrationData(
        createdFor: $overrides['createdFor'] ?? CreatedFor::Self,
        firstName: $overrides['firstName'] ?? 'Anjali',
        lastName: array_key_exists('lastName', $overrides) ? $overrides['lastName'] : 'Thomas',
        gender: $overrides['gender'] ?? Gender::Female,
        dob: array_key_exists('dob', $overrides) ? $overrides['dob'] : CarbonImmutable::parse('1998-05-12'),
        phone: $overrides['phone'] ?? PhoneNumber::fromParts('91', '9876543210'),
        email: array_key_exists('email', $overrides) ? $overrides['email'] : 'anjali@example.com',
        password: $overrides['password'] ?? TEST_MEMBER_PASSWORD,
    );
}

/** A phone-verified, ACTIVE-profile member with a known password. */
function memberWithPhone(string $e164 = '+919876543210', ProfileStatus $status = ProfileStatus::Active): User
{
    $user = User::factory()->create(['phone' => $e164, 'password' => TEST_MEMBER_PASSWORD]);
    $factory = Profile::factory()->for($user);
    $factory = match ($status) {
        ProfileStatus::Draft => $factory->draft(),
        ProfileStatus::PendingReview => $factory->pendingReview(),
        default => $factory->active(),
    };
    $factory->create();

    return $user->refresh();
}

/** A request with a started session, for calling session-touching Actions directly. */
function requestWithSession(string $ip = '10.1.1.1'): Request
{
    $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);

    return $request;
}

function memberUrl(string $path = '/'): string
{
    return 'http://'.config('oppam.app_domain').$path;
}
