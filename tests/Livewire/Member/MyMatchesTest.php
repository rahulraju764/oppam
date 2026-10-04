<?php

declare(strict_types=1);

namespace Tests\Livewire\Member;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Member\Matches\MyMatches;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class MyMatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlansSeeder::class);
    }

    public function test_guest_is_redirected_from_my_matches(): void
    {
        $this->get(route('member.my-matches'))
            ->assertRedirect(route('login'));
    }

    public function test_onboarded_member_can_view_my_matches(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);

        Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'first_name' => 'Meera',
        ]);

        $this->actingAs($user);

        Livewire::test(MyMatches::class)
            ->assertOk()
            ->assertSee('My Matches')
            ->assertSee('All Matches')
            ->assertSee('Yet to be Viewed')
            ->assertSee('Meera');
    }

    public function test_tab_switching_filters_profiles(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);

        $viewedCandidate = Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'first_name' => 'ViewedGirl',
        ]);

        $unviewedCandidate = Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'first_name' => 'UnviewedGirl',
        ]);

        ProfileView::factory()->create([
            'viewer_profile_id' => $profile->id,
            'viewed_profile_id' => $viewedCandidate->id,
        ]);

        $this->actingAs($user);

        Livewire::test(MyMatches::class)
            ->assertSee('ViewedGirl')
            ->assertSee('UnviewedGirl')
            ->set('tab', 'viewed')
            ->assertSee('ViewedGirl')
            ->assertDontSee('UnviewedGirl');
    }
}
