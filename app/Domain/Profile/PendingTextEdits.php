<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\ModerationItem;
use App\Models\Profile;

/**
 * R-M02-4: on a LIVE profile, edited free text (names, about me, about family, about partner)
 * is not written straight away — it waits in ONE open PROFILE_EDIT item per profile (A04
 * "edited fields" queue) while everyone else keeps seeing the approved text (owner decision
 * 2026-10-01). Keys are "table.column". Clearing a text or typing the approved text back
 * applies at once and withdraws that field.
 * Moderation (P1.6) applies or drops the held values.
 */
final class PendingTextEdits
{
    /** Free-text columns that need review once the profile is live, per table. */
    public const FIELDS = [
        'profiles' => ['first_name', 'last_name', 'about'],
        'family_details' => ['about_family'],
        'partner_preferences' => ['about_partner'],
    ];

    /**
     * Split a step's attributes: what may be written now, and (on a live profile) hold the
     * moderated text fields that changed. $current holds the approved values of that table.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $current
     * @return array<string, mixed> the attributes to write now
     */
    public function hold(Profile $profile, string $table, array $attributes, array $current): array
    {
        if ($profile->status !== ProfileStatus::Active) {
            return $attributes;
        }

        $held = [];
        foreach (self::FIELDS[$table] ?? [] as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $new = $attributes[$column];

            // New text that differs from the approved text waits for review. Clearing a text, or
            // typing the approved text back, applies now and withdraws anything held for it.
            if ($new !== null && $new !== ($current[$column] ?? null)) {
                $held[$table.'.'.$column] = $new;
                unset($attributes[$column]);
            } else {
                $held[$table.'.'.$column] = null;
            }
        }

        if (array_filter($held, fn (mixed $v): bool => $v !== null) !== [] || $this->openItem($profile) !== null) {
            $this->merge($profile, $held);
        }

        return $attributes;
    }

    /**
     * The owner's own view of their held text (to show what they typed, labelled "in review").
     *
     * @return array<string, string|null> "table.column" => pending value
     */
    public function pending(Profile $profile): array
    {
        $fields = $this->openItem($profile)?->fields;

        return is_array($fields) ? array_map(fn (mixed $v): ?string => is_string($v) ? $v : null, $fields) : [];
    }

    /** @param  array<string, mixed>  $held  "table.column" => new value, null = withdrawn */
    private function merge(Profile $profile, array $held): void
    {
        $item = $this->openItem($profile, lock: true);
        $fields = array_filter([...(is_array($item?->fields) ? $item->fields : []), ...$held], fn (mixed $v): bool => $v !== null);

        if ($fields === []) {
            $item?->delete();
            ModerationQueueChanged::dispatch(ModerationItemType::ProfileEdit);

            return;
        }

        $item ??= new ModerationItem;
        $isNew = ! $item->exists;
        // An existing item keeps its status: a member's edit never pulls an ESCALATED item back
        // into the ordinary queue.
        $item->forceFill([
            'type' => ModerationItemType::ProfileEdit,
            'profile_id' => $profile->id,
            'fields' => $fields,
            'is_priority' => $profile->is_premium,
            ...($isNew ? ['status' => ModerationStatus::Open, 'submitted_at' => now()] : []),
        ])->save();

        if ($isNew) {
            ModerationQueueChanged::dispatch(ModerationItemType::ProfileEdit);
        }
    }

    private function openItem(Profile $profile, bool $lock = false): ?ModerationItem
    {
        $query = ModerationItem::query()
            ->where('profile_id', $profile->id)
            ->ofType(ModerationItemType::ProfileEdit)
            ->pending();

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
