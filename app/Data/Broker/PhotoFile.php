<?php

declare(strict_types=1);

namespace App\Data\Broker;

/** One approved original photo for the bureau photo package (§11A B.7): where it is and its name in the zip. */
final readonly class PhotoFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $name,
    ) {}
}
