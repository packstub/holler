<?php

namespace Packstub\Calls\Exceptions;

/** A signal payload above `max_signal_kb`. */
class SignalTooLarge extends CallException
{
    public int $status = 413;

    public string $reason = 'too_large';
}
