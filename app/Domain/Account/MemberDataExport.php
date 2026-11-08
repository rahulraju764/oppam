<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Data\Broker\PhotoFile;
use App\Enums\Entitlement;
use App\Enums\PhotoStatus;
use App\Models\Block;
use App\Models\ContactDetail;
use App\Models\EducationCareer;
use App\Models\FamilyDetail;
use App\Models\Favorite;
use App\Models\HoroscopeDetail;
use App\Models\Ignore;
use App\Models\Interest;
use App\Models\LifestyleDetail;
use App\Models\Like;
use App\Models\Media;
use App\Models\NotificationPreference;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What "Download my data" contains (M14, DPDP — owner to confirm, decisions 2026-11-08 P8.2c):
 * the member's own account, every profile section, privacy and notification choices, saved
 * searches, favourites with their private notes, likes / interests / blocks / ignores they sent or
 * received (the other member named by profile code only), and their own photos that are not
 * rejected. Never internal ids, other members' details, message text of other members, password
 * hashes or tokens. Dates are IST. "Who liked me" follows the plan exactly as /likes does (§7.3,
 * rule 5): without Entitlement::SeeWhoLikedMe it is a count only — the export is no way round it.
 */
final class MemberDataExport
{
    private const DROPPED = ['id', 'profile_id', 'user_id', 'password', 'remember_token', 'alert_token', 'deleted_at'];

    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();

        return [
            'exported_at' => $this->date(now()),
            'account' => [
                'mobile' => $user->phone,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'profile_created_for' => $user->created_for->value,
                'joined_at' => $this->date($user->created_at),
            ],
            'profile' => $profile !== null ? $this->profile($profile) : null,
            'notification_preferences' => NotificationPreference::query()->where('user_id', $user->id)->orderBy('event')->get()
                ->map(fn (NotificationPreference $p): array => ['event' => $p->event, 'in_app' => $p->in_app, 'email' => $p->email, 'sms' => $p->sms])
                ->all(),
        ];
    }

    /** @return list<PhotoFile> the member's own photos that are not rejected, in their order */
    public function photoFiles(User $user): array
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();
        if ($profile === null) {
            return [];
        }

        $files = [];
        $photos = Media::query()
            ->where('model_type', $profile->getMorphClass())
            ->where('model_id', $profile->id)
            ->where('collection_name', Profile::PHOTOS)
            ->where('moderation_status', '!=', PhotoStatus::Rejected)
            ->orderBy('order_column')
            ->get();

        foreach ($photos->values() as $i => $media) {
            $extension = match ($media->mime_type) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $files[] = new PhotoFile((string) $media->disk, $media->getPathRelativeToRoot(), 'photos/photo-'.($i + 1).'.'.$extension);
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function profile(Profile $profile): array
    {
        $codes = fn (iterable $ids): array => Profile::withTrashed()->whereIn('id', collect($ids)->unique()->all())->pluck('code', 'id')->all();

        $likes = Like::query()->where('from_profile_id', $profile->id)->orWhere('to_profile_id', $profile->id)->get();
        $interests = Interest::query()->where('from_profile_id', $profile->id)->orWhere('to_profile_id', $profile->id)->get();
        $favorites = Favorite::query()->where('profile_id', $profile->id)->get();
        $blocks = Block::query()->where('blocker_profile_id', $profile->id)->get();
        $ignores = Ignore::query()->where('ignorer_profile_id', $profile->id)->get();

        $map = $codes([
            ...$likes->pluck('from_profile_id'), ...$likes->pluck('to_profile_id'),
            ...$interests->pluck('from_profile_id'), ...$interests->pluck('to_profile_id'),
            ...$favorites->pluck('favorite_profile_id'), ...$blocks->pluck('blocked_profile_id'), ...$ignores->pluck('ignored_profile_id'),
        ]);
        $code = fn (string $id): string => (string) ($map[$id] ?? '');

        return [
            'code' => $profile->code,
            'status' => $profile->status->value,
            'basic' => $this->clean($profile, ['first_name', 'last_name', 'gender', 'dob', 'height_cm', 'weight_kg', 'marital_status',
                'children_count', 'physical_status', 'religion_id', 'caste_id', 'caste_no_bar', 'mother_tongue_id', 'star_id', 'rasi_id',
                'district_id', 'about', 'published_at']),
            'education_career' => $this->section(EducationCareer::query()->where('profile_id', $profile->id)->first()),
            'family' => $this->section(FamilyDetail::query()->where('profile_id', $profile->id)->first()),
            'partner_preferences' => $this->section(PartnerPreference::query()->where('profile_id', $profile->id)->first()),
            'contact' => $this->section(ContactDetail::query()->where('profile_id', $profile->id)->first()),
            'horoscope' => $this->section(HoroscopeDetail::query()->where('profile_id', $profile->id)->first()),
            'lifestyle' => $this->section(LifestyleDetail::query()->where('profile_id', $profile->id)->first()),
            'privacy' => $this->section(PrivacySetting::query()->whereKey($profile->id)->first()),
            'saved_searches' => SavedSearch::query()->where('profile_id', $profile->id)->get()
                ->map(fn (SavedSearch $s): array => ['name' => $s->name, 'filters' => $s->filters, 'alerts' => $s->alert_frequency->value, 'created_at' => $this->date($s->created_at)])
                ->all(),
            'favourites' => $favorites->map(fn (Favorite $f): array => ['profile' => $code($f->favorite_profile_id), 'note' => $f->note, 'added_at' => $this->date($f->created_at)])->all(),
            'likes_sent' => $likes->where('from_profile_id', $profile->id)->map(fn (Like $l): array => ['profile' => $code($l->to_profile_id), 'at' => $this->date($l->created_at)])->values()->all(),
            'likes_received' => $this->entitlements->allows($profile, Entitlement::SeeWhoLikedMe)
                ? $likes->where('to_profile_id', $profile->id)->map(fn (Like $l): array => ['profile' => $code($l->from_profile_id), 'at' => $this->date($l->created_at)])->values()->all()
                : ['count' => $likes->where('to_profile_id', $profile->id)->count(), 'note' => __('Who liked you is included with a paid plan.')],
            'interests_sent' => $interests->where('from_profile_id', $profile->id)->map(fn (Interest $i): array => ['profile' => $code($i->to_profile_id), 'status' => $i->status->value, 'message' => $i->message, 'sent_at' => $this->date($i->sent_at)])->values()->all(),
            'interests_received' => $interests->where('to_profile_id', $profile->id)->map(fn (Interest $i): array => ['profile' => $code($i->from_profile_id), 'status' => $i->status->value, 'sent_at' => $this->date($i->sent_at)])->values()->all(),
            'blocked' => $blocks->map(fn (Block $b): array => ['profile' => $code($b->blocked_profile_id), 'at' => $this->date($b->created_at)])->all(),
            'ignored' => $ignores->map(fn (Ignore $i): array => ['profile' => $code($i->ignored_profile_id), 'at' => $this->date($i->created_at)])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function section(?Model $row): ?array
    {
        return $row === null ? null : $this->clean($row);
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    private function clean(Model $row, ?array $only = null): array
    {
        $data = $row->attributesToArray();
        if ($only !== null) {
            $data = array_intersect_key($data, array_flip($only));
        }

        return array_diff_key($data, array_flip(self::DROPPED));
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->timezone('Asia/Kolkata')->toIso8601String() : null;
    }
}
