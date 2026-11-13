<?php

namespace Packstub\Holler\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;

/** Someone put the call on hold: they are still on it (`on_hold`), away until they resume. */
class ParticipantHeld implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call, public CallParticipant $participant) {}
}
