<?php

declare(strict_types=1);

namespace Tests\Livewire\Member;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Member\Dashboard\Dashboard;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlansSeeder::class);
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get(route('member.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_onboarded_member_can_view_dashboard(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
            'first_name' => 'Rahul',
            'completeness' => 85,
        ]);

        Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'first_name' => 'Priya',
        ]);

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Daily Recommendations')
            ->assertSee('New Matches')
            ->assertSee('Rahul')
            ->assertSee('85%');
    }

    public function test_dashboard_renders_with_prevent_lazy_loading_enabled(): void
    {
        Model::preventLazyLoading();

        $user = User::factory()->create(['role' => UserRole::Member]);
        Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);

        Profile::factory()->female()->count(5)->create([
            'status' => ProfileStatus::Active,
        ]);

        $this->actingAs($user);

        Livewire::test(Dashboard::class)->assertOk();

        Model::preventLazyLoading(false);
    }
}
