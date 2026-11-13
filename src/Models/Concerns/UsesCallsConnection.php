<?php

namespace Packstub\Holler\Models\Concerns;

/** The tables live on `holler.connection` (null: the default connection). */
trait UsesCallsConnection
{
    public function getConnectionName(): ?string
    {
        return config('holler.connection') ?? $this->connection;
    }
}
