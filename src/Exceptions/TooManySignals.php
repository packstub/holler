<?php

namespace Packstub\Calls\Exceptions;

/** More signals from one person waiting on a call than `max_pending_signals`: nobody is reading them. */
class TooManySignals extends CallException
{
    public int $status = 429;

    public string $reason = 'too_many';
}
