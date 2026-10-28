<?php

namespace Packstub\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;

/** Someone is gone from the call: `$participant->status` is left, declined, missed or dropped. The call may go on for the others. */
class ParticipantLeft
{
    use Dispatchable;

    public function __construct(public Call $call, public CallParticipant $participant) {}
}
