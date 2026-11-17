<?php

namespace ChristianHnl\AiDebugger\Exceptions;

use Exception;

class SessionCancelledException extends Exception
{
    public function __construct()
    {
        parent::__construct('Session was cancelled.');
    }
}
