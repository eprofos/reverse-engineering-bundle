<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Exception;

use Exception;
use Throwable;

/**
 * Base exception for the ReverseEngineering bundle.
 *
 * This is the root exception class for all reverse engineering related errors.
 * It provides a common base for all bundle-specific exceptions and allows
 * for centralized exception handling throughout the application.
 */
class ReverseEngineeringException extends Exception
{
    /**
     * ReverseEngineeringException constructor.
     *
     * Creates a new reverse engineering exception with optional message,
     * error code, and previous exception for exception chaining.
     *
     * @param string         $message  The exception message describing the error
     * @param int            $code     The exception code for error categorization
     * @param Throwable|null $previous Previous exception for exception chaining
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
