<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profile;
use App\Models\ProfileView;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfileView> */
final class ProfileViewFactory extends Factory
{
    protected $model = ProfileView::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'viewer_profile_id' => Profile::factory(),
            'viewed_profile_id' => Profile::factory(),
            'view_date' => now((string) config('oppam.display_timezone'))->toDateString(),
            'count' => 1,
        ];
    }
}
