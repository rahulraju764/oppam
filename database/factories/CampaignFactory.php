<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Models\AdminUser;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Campaign> */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'CMP'.Str::upper(Str::random(8)),
            'name' => 'Onam offer '.Str::random(4),
            'channel' => CommsChannel::Email,
            'segment_filters' => [],
            'subject' => 'Onam offer',
            'message' => "Hello {first_name},\n\n20 % off Gold this week.",
            'status' => CampaignStatus::Draft,
            'throttle_per_minute' => 300,
            'recipient_count' => 0,
            'created_by' => AdminUser::factory(),
        ];
    }

    public function channel(CommsChannel $channel): self
    {
        return $this->state(fn (): array => [
            'channel' => $channel,
            'dlt_template_id' => $channel === CommsChannel::Sms ? '1107000000000000001' : null,
        ]);
    }

    public function status(CampaignStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
