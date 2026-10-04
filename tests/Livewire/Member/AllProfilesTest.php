<?php

declare(strict_types=1);

namespace Tests\Livewire\Member;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Member\Browse\AllProfiles;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AllProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_all_profiles(): void
    {
        $this->get(route('member.all-profiles'))
            ->assertRedirect(route('login'));
    }

    public function test_onboarded_member_can_view_all_profiles(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);

        $candidate = Profile::factory()->female()->create([
            'status' => ProfileStatus::Active,
            'first_name' => 'Anjali',
        ]);

        $this->actingAs($user);

        Livewire::test(AllProfiles::class)
            ->assertOk()
            ->assertSee('All Profiles')
            ->assertSee('Anjali');
    }

    public function test_member_can_save_a_search_from_modal(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);

        $this->actingAs($user);

        Livewire::test(AllProfiles::class)
            ->call('openSaveModal')
            ->assertSet('showSaveModal', true)
            ->set('saveName', 'My Favorite Kerala Brides')
            ->set('saveFrequency', 'DAILY')
            ->call('saveSearch')
            ->assertSet('showSaveModal', false)
            ->assertSee(__('Search saved successfully.'));

        $this->assertDatabaseHas('saved_searches', [
            'profile_id' => $profile->id,
            'name' => 'My Favorite Kerala Brides',
            'alert_frequency' => 'DAILY',
        ]);
    }

    public function test_member_can_delete_a_saved_search(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $profile = Profile::factory()->male()->create([
            'user_id' => $user->id,
            'status' => ProfileStatus::Active,
        ]);
        $saved = SavedSearch::factory()->create(['profile_id' => $profile->id]);

        $this->actingAs($user);

        Livewire::test(AllProfiles::class)
            ->call('deleteSavedSearch', $saved->id);

        $this->assertDatabaseMissing('saved_searches', ['id' => $saved->id]);
    }
}
