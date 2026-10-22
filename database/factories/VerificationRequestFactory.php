<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VerificationDocType;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Models\Profile;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerificationRequest> */
final class VerificationRequestFactory extends Factory
{
    protected $model = VerificationRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'doc_type' => VerificationDocType::Aadhaar,
            'number_last4' => (string) fake()->numberBetween(1000, 9999),
            'number_hash' => hash('sha256', fake()->unique()->uuid()),
            'name_on_document' => fake()->name(),
            'status' => VerificationStatus::Pending,
            'flags' => null,
            'is_priority' => false,
            'submitted_at' => now(),
            'consented_at' => now(),
        ];
    }

    public function approved(): self
    {
        return $this->state(['status' => VerificationStatus::Approved, 'decided_at' => now()]);
    }

    public function rejected(VerificationRejectReason $reason = VerificationRejectReason::Unreadable): self
    {
        return $this->state(['status' => VerificationStatus::Rejected, 'decided_at' => now(), 'reject_reason' => $reason]);
    }

    public function escalated(string $note = 'Please take a second look at the photo.'): self
    {
        return $this->state(['status' => VerificationStatus::Escalated, 'escalation_note' => $note]);
    }
}
