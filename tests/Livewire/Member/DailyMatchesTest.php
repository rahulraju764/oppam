<?php

declare(strict_types=1);

namespace Tests\Livewire\Member;

use App\Enums\UserRole;
use App\Livewire\Member\Matches\Daily;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DailyMatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlansSeeder::class);
    }

    public function test_guest_is_redirected_from_daily_matches(): void
    {
        $this->get(route('member.matches.daily'))
            ->assertRedirect(route('login'));

        $this->get(route('member.daily-matches'))
            ->assertRedirect(route('login'));
    }

    public function test_onboarded_member_can_view_daily_matches(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
            'first_name' => 'Kavya',
        ]);

        $candidate = Profile::factory()->active()->female()->create([
            'first_name' => 'Meera',
        ]);

        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        DailyMatch::factory()->create([
            'profile_id' => $profile->id,
            'matched_profile_id' => $candidate->id,
            'match_date' => $today,
            'score' => 88,
            'is_viewed' => false,
            'is_interacted' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(Daily::class)
            ->assertOk()
            ->assertSee('Daily Matches')
            ->assertSee('88% Match')
            ->assertSee('Meera')
            ->assertSee('Batch expires in:');

        // Check that match was marked as viewed
        $match = DailyMatch::query()->where('profile_id', $profile->id)->where('matched_profile_id', $candidate->id)->first();
        expect($match?->is_viewed)->toBeTrue();
    }

    public function test_member_can_dismiss_a_daily_match(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $candidate = Profile::factory()->active()->female()->create([
            'first_name' => 'Divya',
        ]);

        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        $dailyMatch = DailyMatch::factory()->create([
            'profile_id' => $profile->id,
            'matched_profile_id' => $candidate->id,
            'match_date' => $today,
            'is_interacted' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(Daily::class)
            ->call('dismissMatch', $dailyMatch->id)
            ->assertDontSee('Divya');

        expect($dailyMatch->fresh()?->is_interacted)->toBeTrue();
    }

    public function test_empty_state_shown_when_no_daily_matches_available(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(Daily::class)
            ->assertOk()
            ->assertSee('No daily matches available today')
            ->assertSee('Edit Partner Preferences');
    }

    public function test_daily_matches_renders_without_n_plus_one_with_prevent_lazy_loading(): void
    {
        Model::preventLazyLoading(true);

        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        $candidates = Profile::factory()->count(3)->active()->female()->create();
        foreach ($candidates as $cand) {
            DailyMatch::factory()->create([
                'profile_id' => $profile->id,
                'matched_profile_id' => $cand->id,
                'match_date' => $today,
            ]);
        }

        $this->actingAs($user);

        Livewire::test(Daily::class)
            ->assertOk();

        Model::preventLazyLoading(false);
    }
}
