<?php

namespace Packstub\Calls\Exceptions;

/** More people than `max_participants` allows on one call. */
class CallFull extends CallException
{
    public int $status = 422;
}
