<?php

namespace Packstub\Holler\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;

/** Someone who had put the call on hold is back in it. */
class ParticipantResumed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call, public CallParticipant $participant) {}
}
