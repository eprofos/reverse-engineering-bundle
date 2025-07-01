<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Exception;

use Throwable;

/**
 * Exception thrown when database connection operations fail.
 *
 * This exception is raised when the reverse engineering process encounters
 * database connectivity issues, including connection timeouts, authentication
 * failures, network issues, or invalid database configurations. It provides
 * specific error handling for database-related problems during schema analysis.
 */
class DatabaseConnectionException extends ReverseEngineeringException
{
    /**
     * DatabaseConnectionException constructor.
     *
     * Creates a new database connection exception with detailed error information.
     * This exception should be thrown when database connectivity issues prevent
     * the reverse engineering process from proceeding.
     *
     * @param string         $message  Detailed description of the database connection error
     * @param int            $code     Error code for categorizing the connection failure type
     * @param Throwable|null $previous Previous exception that caused this database error
     */
    public function __construct(
        string $message = 'Database connection failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
