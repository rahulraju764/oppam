<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\AlertFrequency;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Jobs\Search\SendSavedSearchAlerts;
use App\Models\Ignore;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\Member\SavedSearchMatchesNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class SendSavedSearchAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_alerts_only_for_profiles_published_after_last_alerted_at(): void
    {
        Notification::fake();

        $searcherUser = User::factory()->create(['email' => 'searcher@example.com', 'role' => UserRole::Member]);
        $searcher = Profile::factory()->male()->create(['user_id' => $searcherUser->id, 'status' => ProfileStatus::Active]);

        $checkpoint = CarbonImmutable::parse('2026-10-01 10:00:00');

        $savedSearch = SavedSearch::factory()->create([
            'profile_id' => $searcher->id,
            'name' => 'Bride Alert',
            'filters' => ['age_min' => 22, 'age_max' => 28],
            'alert_frequency' => AlertFrequency::Daily,
            'last_alerted_at' => $checkpoint,
        ]);

        // Old candidate (published before checkpoint) - should NOT count
        Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'published_at' => $checkpoint->subDay(),
            'dob' => CarbonImmutable::now()->subYears(25),
        ]);

        // New candidate (published after checkpoint) - SHOULD count
        Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'published_at' => $checkpoint->addHour(),
            'dob' => CarbonImmutable::now()->subYears(24),
        ]);

        app(SendSavedSearchAlerts::class)->handle(app(\App\Services\Profile\ProfileSearch::class));

        Notification::assertSentTo(
            $searcherUser,
            SavedSearchMatchesNotification::class,
            function (SavedSearchMatchesNotification $notification): bool {
                return $notification->newMatchesCount === 1;
            }
        );

        $savedSearch->refresh();
        $this->assertTrue($savedSearch->last_alerted_at->isAfter($checkpoint));
    }

    public function test_it_does_not_alert_when_frequency_is_off(): void
    {
        Notification::fake();

        $searcherUser = User::factory()->create(['email' => 'searcher@example.com', 'role' => UserRole::Member]);
        $searcher = Profile::factory()->male()->create(['user_id' => $searcherUser->id, 'status' => ProfileStatus::Active]);

        SavedSearch::factory()->create([
            'profile_id' => $searcher->id,
            'alert_frequency' => AlertFrequency::Off,
            'last_alerted_at' => null,
        ]);

        Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'published_at' => now(),
        ]);

        app(SendSavedSearchAlerts::class)->handle(app(\App\Services\Profile\ProfileSearch::class));

        Notification::assertNothingSent();
    }

    public function test_it_does_not_alert_when_there_are_zero_new_matches(): void
    {
        Notification::fake();

        $searcherUser = User::factory()->create(['email' => 'searcher@example.com', 'role' => UserRole::Member]);
        $searcher = Profile::factory()->male()->create(['user_id' => $searcherUser->id, 'status' => ProfileStatus::Active]);

        SavedSearch::factory()->create([
            'profile_id' => $searcher->id,
            'alert_frequency' => AlertFrequency::Daily,
            'last_alerted_at' => now()->subDay(),
        ]);

        app(SendSavedSearchAlerts::class)->handle(app(\App\Services\Profile\ProfileSearch::class));

        Notification::assertNothingSent();
    }

    public function test_it_excludes_ignored_profiles_from_the_alert_count(): void
    {
        Notification::fake();

        $searcherUser = User::factory()->create(['email' => 'searcher@example.com', 'role' => UserRole::Member]);
        $searcher = Profile::factory()->male()->create(['user_id' => $searcherUser->id, 'status' => ProfileStatus::Active]);

        $checkpoint = CarbonImmutable::parse('2026-10-01 10:00:00');

        SavedSearch::factory()->create([
            'profile_id' => $searcher->id,
            'filters' => [],
            'alert_frequency' => AlertFrequency::Daily,
            'last_alerted_at' => $checkpoint,
        ]);

        $ignoredCandidate = Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'published_at' => $checkpoint->addHour(),
        ]);

        Ignore::factory()->create([
            'ignorer_profile_id' => $searcher->id,
            'ignored_profile_id' => $ignoredCandidate->id,
        ]);

        app(SendSavedSearchAlerts::class)->handle(app(\App\Services\Profile\ProfileSearch::class));

        Notification::assertNothingSent();
    }
}
