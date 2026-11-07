<?php

declare(strict_types=1);

namespace App\Data\Profile;

use App\Enums\HoroscopeVisibility;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Models\PrivacySetting;

/** The /settings Privacy tab (M14): every member-editable privacy_settings column except show_on_home (SetHomeVisibility). */
final readonly class PrivacySettingsData
{
    public function __construct(
        public PhotoVisibility $photoVisibility,
        public PhoneVisibility $phoneVisibility,
        public HoroscopeVisibility $horoscopeVisibility,
        public bool $showOnlineStatus,
        public bool $showLastSeen,
        public bool $readReceipts,
        public bool $hideFromSearch,
        public bool $incognito,
        public bool $contactFilter,
    ) {}

    /** The stored row; the migration defaults when the member has none yet. */
    public static function from(?PrivacySetting $row): self
    {
        if ($row === null) {
            return new self(PhotoVisibility::AllMembers, PhoneVisibility::PremiumOnly, HoroscopeVisibility::AllMembers,
                showOnlineStatus: true, showLastSeen: true, readReceipts: true, hideFromSearch: false, incognito: false, contactFilter: false);
        }

        return new self(
            photoVisibility: $row->photo_visibility,
            phoneVisibility: $row->phone_visibility,
            horoscopeVisibility: $row->horoscope_visibility,
            showOnlineStatus: $row->show_online_status,
            showLastSeen: $row->show_last_seen,
            readReceipts: $row->read_receipts,
            hideFromSearch: $row->hide_from_search,
            incognito: $row->incognito,
            contactFilter: $row->contact_filter_enabled,
        );
    }

    public function equals(self $other): bool
    {
        return $this->toColumns() === $other->toColumns();
    }

    /** True when nothing here shows the member to more people than $before does. */
    public function isNoLooserThan(self $before): bool
    {
        return $this->photoVisibility->strictness() >= $before->photoVisibility->strictness()
            && $this->phoneVisibility->strictness() >= $before->phoneVisibility->strictness()
            && $this->horoscopeVisibility->strictness() >= $before->horoscopeVisibility->strictness()
            && (! $this->showOnlineStatus || $before->showOnlineStatus)
            && (! $this->showLastSeen || $before->showLastSeen)
            && (! $this->readReceipts || $before->readReceipts)
            && ($this->hideFromSearch || ! $before->hideFromSearch)
            && ($this->incognito || ! $before->incognito)
            && ($this->contactFilter || ! $before->contactFilter);
    }

    /** @return array<string, mixed> privacy_settings columns */
    public function toColumns(): array
    {
        return [
            'photo_visibility' => $this->photoVisibility,
            'phone_visibility' => $this->phoneVisibility,
            'horoscope_visibility' => $this->horoscopeVisibility,
            'show_online_status' => $this->showOnlineStatus,
            'show_last_seen' => $this->showLastSeen,
            'read_receipts' => $this->readReceipts,
            'hide_from_search' => $this->hideFromSearch,
            'incognito' => $this->incognito,
            'contact_filter_enabled' => $this->contactFilter,
        ];
    }
}
