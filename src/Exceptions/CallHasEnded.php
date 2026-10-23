<?php

namespace Packstub\Calls\Exceptions;

/** A signal for a call that already ended (a last candidate after the hang-up). */
class CallHasEnded extends CallException
{
    public int $status = 410;
}
