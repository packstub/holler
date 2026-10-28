<?php

namespace Packstub\Calls\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;

/** Someone who was rung is on the call now (the first one also fires CallAnswered). */
class ParticipantJoined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call, public CallParticipant $participant) {}
}
