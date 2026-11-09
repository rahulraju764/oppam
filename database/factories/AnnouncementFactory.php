<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Announcement> */
final class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'ANN'.Str::upper(Str::random(8)),
            'title' => 'Onam offer',
            'body' => '20 % off Gold this week.',
            'audience' => AnnouncementAudience::All,
            'is_dismissible' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
