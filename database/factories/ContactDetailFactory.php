<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactDetail;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactDetail> */
final class ContactDetailFactory extends Factory
{
    protected $model = ContactDetail::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'contact_person' => $this->faker->name(),
            'contact_relation' => 'Parent',
            'convenient_time' => 'Evenings after 6pm',
            'city' => $this->faker->city(),
        ];
    }
}
