<?php

namespace Packstub\Holler\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;

/** Someone is gone from the call: `$participant->status` is left, declined, missed or dropped. The call may go on for the others. */
class ParticipantLeft implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call, public CallParticipant $participant) {}
}
