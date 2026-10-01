<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactView;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactView> */
final class ContactViewFactory extends Factory
{
    protected $model = ContactView::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'viewer_profile_id' => Profile::factory(),
            'viewed_profile_id' => Profile::factory(),
        ];
    }
}
