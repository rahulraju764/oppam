<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommsChannel;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationTemplate> */
final class CommunicationTemplateFactory extends Factory
{
    protected $model = CommunicationTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'profile_approved.email',
            'event' => 'profile_approved',
            'channel' => CommsChannel::Email,
            'name' => 'Profile approved — email',
            'is_active' => true,
            'current_version' => 1,
        ];
    }

    /** A template for this event / channel with one live version. */
    public function forEvent(string $event, CommsChannel $channel, string $body, ?string $subject = null, ?string $dltId = null): static
    {
        return $this->state([
            'key' => CommunicationTemplate::keyFor($event, $channel),
            'event' => $event,
            'channel' => $channel,
            'name' => $event.' — '.$channel->value,
        ])->afterCreating(function (CommunicationTemplate $template) use ($body, $subject, $dltId): void {
            (new CommunicationTemplateVersion)->forceFill([
                'template_id' => $template->id, 'version' => 1, 'subject' => $subject, 'body' => $body, 'dlt_template_id' => $dltId,
            ])->save();
        });
    }
}
