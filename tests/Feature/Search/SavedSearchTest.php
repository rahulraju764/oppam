<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Search\DeleteSavedSearch;
use App\Actions\Search\SaveSearch;
use App\Actions\Search\UpdateSavedSearch;
use App\Enums\AlertFrequency;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_save_a_search_with_criteria_and_alert_frequency(): void
    {
        $profile = Profile::factory()->create();
        $action = app(SaveSearch::class);

        $saved = $action->handle(
            profile: $profile,
            name: 'Kochi Techies',
            filters: ['district' => [1], 'age_min' => 24, 'age_max' => 28],
            frequency: AlertFrequency::Daily,
        );

        $this->assertDatabaseHas('saved_searches', [
            'id' => $saved->id,
            'profile_id' => $profile->id,
            'name' => 'Kochi Techies',
            'alert_frequency' => 'DAILY',
        ]);
        $this->assertSame(24, $saved->filters['age_min']);
    }

    public function test_saved_search_enforces_quota_limit_of_ten_per_profile(): void
    {
        $profile = Profile::factory()->create();
        $action = app(SaveSearch::class);

        SavedSearch::factory()->count(10)->create(['profile_id' => $profile->id]);

        $this->expectException(SavedSearchLimitReached::class);

        $action->handle(
            profile: $profile,
            name: '11th Search',
            filters: ['age_min' => 25],
        );
    }

    public function test_saved_search_validates_name_length(): void
    {
        $profile = Profile::factory()->create();
        $action = app(SaveSearch::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            profile: $profile,
            name: str_repeat('a', 61),
            filters: [],
        );
    }

    public function test_saved_search_can_be_updated(): void
    {
        $profile = Profile::factory()->create();
        $saved = SavedSearch::factory()->create(['profile_id' => $profile->id, 'name' => 'Old Name']);
        $action = app(UpdateSavedSearch::class);

        $updated = $action->handle(
            profile: $profile,
            savedSearch: $saved,
            name: 'New Name',
            frequency: AlertFrequency::Weekly,
        );

        $this->assertSame('New Name', $updated->name);
        $this->assertSame(AlertFrequency::Weekly, $updated->alert_frequency);
    }

    public function test_saved_search_cannot_be_updated_by_another_member(): void
    {
        $owner = Profile::factory()->create();
        $attacker = Profile::factory()->create();
        $saved = SavedSearch::factory()->create(['profile_id' => $owner->id]);
        $action = app(UpdateSavedSearch::class);

        $this->expectException(ModelNotFoundException::class);

        $action->handle(
            profile: $attacker,
            savedSearch: $saved,
            name: 'Hacked Name',
        );
    }

    public function test_saved_search_can_be_deleted(): void
    {
        $profile = Profile::factory()->create();
        $saved = SavedSearch::factory()->create(['profile_id' => $profile->id]);
        $action = app(DeleteSavedSearch::class);

        $action->handle($profile, $saved);

        $this->assertDatabaseMissing('saved_searches', ['id' => $saved->id]);
    }

    public function test_saved_search_cannot_be_deleted_by_another_member(): void
    {
        $owner = Profile::factory()->create();
        $attacker = Profile::factory()->create();
        $saved = SavedSearch::factory()->create(['profile_id' => $owner->id]);
        $action = app(DeleteSavedSearch::class);

        $this->expectException(ModelNotFoundException::class);

        $action->handle($attacker, $saved);
    }

    public function test_saved_search_unsubscribe_via_signed_url_turns_alerts_off(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $saved = SavedSearch::factory()->create([
            'profile_id' => $profile->id,
            'alert_frequency' => AlertFrequency::Daily,
        ]);

        $url = URL::signedRoute('saved-searches.unsubscribe', ['savedSearch' => $saved->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee(__('Alerts turned off'));

        $this->assertDatabaseHas('saved_searches', [
            'id' => $saved->id,
            'alert_frequency' => 'OFF',
        ]);
    }
}
