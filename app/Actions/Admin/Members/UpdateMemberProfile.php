<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Data\Admin\MemberProfileEditData;
use App\Domain\Profile\CompletenessCalculator;
use App\Domain\Profile\ProfileRules;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An admin corrects a member's profile (A03 "Profile (edit with diff + reason)"). The same rules
 * as the wizard (ProfileRules — names, minimum marriage age, caste belongs to religion…), applied
 * to MemberProfileEditData::FIELDS only; nothing else can be written. The admin's edit is final
 * (no moderation round). Only changed fields are saved; the audit row holds before / after of
 * exactly those. Typed reason. Returns the changed columns.
 */
final class UpdateMemberProfile
{
    use ChangesMemberState;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompletenessCalculator $completeness,
    ) {}

    /** A hash of the editable fields as stored — what the edit form was loaded with. */
    public static function fingerprint(Profile $profile): string
    {
        return hash('sha256', (string) json_encode(array_map(
            fn (string $column): ?string => self::comparable($profile->getAttribute($column)),
            MemberProfileEditData::FIELDS,
        )));
    }

    /**
     * @return list<string> the columns that changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, MemberProfileEditData $data, string $reason, ?string $seen = null): array
    {
        Gate::forUser($admin)->authorize('members.edit');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $member, $data, $reason, $seen): array {
            $member = $this->lockMember($member);
            $profile = $this->lockProfile($member);

            if ($member->trashed() || $profile === null) {
                throw MemberStateConflict::deleted();
            }

            // $seen = fingerprint of the values the form was loaded with: another admin's edit in
            // between is never silently overwritten.
            if ($seen !== null && ! hash_equals(self::fingerprint($profile), $seen)) {
                throw MemberStateConflict::changedMeanwhile();
            }

            $input = [...$data->toArray(), 'gender' => $profile->gender->value];
            $partial = $profile->status === ProfileStatus::Draft;
            $rules = Arr::only([...ProfileRules::basic($input, $partial), ...ProfileRules::about($partial)], MemberProfileEditData::FIELDS);
            $values = Validator::make($input, $rules, [], ProfileRules::attributes())->validate();

            $before = [];
            $after = [];
            foreach (MemberProfileEditData::FIELDS as $column) {
                $old = self::comparable($profile->getAttribute($column));
                $new = self::comparable($values[$column] ?? null);

                if ($old !== $new) {
                    $before[$column] = $old;
                    $after[$column] = $new;
                }
            }

            if ($after === []) {
                throw ValidationException::withMessages(['form' => __('Nothing has changed.')]);
            }

            $profile->forceFill($after)->save();
            $profile->forceFill(['completeness' => $this->completeness->percent($profile)])->save();

            $this->audit->record('members.profile_edited', $profile, $before, $after,
                reason: $reason, actor: $admin, subjectLabel: $profile->code);

            return array_keys($after);
        });
    }

    /** Values as the strings the database round-trips, so "unchanged" means unchanged. */
    private static function comparable(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => trim((string) $value),
            default => null,
        };
    }
}
