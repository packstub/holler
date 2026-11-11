<?php

namespace Packstub\Holler\Exceptions;

/** More people than `max_participants` allows on one call. */
class CallFull extends CallException
{
    public int $status = 422;

    public string $reason = 'full';
}
