<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Profile\AgeCalculator;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\District;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * A matrimony profile (PRD §7.2). URLs use `code` (OPM10001), never the ULID. code, status,
 * is_verified, is_premium, completeness and user_id are not mass-assignable: they are set by
 * Actions (code by ProfileCodeGenerator, status by moderation, is_verified by M09 …).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $code
 * @property Gender $gender
 * @property string $first_name
 * @property string|null $last_name null only while DRAFT (DB CHECK)
 * @property CarbonImmutable|null $dob
 * @property int|null $height_cm
 * @property int|null $weight_kg
 * @property MaritalStatus|null $marital_status
 * @property int $children_count
 * @property PhysicalStatus $physical_status
 * @property int|null $religion_id
 * @property int|null $caste_id
 * @property bool $caste_no_bar
 * @property int|null $mother_tongue_id
 * @property int|null $star_id
 * @property int|null $rasi_id
 * @property int|null $district_id
 * @property ProfileStatus $status
 * @property ProfileStatus|null $previous_status before an admin suspended / hid / deleted it (A03)
 * @property bool $is_verified
 * @property bool $is_premium
 * @property int $completeness
 * @property string|null $about
 * @property string|null $sub_caste
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $last_active_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class Profile extends Model implements HasMedia
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, HasUlids, InteractsWithMedia, SoftDeletes;

    /** Media collections (M11). */
    public const PHOTOS = 'photos';

    public const HOROSCOPE = 'horoscope';

    /** @var list<string> */
    protected $fillable = [
        'gender',
        'first_name',
        'last_name',
        'dob',
        'height_cm',
        'weight_kg',
        'marital_status',
        'children_count',
        'physical_status',
        'religion_id',
        'caste_id',
        'caste_no_bar',
        'sub_caste',
        'mother_tongue_id',
        'star_id',
        'rasi_id',
        'district_id',
        'about',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'dob' => 'immutable_date',
            'marital_status' => MaritalStatus::class,
            'physical_status' => PhysicalStatus::class,
            'status' => ProfileStatus::class,
            'previous_status' => ProfileStatus::class,
            'caste_no_bar' => 'boolean',
            'is_verified' => 'boolean',
            'is_premium' => 'boolean',
            'highlighted_until' => 'datetime',
            'published_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    /** Public URLs resolve profiles by code (CLAUDE.md "URLs use codes, never ULIDs"). */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /** Age in completed years on $today (default: today in IST); null while a DRAFT has no DOB yet. */
    public function age(?CarbonInterface $today = null): ?int
    {
        if ($this->dob === null) {
            return null;
        }

        return AgeCalculator::ageOn($this->dob, $today ?? CarbonImmutable::now(config('oppam.display_timezone')));
    }

    /** @param Builder<self> $query */
    public function scopeSearchable(Builder $query): void
    {
        $query->where('status', ProfileStatus::Active->value);
    }

    /**
     * Photos: originals on the private disk, conversions on the public disk under uuid paths.
     * Horoscope: one private file, served only through a signed + audited route (M11).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS)
            ->useDisk((string) config('oppam.media.private_disk'))
            ->storeConversionsOnDisk((string) config('oppam.media.public_disk'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection(self::HOROSCOPE)
            ->singleFile()
            ->useDisk((string) config('oppam.media.private_disk'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /**
     * Photo conversions (M11), WebP, queued on `media`: thumb 200 (square), card 600, full 1200
     * with a light profile-code watermark, and blurred 600 — the only version a viewer without
     * permission ever receives (PhotoUrls).
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        $this->addMediaConversion('thumb')->performOnCollections(self::PHOTOS)->nonOptimized()
            ->fit(Fit::Crop, 200, 200)->format('webp');

        $this->addMediaConversion('card')->performOnCollections(self::PHOTOS)->nonOptimized()
            ->fit(Fit::Max, 600, 600)->format('webp');

        $this->addMediaConversion('full')->performOnCollections(self::PHOTOS)->nonOptimized()
            ->fit(Fit::Max, 1200, 1200)
            ->text($this->code.' · oppam.in', 16, 'rgba(255, 255, 255, 0.55)', 20, 36, 0, (string) config('oppam.media.watermark_font'))
            ->format('webp');

        $this->addMediaConversion('blurred')->performOnCollections(self::PHOTOS)->nonOptimized()
            ->fit(Fit::Max, 600, 600)->pixelate(24)->blur(12)->format('webp');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Religion, $this> */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /** @return BelongsTo<Caste, $this> */
    public function caste(): BelongsTo
    {
        return $this->belongsTo(Caste::class);
    }

    /** @return BelongsTo<MotherTongue, $this> */
    public function motherTongue(): BelongsTo
    {
        return $this->belongsTo(MotherTongue::class);
    }

    /** @return BelongsTo<Star, $this> */
    public function star(): BelongsTo
    {
        return $this->belongsTo(Star::class);
    }

    /** @return BelongsTo<Rasi, $this> */
    public function rasi(): BelongsTo
    {
        return $this->belongsTo(Rasi::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return HasOne<EducationCareer, $this> */
    public function educationCareer(): HasOne
    {
        return $this->hasOne(EducationCareer::class);
    }

    /** @return HasOne<FamilyDetail, $this> */
    public function familyDetail(): HasOne
    {
        return $this->hasOne(FamilyDetail::class);
    }

    /** @return HasOne<PartnerPreference, $this> */
    public function partnerPreference(): HasOne
    {
        return $this->hasOne(PartnerPreference::class);
    }

    /** @return HasOne<ContactDetail, $this> */
    public function contactDetail(): HasOne
    {
        return $this->hasOne(ContactDetail::class);
    }

    /** @return HasOne<HoroscopeDetail, $this> */
    public function horoscopeDetail(): HasOne
    {
        return $this->hasOne(HoroscopeDetail::class);
    }

    /** @return HasOne<LifestyleDetail, $this> */
    public function lifestyleDetail(): HasOne
    {
        return $this->hasOne(LifestyleDetail::class);
    }

    /** @return HasOne<PrivacySetting, $this> */
    public function privacySetting(): HasOne
    {
        return $this->hasOne(PrivacySetting::class);
    }

    /** @return HasMany<SavedSearch, $this> */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }
}
