<?php

declare(strict_types=1);

use App\Actions\Search\DeleteSavedSearch;
use App\Actions\Search\SaveSearch;
use App\Actions\Search\UnsubscribeSavedSearch;
use App\Actions\Search\UpdateSavedSearch;
use App\Data\Search\SearchCriteria;
use App\Enums\AlertFrequency;
use App\Enums\ProfileStatus;
use App\Enums\SearchSort;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Exceptions\Search\SearchThrottled;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P2.2 — saved searches (M04): only normalised filters are stored, max 10 per profile, owner-only
| rename / alert / delete (anyone else's → 404), and the token unsubscribe link (GET confirms,
| POST — also RFC 8058 one-click — turns the alerts off).
*/

beforeEach(function (): void {
    seedMasters();
});

function saveFor(User $member, array $filters = ['age_min' => '24'], string $name = 'Kochi brides'): SavedSearch
{
    return app(SaveSearch::class)->handle($member, $name, SearchCriteria::fromInput($filters), AlertFrequency::Weekly);
}

it('M04: stores only the normalised filters, never raw input', function (): void {
    $saved = saveFor(groom(), [
        'age_min' => '30', 'age_max' => '24',          // swapped → ordered
        'caste' => array_map('strval', range(1, 50)),  // capped at 30
        'district' => ['3', 'x', '-1'],                // junk ids dropped
        'sort' => 'newest',
        'evil' => '<script>', 'nested' => ['a' => ['b' => 'c']],
        'photo' => 'on', 'premium' => '0',
    ]);

    expect($saved->filters)->toBe([
        'age_min' => 24, 'age_max' => 30, 'caste' => range(1, 30), 'district' => [3], 'photo' => '1', 'sort' => 'newest',
    ])
        ->and($saved->alert_frequency)->toBe(AlertFrequency::Weekly)
        ->and($saved->alert_token)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($saved->criteria()->sort)->toBe(SearchSort::Newest);
});

it('M04: SearchCriteria::toInput round-trips through fromInput', function (): void {
    $criteria = SearchCriteria::fromInput(['age_min' => '25', 'marital' => ['NEVER_MARRIED'], 'verified' => '1', 'active' => '7d', 'sub_caste' => ' Nair ']);

    expect(SearchCriteria::fromInput($criteria->toInput()))->toEqual($criteria)
        ->and((new SearchCriteria)->toInput())->toBe([]);
});

it('M04: at most 10 saved searches per profile', function (): void {
    $member = groom();
    SavedSearch::factory()->count(SavedSearch::MAX_PER_PROFILE)->create(['profile_id' => $member->profile->id]);

    expect(fn () => saveFor($member))->toThrow(SavedSearchLimitReached::class);
    expect(SavedSearch::query()->count())->toBe(SavedSearch::MAX_PER_PROFILE);
});

it('validates the name: trimmed, 1–60 characters', function (string $name): void {
    expect(fn () => saveFor(groom(), name: $name))->toThrow(ValidationException::class);
})->with(['empty' => '', 'spaces' => '   ', 'too long' => str_repeat('a', 61)]);

it('saving is rate-limited per member', function (): void {
    $member = groom();
    foreach (range(1, SaveSearch::MAX_PER_MINUTE) as $i) {
        try {
            saveFor($member, name: 'Search '.$i);
        } catch (SavedSearchLimitReached) {
            // the 10-per-profile limit is reached first; the attempts still count
        }
    }

    expect(fn () => saveFor($member))->toThrow(SearchThrottled::class);
});

it('a member who may not browse (suspended profile) cannot save', function (): void {
    $member = groom();
    $member->profile->forceFill(['status' => ProfileStatus::Suspended])->save();

    expect(fn () => saveFor($member->refresh()))->toThrow(HttpException::class);
});

it('the owner renames, changes the alert frequency and deletes', function (): void {
    $member = groom();
    $saved = saveFor($member);

    app(UpdateSavedSearch::class)->handle($member, $saved->id, name: '  Thrissur  doctors ', frequency: AlertFrequency::Off);
    expect($saved->refresh()->name)->toBe('Thrissur doctors')->and($saved->alert_frequency)->toBe(AlertFrequency::Off);

    app(DeleteSavedSearch::class)->handle($member, $saved->id);
    expect(SavedSearch::query()->count())->toBe(0);
});

it('IDOR: another member\'s saved search, or a made-up id, is a 404', function (string $action): void {
    $saved = saveFor(groom());
    $other = bride();

    $call = fn (string $id) => $action === 'update'
        ? app(UpdateSavedSearch::class)->handle($other, $id, name: 'Mine now')
        : app(DeleteSavedSearch::class)->handle($other, $id);

    expect(fn () => $call($saved->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $call('01JUNKJUNKJUNKJUNKJUNKJUNK'))->toThrow(ModelNotFoundException::class);
    expect($saved->refresh()->name)->toBe('Kochi brides');
})->with(['update', 'delete']);

it('a member suspended meanwhile can no longer rename or delete (403)', function (): void {
    $member = groom();
    $saved = saveFor($member);
    $member->profile->forceFill(['status' => ProfileStatus::Suspended])->save();
    $member->refresh();

    expect(fn () => app(UpdateSavedSearch::class)->handle($member, $saved->id, name: 'x'))->toThrow(HttpException::class)
        ->and(fn () => app(DeleteSavedSearch::class)->handle($member, $saved->id))->toThrow(HttpException::class);
    expect($saved->refresh()->exists)->toBeTrue();
});

it('rename validates the name', function (): void {
    $member = groom();
    $saved = saveFor($member);

    expect(fn () => app(UpdateSavedSearch::class)->handle($member, $saved->id, name: ''))->toThrow(ValidationException::class);
});

it('M04 unsubscribe: GET only shows the confirmation; POST turns the alerts off', function (): void {
    $saved = saveFor(groom());
    $url = memberUrl('/saved-searches/unsubscribe/'.$saved->alert_token);

    $this->get($url)->assertOk()->assertSee('Turn off alerts?')->assertSee('Kochi brides');
    expect($saved->refresh()->alert_frequency)->toBe(AlertFrequency::Weekly);

    $this->post($url)->assertOk()->assertSee('Alerts turned off');   // no session, no CSRF token: one-click
    expect($saved->refresh()->alert_frequency)->toBe(AlertFrequency::Off);

    $this->post($url)->assertOk();   // idempotent
});

it('unsubscribe: an unknown or malformed token is a 404 and changes nothing', function (): void {
    $saved = saveFor(groom());

    $this->get(memberUrl('/saved-searches/unsubscribe/'.str_repeat('a', 48)))->assertNotFound();
    $this->post(memberUrl('/saved-searches/unsubscribe/'.str_repeat('a', 48)))->assertNotFound();
    $this->get(memberUrl('/saved-searches/unsubscribe/'.$saved->id))->assertNotFound();   // the ULID is not a credential

    expect(app(UnsubscribeSavedSearch::class)->find('short'))->toBeNull()
        ->and($saved->refresh()->alert_frequency)->toBe(AlertFrequency::Weekly);
});

it('the old /all-profiles URL redirects to /profiles', function (): void {
    $this->get(memberUrl('/all-profiles'))->assertRedirect('/profiles')->assertStatus(301);
});
