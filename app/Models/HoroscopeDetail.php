<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Dosham;
use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Horoscope extras (M02 step 1). The horoscope file is private media (P1.4). */
final class HoroscopeDetail extends Model
{
    /** @use HasFactory<\Database\Factories\HoroscopeDetailFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'horoscope_details';

    /** @var list<string> */
    protected $fillable = [
        'birth_time',
        'birth_place',
        'chovva_dosham',
        'papa_dosham',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chovva_dosham' => Dosham::class,
            'papa_dosham' => Dosham::class,
        ];
    }
}
