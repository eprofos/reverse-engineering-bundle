<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Exception;

use Eprofos\ReverseEngineeringBundle\Exception\FileWriteException;
use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Unit tests for FileWriteException.
 */
class FileWriteExceptionTest extends TestCase
{
    public function testExceptionWithDefaultMessage(): void
    {
        // Act
        $exception = new FileWriteException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertEquals('File write error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessage(): void
    {
        // Arrange
        $message = 'Unable to write User.php file';

        // Act
        $exception = new FileWriteException($message);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithCustomMessageAndCode(): void
    {
        // Arrange
        $message = 'Permissions insuffisantes';
        $code    = 403;

        // Act
        $exception = new FileWriteException($message, $code);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionWithPreviousException(): void
    {
        // Arrange
        $message  = 'File writing error';
        $code     = 500;
        $previous = new RuntimeException('Disk full');

        // Act
        $exception = new FileWriteException($message, $code, $previous);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionInheritance(): void
    {
        // Act
        $exception = new FileWriteException();

        // Assert
        $this->assertInstanceOf(ReverseEngineeringException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(Throwable::class, $exception);
    }

    public function testExceptionCanBeThrown(): void
    {
        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage('Test file write error');

        // Act
        throw new FileWriteException('Test file write error');
    }

    public function testExceptionCanBeCaughtAsParentType(): void
    {
        // Arrange
        $message = 'File write failed';
        $caught  = false;

        // Act
        try {
            throw new FileWriteException($message);
        } catch (ReverseEngineeringException $e) {
            $caught = true;
            $this->assertInstanceOf(FileWriteException::class, $e);
            $this->assertEquals($message, $e->getMessage());
        }

        // Assert
        $this->assertTrue($caught);
    }

    public function testExceptionWithFilePermissionScenario(): void
    {
        // Arrange
        $message = 'Directory /path/to/entities is not writable';
        $code    = 403;

        // Act
        $exception = new FileWriteException($message, $code);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithDiskSpaceScenario(): void
    {
        // Arrange
        $message  = 'Insufficient disk space to write file';
        $previous = new RuntimeException('No space left on device');

        // Act
        $exception = new FileWriteException($message, 0, $previous);

        // Assert
        $this->assertEquals($message, $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
