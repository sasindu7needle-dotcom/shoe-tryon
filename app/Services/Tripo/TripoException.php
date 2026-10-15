<?php

namespace App\Services\Tripo;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TripoException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public bool $transient = false, public bool $ambiguous = false, public int $retryAfter = 30)
    {
        parent::__construct($message);
    }
}
