<?php

declare(strict_types=1);

use App\Enums\AlertFrequency;
use App\Enums\ProfileStatus;
use App\Jobs\Search\SendSavedSearchAlerts;
use App\Models\Block;
use App\Models\Ignore;
use App\Models\NotificationPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\Member\SavedSearchMatchesNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

/*
| P2.2 — saved-search alerts (M04): the job counts profiles published since the last alert with the
| owner's search rules (blocked / ignored / incognito never count), honours DAILY / WEEKLY / OFF,
| skips owners who may not browse or turned the email off, and the email carries a count and links.
*/

beforeEach(function (): void {
    seedMasters();
    Notification::fake();
    $this->travelTo(now()->startOfMinute());
});

function alertOwner(): User
{
    return groom('+919811100001');
}

function savedSearchOf(User $owner, array $state = []): SavedSearch
{
    return SavedSearch::factory()->create([
        'profile_id' => $owner->profile->id,
        'name' => 'Brides',
        'filters' => [],
        'alert_frequency' => AlertFrequency::Daily,
        'last_alerted_at' => now()->subDay(),
        ...$state,
    ]);
}

function newBride(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create(['published_at' => now()->subHour(), ...$state]);
}

function runAlerts(): void
{
    app()->call([new SendSavedSearchAlerts, 'handle']);
}

it('M04: counts only profiles published since the last alert, then moves the window on', function (): void {
    $owner = alertOwner();
    $saved = savedSearchOf($owner);
    newBride(['published_at' => now()->subDays(2)]);   // before the last alert
    newBride();
    newBride();

    runAlerts();

    Notification::assertSentTo($owner, SavedSearchMatchesNotification::class, fn (SavedSearchMatchesNotification $n): bool => $n->newMatchesCount === 2);
    expect($saved->refresh()->last_alerted_at->equalTo(now()))->toBeTrue();

    Notification::fake();
    $this->travel(21)->hours();
    runAlerts();   // nothing new since
    Notification::assertNothingSent();
});

it('M04: the saved filters apply', function (): void {
    $owner = alertOwner();
    savedSearchOf($owner, ['filters' => ['height_min' => 170]]);
    newBride(['height_cm' => 175]);
    newBride(['height_cm' => 150]);

    runAlerts();

    Notification::assertSentTo($owner, SavedSearchMatchesNotification::class, fn (SavedSearchMatchesNotification $n): bool => $n->newMatchesCount === 1);
});

it('blocked (either way), ignored and incognito profiles never count', function (): void {
    $owner = alertOwner();
    savedSearchOf($owner);
    $blocked = newBride();
    $blocker = newBride();
    $ignored = newBride();
    $incognito = newBride();
    newBride();
    Block::factory()->create(['blocker_profile_id' => $owner->profile->id, 'blocked_profile_id' => $blocked->id]);
    Block::factory()->create(['blocker_profile_id' => $blocker->id, 'blocked_profile_id' => $owner->profile->id]);
    Ignore::factory()->create(['ignorer_profile_id' => $owner->profile->id, 'ignored_profile_id' => $ignored->id]);
    (PrivacySetting::query()->whereKey($incognito->id)->first() ?? new PrivacySetting)
        ->forceFill(['profile_id' => $incognito->id, 'incognito' => true])->save();

    runAlerts();

    Notification::assertSentTo($owner, SavedSearchMatchesNotification::class, fn (SavedSearchMatchesNotification $n): bool => $n->newMatchesCount === 1);
});

it('DAILY waits 20 hours and WEEKLY 6 days between alerts; OFF never alerts', function (): void {
    $owner = alertOwner();
    savedSearchOf($owner, ['last_alerted_at' => now()->subHours(19)]);
    savedSearchOf($owner, ['alert_frequency' => AlertFrequency::Weekly, 'last_alerted_at' => now()->subDays(5)]);
    savedSearchOf($owner, ['alert_frequency' => AlertFrequency::Off]);
    newBride(['published_at' => now()->subMinutes(10)]);

    runAlerts();
    Notification::assertNothingSent();

    $this->travel(2)->days();
    runAlerts();
    Notification::assertSentToTimes($owner, SavedSearchMatchesNotification::class, 2);
});

it('nothing for an owner who may not browse (suspended profile or account)', function (string $case): void {
    $owner = alertOwner();
    $saved = savedSearchOf($owner);
    newBride();
    $case === 'profile'
        ? $owner->profile->forceFill(['status' => ProfileStatus::Suspended])->save()
        : $owner->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();

    runAlerts();

    Notification::assertNothingSent();
    expect($saved->refresh()->last_alerted_at->lt(now()))->toBeTrue();
})->with(['profile', 'account']);

it('M08: no email when the owner turned saved_search_alert emails off; the window still moves', function (): void {
    $owner = alertOwner();
    $saved = savedSearchOf($owner);
    $pref = new NotificationPreference(['event' => SavedSearchMatchesNotification::EVENT, 'email' => false]);
    $pref->user_id = $owner->id;
    $pref->save();
    newBride();

    runAlerts();

    Notification::assertNothingSent();
    expect($saved->refresh()->last_alerted_at->equalTo(now()))->toBeTrue();
});

it('the email: count, the search link with normalised filters, the token unsubscribe link and one-click headers', function (): void {
    $owner = alertOwner();
    $saved = savedSearchOf($owner, ['filters' => ['height_min' => 170, 'junk' => 'x']]);

    $mail = (new SavedSearchMatchesNotification($saved, 3))->toMail($owner);
    $unsubscribe = route('saved-searches.unsubscribe', ['token' => $saved->alert_token]);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toContain('3 new profiles')
        ->and($mail->actionUrl)->toBe(route('member.search', ['f' => ['height_min' => 170]]))
        ->and(implode(' ', $mail->outroLines))->toContain($unsubscribe)
        ->and(implode(' ', $mail->outroLines))->not->toContain($saved->id);

    $email = new Symfony\Component\Mime\Email;
    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }
    expect($email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toBe('<'.$unsubscribe.'>')
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
});
