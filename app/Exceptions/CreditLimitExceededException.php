<?php

namespace App\Exceptions;

use Exception;

class CreditLimitExceededException extends Exception
{
    public function __construct(
        string $message = 'Transaction exceeds customer authorized credit limit.',
        int $code = 422,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
