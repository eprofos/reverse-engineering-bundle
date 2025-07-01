<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Exception;

use Eprofos\ReverseEngineeringBundle\Exception\DatabaseConnectionException;
use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Exception;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for DatabaseConnectionException.
 */
class DatabaseConnectionExceptionTest extends TestCase
{
    public function testExceptionWithDefaultMessage(): void
    {
        // Act
        $exception = new DatabaseConnectionException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertEquals('Database connection failed', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessage(): void
    {
        // Arrange
        $message = 'Unable to connect to MySQL server';

        // Act
        $exception = new DatabaseConnectionException($message);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessageAndCode(): void
    {
        // Arrange
        $message = 'Connection timeout';
        $code    = 2002;

        // Act
        $exception = new DatabaseConnectionException($message, $code);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithPreviousException(): void
    {
        // Arrange
        $message  = 'DBAL connection error';
        $code     = 500;
        $previous = new PDOException('Connection refused');

        // Act
        $exception = new DatabaseConnectionException($message, $code, $previous);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionInheritance(): void
    {
        // Act
        $exception = new DatabaseConnectionException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(Throwable::class, $exception);
    }

    public function testExceptionCanBeThrown(): void
    {
        // Assert
        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Test database error');

        // Act
        throw new DatabaseConnectionException('Test database error');
    }

    public function testExceptionCanBeCaughtAsParentType(): void
    {
        // Arrange
        $message = 'Database error';
        $caught  = false;

        // Act
        try {
            throw new DatabaseConnectionException($message);
        } catch (ReverseEngineeringException $e) {
            $caught = true;
            $this->assertInstanceOf(DatabaseConnectionException::class, $e);
            $this->assertEquals($message, $e->getMessage());
        }

        // Assert
        $this->assertTrue($caught);
    }

    public function testExceptionWithEmptyMessage(): void
    {
        // Act
        $exception = new DatabaseConnectionException('');

        // Assert
        $this->assertEquals('', $exception->getMessage());
    }

    public function testExceptionWithNegativeCode(): void
    {
        // Arrange
        $code = -1;

        // Act
        $exception = new DatabaseConnectionException('Error', $code);

        // Assert
        $this->assertEquals($code, $exception->getCode());
    }
}
