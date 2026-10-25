<?php

declare(strict_types=1);

namespace App\Data\Broker;

/**
 * Everything the bureau biodata PDF prints (§11A B.7) — taken while the export holds the profile's
 * row lock (ExportManagedProfile), so the file reflects a moment the bureau still managed it. The
 * same data the profile owner sees on their own profile, never more: no contact details, no
 * horoscope file.
 */
final readonly class BiodataData
{
    /**
     * @param  array<string, string>  $headline  short facts under the name
     * @param  array<string, array<string, string>>  $sections  section title => [label => value]
     * @param  array<string, string>  $partner  partner preferences, label => value
     */
    public function __construct(
        public string $profileCode,
        public string $name,
        public ?string $photo,
        public array $headline,
        public array $sections,
        public ?string $about,
        public array $partner,
        public ?string $aboutPartner,
        public string $bureauName,
        public string $bureauCode,
        public string $bureauPhone,
        public string $preparedOn,
    ) {}
}
