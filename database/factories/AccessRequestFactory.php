<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Models\AccessRequest;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccessRequest> */
final class AccessRequestFactory extends Factory
{
    protected $model = AccessRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => AccessRequestType::Photo,
            'requester_profile_id' => Profile::factory(),
            'owner_profile_id' => Profile::factory(),
            'status' => AccessRequestStatus::Pending,
        ];
    }

    public function between(Profile $requester, Profile $owner, AccessRequestType $type = AccessRequestType::Photo): self
    {
        return $this->state(['requester_profile_id' => $requester->id, 'owner_profile_id' => $owner->id, 'type' => $type]);
    }

    public function approved(): self
    {
        return $this->state(['status' => AccessRequestStatus::Approved, 'responded_at' => now()]);
    }
}
