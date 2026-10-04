<?php

declare(strict_types=1);

namespace Tests\Livewire\Member;

use App\Enums\PlanCode;
use App\Enums\UserRole;
use App\Livewire\Member\Activity\Visitors;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VisitorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlansSeeder::class);
    }

    public function test_guest_is_redirected_from_visitors(): void
    {
        $this->get(route('member.visitors'))
            ->assertRedirect(route('login'));
    }

    public function test_free_member_sees_visitor_count_and_upgrade_teaser(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $viewer = Profile::factory()->active()->female()->create([
            'first_name' => 'Aparna',
        ]);

        ProfileView::factory()->create([
            'viewer_profile_id' => $viewer->id,
            'viewed_profile_id' => $profile->id,
            'view_date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
            'count' => 2,
        ]);

        $this->actingAs($user);

        Livewire::test(Visitors::class)
            ->assertOk()
            ->assertSee('Who Viewed Me')
            ->assertSee('1 Members Viewed Your Profile')
            ->assertSee('Upgrade to Gold to See Visitors')
            ->assertDontSee('Aparna');
    }

    public function test_gold_member_can_see_full_viewer_cards_with_timestamps(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        // Grant Gold subscription
        $goldPlan = Plan::query()->where('code', PlanCode::Gold->value)->sole();
        Subscription::factory()->create([
            'profile_id' => $profile->id,
            'plan_id' => $goldPlan->id,
        ]);

        $viewer = Profile::factory()->active()->female()->create([
            'first_name' => 'Deepika',
        ]);

        ProfileView::factory()->create([
            'viewer_profile_id' => $viewer->id,
            'viewed_profile_id' => $profile->id,
            'view_date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
            'count' => 3,
        ]);

        $this->actingAs($user);

        Livewire::test(Visitors::class)
            ->assertOk()
            ->assertSee('Deepika')
            ->assertSee('Viewed')
            ->assertSee('3 times')
            ->assertDontSee('Upgrade to Gold to See Visitors');
    }

    public function test_all_members_can_view_profiles_they_viewed_within_90_days(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $recentlyViewed = Profile::factory()->active()->female()->create([
            'first_name' => 'Anjali',
        ]);

        ProfileView::factory()->create([
            'viewer_profile_id' => $profile->id,
            'viewed_profile_id' => $recentlyViewed->id,
            'view_date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
            'updated_at' => now()->subDays(5),
        ]);

        $this->actingAs($user);

        Livewire::test(Visitors::class)
            ->set('tab', 'viewed_by_me')
            ->assertOk()
            ->assertSee('Anjali')
            ->assertSee('You visited');
    }

    public function test_visitors_renders_without_n_plus_one_with_prevent_lazy_loading(): void
    {
        Model::preventLazyLoading(true);

        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->active()->male()->create([
            'user_id' => $user->id,
        ]);

        $goldPlan = Plan::query()->where('code', PlanCode::Gold->value)->sole();
        Subscription::factory()->create([
            'profile_id' => $profile->id,
            'plan_id' => $goldPlan->id,
        ]);

        $viewers = Profile::factory()->count(3)->active()->female()->create();
        foreach ($viewers as $viewer) {
            ProfileView::factory()->create([
                'viewer_profile_id' => $viewer->id,
                'viewed_profile_id' => $profile->id,
                'view_date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
            ]);
        }

        $this->actingAs($user);

        Livewire::test(Visitors::class)
            ->assertOk();

        Livewire::test(Visitors::class)
            ->set('tab', 'viewed_by_me')
            ->assertOk();

        Model::preventLazyLoading(false);
    }
}
