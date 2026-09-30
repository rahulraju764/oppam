<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Lifestyle (M02 step 6, v5 addition). */
final class LifestyleDetail extends Model
{
    /** @use HasFactory<\Database\Factories\LifestyleDetailFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'lifestyle_details';

    /** @var list<string> */
    protected $fillable = [
        'diet_option_id',
        'smoking_option_id',
        'drinking_option_id',
        'complexion_option_id',
        'body_type_option_id',
        'hobbies',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hobbies' => 'array',
        ];
    }
}
