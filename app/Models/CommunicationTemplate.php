<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommsChannel;
use Carbon\CarbonImmutable;
use Database\Factories\CommunicationTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A14 template (P8.3a): the copy of one notification event on one channel (`key` =
 * "{event}.{channel}"), or a reusable campaign text (`event` = "campaign"). The text is in
 * append-only versions; `current_version` names the live one (SaveTemplate). Read by TemplateCopy.
 *
 * @property string $id
 * @property string $key
 * @property string $event
 * @property CommsChannel $channel
 * @property string $name
 * @property bool $is_active
 * @property int $current_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CommunicationTemplate extends Model
{
    /** @use HasFactory<CommunicationTemplateFactory> */
    use HasFactory, HasUlids;

    public const CAMPAIGN_EVENT = 'campaign';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => CommsChannel::class,
            'is_active' => 'boolean',
            'current_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function keyFor(string $event, CommsChannel $channel): string
    {
        return $event.'.'.$channel->value;
    }

    /** @return HasMany<CommunicationTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CommunicationTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    /** The live version (the one `current_version` names), or null. */
    public function currentVersion(): ?CommunicationTemplateVersion
    {
        return CommunicationTemplateVersion::query()->where('template_id', $this->id)->where('version', $this->current_version)->first();
    }

    /** The live text (campaign builder, P8.3b), or '' without a version. */
    public function currentBody(): string
    {
        return (string) $this->currentVersion()?->body;
    }
}
