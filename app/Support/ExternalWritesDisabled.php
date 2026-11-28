<?php

namespace App\Support;

use RuntimeException;

final class ExternalWritesDisabled extends RuntimeException
{
    public function __construct(public readonly string $action)
    {
        parent::__construct("External writes are disabled (TOOLS_EXTERNAL_WRITES=false, and this tool hasn't moved from the old app yet); not sent: {$action}.");
    }
}
