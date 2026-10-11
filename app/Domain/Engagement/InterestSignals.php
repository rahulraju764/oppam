<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Events\Engagement\InterestChanged;
use App\Models\Profile;

/**
 * After an interest between two members changed: tell both open pages where they now stand with
 * the other (InterestChanged, each from its own point of view). Call it after the change is
 * written; the events themselves leave after the transaction commits.
 */
final class InterestSignals
{
    public function __construct(private readonly Connections $connections) {}

    public function changed(Profile $a, Profile $b): void
    {
        $this->connections->forget();

        foreach ([[$a, $b], [$b, $a]] as [$me, $other]) {
            if ($me->user_id !== null) {
                InterestChanged::dispatch($me->user_id, $me->id, $other->id, $other->code, $this->connections->stateWith($me, $other));
            }
        }
    }
}
