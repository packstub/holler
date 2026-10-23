<?php

namespace Packstub\Calls\Exceptions;

/** The callee is on another call. */
class CalleeBusy extends CallException
{
    public int $status = 409;
}
