<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HoroscopeVisibility;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-profile privacy (PRD §7.2, M11, M14). Defaults come from the migration.
 *
 * @property PhotoVisibility $photo_visibility
 * @property PhoneVisibility $phone_visibility
 * @property HoroscopeVisibility $horoscope_visibility
 * @property bool $incognito
 * @property bool $contact_filter_enabled
 */
final class PrivacySetting extends Model
{
    /** @use HasFactory<\Database\Factories\PrivacySettingFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'privacy_settings';

    /** @var list<string> */
    protected $fillable = [
        'photo_visibility',
        'phone_visibility',
        'horoscope_visibility',
        'show_online_status',
        'show_last_seen',
        'read_receipts',
        'incognito',
        'contact_filter_enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'photo_visibility' => PhotoVisibility::class,
            'phone_visibility' => PhoneVisibility::class,
            'horoscope_visibility' => HoroscopeVisibility::class,
            'show_online_status' => 'boolean',
            'show_last_seen' => 'boolean',
            'read_receipts' => 'boolean',
            'incognito' => 'boolean',
            'contact_filter_enabled' => 'boolean',
        ];
    }
}
