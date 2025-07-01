<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Exception;

use Throwable;

/**
 * Exception thrown when database metadata extraction fails.
 *
 * This exception is raised during the metadata extraction phase when the system
 * encounters errors while analyzing database table structures, relationships,
 * or constraints. Common causes include unsupported column types, corrupted
 * table schemas, or database introspection failures.
 */
class MetadataExtractionException extends ReverseEngineeringException
{
    /**
     * MetadataExtractionException constructor.
     *
     * Creates a new metadata extraction exception with detailed error information.
     * This exception should be thrown when database schema analysis fails
     * or when table metadata cannot be properly extracted and processed.
     *
     * @param string         $message  Detailed description of the metadata extraction error
     * @param int            $code     Error code for categorizing the extraction failure type
     * @param Throwable|null $previous Previous exception that caused this metadata error
     */
    public function __construct(
        string $message = 'Metadata extraction failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
