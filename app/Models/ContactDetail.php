<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Wizard step 5 — contact extras (M02). Shown only under the phone-visibility rules; the primary mobile is users.phone. */
final class ContactDetail extends Model
{
    /** @use HasFactory<\Database\Factories\ContactDetailFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'contact_details';

    /** @var list<string> */
    protected $fillable = [
        'alternate_phone',
        'contact_email',
        'contact_person',
        'contact_relation',
        'convenient_time',
        'address_line',
        'country_id',
        'state_id',
        'district_id',
        'city',
    ];
}
