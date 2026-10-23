<?php

namespace Packstub\Calls\Exceptions;

use RuntimeException;

/** A call could not be started, answered or signaled; `status` is the HTTP status the endpoints answer with. */
abstract class CallException extends RuntimeException
{
    public int $status = 422;
}
