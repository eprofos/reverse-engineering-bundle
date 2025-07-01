<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Exception;

use Throwable;

/**
 * Exception thrown when file writing operations fail.
 *
 * This exception is raised when the system encounters errors while writing
 * generated entity or repository files to disk. Common causes include
 * insufficient permissions, disk space issues, invalid file paths,
 * or file system access restrictions.
 */
class FileWriteException extends ReverseEngineeringException
{
    /**
     * FileWriteException constructor.
     *
     * Creates a new file write exception with detailed error information.
     * This exception should be thrown when file system operations fail
     * during the entity or repository file creation process.
     *
     * @param string         $message  Detailed description of the file write error
     * @param int            $code     Error code for categorizing the file operation failure type
     * @param Throwable|null $previous Previous exception that caused this file write error
     */
    public function __construct(
        string $message = 'File write error',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
