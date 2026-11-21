<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use Database\Factories\WebhookReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A12 system health: one inbound webhook request (RecordWebhookReceipt) — who sent it and what
 * happened. Never the body, headers or IP.
 *
 * @property string $id
 * @property WebhookProvider $provider
 * @property WebhookOutcome $outcome
 * @property int $http_status
 * @property int $duration_ms
 * @property Carbon $received_at
 */
final class WebhookReceipt extends Model
{
    /** @use HasFactory<WebhookReceiptFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => WebhookProvider::class,
            'outcome' => WebhookOutcome::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
