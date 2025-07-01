<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Exception;

use Eprofos\ReverseEngineeringBundle\Exception\MetadataExtractionException;
use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Exception;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for MetadataExtractionException.
 */
class MetadataExtractionExceptionTest extends TestCase
{
    public function testExceptionWithDefaultMessage(): void
    {
        // Act
        $exception = new MetadataExtractionException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertEquals('Metadata extraction failed', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessage(): void
    {
        // Arrange
        $message = 'Impossible d\'extraire les métadonnées de la table users';

        // Act
        $exception = new MetadataExtractionException($message);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessageAndCode(): void
    {
        // Arrange
        $message = 'Table non trouvée';
        $code    = 404;

        // Act
        $exception = new MetadataExtractionException($message, $code);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithPreviousException(): void
    {
        // Arrange
        $message  = 'Erreur d\'extraction de métadonnées';
        $code     = 500;
        $previous = new Exception('DBAL error');

        // Act
        $exception = new MetadataExtractionException($message, $code, $previous);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionInheritance(): void
    {
        // Act
        $exception = new MetadataExtractionException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(Throwable::class, $exception);
    }

    public function testExceptionCanBeThrown(): void
    {
        // Assert
        $this->expectException(MetadataExtractionException::class);
        $this->expectExceptionMessage('Test metadata extraction error');

        // Act
        throw new MetadataExtractionException('Test metadata extraction error');
    }

    public function testExceptionCanBeCaughtAsParentType(): void
    {
        // Arrange
        $message = 'Metadata extraction failed';
        $caught  = false;

        // Act
        try {
            throw new MetadataExtractionException($message);
        } catch (ReverseEngineeringException $e) {
            $caught = true;
            $this->assertInstanceOf(MetadataExtractionException::class, $e);
            $this->assertEquals($message, $e->getMessage());
        }

        // Assert
        $this->assertTrue($caught);
    }
}
