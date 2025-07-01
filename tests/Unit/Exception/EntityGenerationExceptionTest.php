<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Exception;

use Eprofos\ReverseEngineeringBundle\Exception\EntityGenerationException;
use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Exception;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for EntityGenerationException.
 */
class EntityGenerationExceptionTest extends TestCase
{
    public function testExceptionWithDefaultMessage(): void
    {
        // Act
        $exception = new EntityGenerationException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertEquals('Entity generation failed', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessage(): void
    {
        // Arrange
        $message = 'Error generating User entity';

        // Act
        $exception = new EntityGenerationException($message);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessageAndCode(): void
    {
        // Arrange
        $message = 'Template Twig invalide';
        $code    = 1001;

        // Act
        $exception = new EntityGenerationException($message, $code);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithPreviousException(): void
    {
        // Arrange
        $message  = 'Entity generation error';
        $code     = 500;
        $previous = new \Twig\Error\SyntaxError('Template syntax error');

        // Act
        $exception = new EntityGenerationException($message, $code, $previous);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionInheritance(): void
    {
        // Act
        $exception = new EntityGenerationException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(Throwable::class, $exception);
    }

    public function testExceptionCanBeThrown(): void
    {
        // Assert
        $this->expectException(EntityGenerationException::class);
        $this->expectExceptionMessage('Test entity generation error');

        // Act
        throw new EntityGenerationException('Test entity generation error');
    }

    public function testExceptionCanBeCaughtAsParentType(): void
    {
        // Arrange
        $message = 'Entity generation failed';
        $caught  = false;

        // Act
        try {
            throw new EntityGenerationException($message);
        } catch (ReverseEngineeringException $e) {
            $caught = true;
            $this->assertInstanceOf(EntityGenerationException::class, $e);
            $this->assertEquals($message, $e->getMessage());
        }

        // Assert
        $this->assertTrue($caught);
    }
}
