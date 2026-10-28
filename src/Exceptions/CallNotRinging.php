<?php

namespace Packstub\Calls\Exceptions;

/** Answering as someone other than the callee, or a call that stopped ringing. */
class CallNotRinging extends CallException
{
    public int $status = 409;

    public string $reason = 'not_ringing';
}
