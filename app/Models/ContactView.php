<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContactViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact reveal (R-M03-2): the viewer has seen this profile's contact details. One row per
 * pair; the contact-view entitlement is charged when the row is first created (ViewContact).
 *
 * @property string $id
 * @property string $viewer_profile_id
 * @property string $viewed_profile_id
 */
final class ContactView extends Model
{
    /** @use HasFactory<ContactViewFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];
}
