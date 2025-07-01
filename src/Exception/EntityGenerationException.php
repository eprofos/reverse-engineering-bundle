<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Exception;

use Throwable;

/**
 * Exception thrown when entity code generation fails.
 *
 * This exception is raised during the entity generation phase when the system
 * encounters errors while creating PHP entity classes from database metadata.
 * Common causes include template rendering failures, invalid metadata structures,
 * namespace conflicts, or issues with property/relationship mapping.
 */
class EntityGenerationException extends ReverseEngineeringException
{
    /**
     * EntityGenerationException constructor.
     *
     * Creates a new entity generation exception with detailed error information.
     * This exception should be thrown when the entity generation process fails
     * due to template errors, metadata issues, or code generation problems.
     *
     * @param string         $message  Detailed description of the entity generation error
     * @param int            $code     Error code for categorizing the generation failure type
     * @param Throwable|null $previous Previous exception that caused this generation error
     */
    public function __construct(
        string $message = 'Entity generation failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
