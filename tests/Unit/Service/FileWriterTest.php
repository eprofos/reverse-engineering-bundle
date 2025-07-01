<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Service;

use Eprofos\ReverseEngineeringBundle\Exception\FileWriteException;
use Eprofos\ReverseEngineeringBundle\Service\FileWriter;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function dirname;

/**
 * Unit tests for FileWriter.
 */
class FileWriterTest extends TestCase
{
    private FileWriter $fileWriter;

    private vfsStreamDirectory $root;

    private string $projectDir;

    protected function setUp(): void
    {
        // Create virtual file system
        $this->root       = vfsStream::setup('project');
        $this->projectDir = vfsStream::url('project');

        $this->fileWriter = new FileWriter($this->projectDir, new NullLogger());
    }

    public function testWriteEntityFileSuccess(): void
    {
        // Arrange
        $entity = [
            'name'     => 'User',
            'filename' => 'User.php',
            'code'     => '<?php class User {}',
        ];

        // Act
        $filePath = $this->fileWriter->writeEntityFile($entity);

        // Assert
        $expectedPath = $this->projectDir . '/src/Entity/User.php';
        $this->assertEquals($expectedPath, $filePath);
        $this->assertTrue(file_exists($filePath));
        $this->assertEquals('<?php class User {}', file_get_contents($filePath));
    }

    public function testWriteEntityFileWithCustomOutputDir(): void
    {
        // Arrange
        $entity = [
            'name'     => 'Product',
            'filename' => 'Product.php',
            'code'     => '<?php class Product {}',
        ];

        // Act
        $filePath = $this->fileWriter->writeEntityFile($entity, 'custom/entities');

        // Assert
        $expectedPath = $this->projectDir . '/custom/entities/Product.php';
        $this->assertEquals($expectedPath, $filePath);
        $this->assertTrue(file_exists($filePath));
    }

    public function testWriteEntityFileCreatesDirectoryIfNotExists(): void
    {
        // Arrange
        $entity = [
            'name'     => 'Category',
            'filename' => 'Category.php',
            'code'     => '<?php class Category {}',
        ];

        // Act
        $filePath = $this->fileWriter->writeEntityFile($entity, 'deep/nested/directory');

        // Assert
        $this->assertTrue(file_exists($filePath));
        $this->assertTrue(is_dir($this->projectDir . '/deep/nested/directory'));
    }

    public function testWriteEntityFileThrowsExceptionWhenFileExistsWithoutForce(): void
    {
        // Arrange
        $entity = [
            'name'     => 'Order',
            'filename' => 'Order.php',
            'code'     => '<?php class Order {}',
        ];

        // Create file first
        $outputDir = $this->projectDir . '/src/Entity';
        mkdir($outputDir, 0o755, true);
        file_put_contents($outputDir . '/Order.php', 'existing content');

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("File 'Order.php' already exists");

        // Act
        $this->fileWriter->writeEntityFile($entity);
    }

    public function testWriteEntityFileOverwritesWhenForceIsTrue(): void
    {
        // Arrange
        $entity = [
            'name'     => 'Comment',
            'filename' => 'Comment.php',
            'code'     => '<?php class Comment {}',
        ];

        // Create file first with different content
        $outputDir = $this->projectDir . '/src/Entity';
        mkdir($outputDir, 0o755, true);
        file_put_contents($outputDir . '/Comment.php', 'old content');

        // Act
        $filePath = $this->fileWriter->writeEntityFile($entity, null, true);

        // Assert
        $this->assertTrue(file_exists($filePath));
        $this->assertEquals('<?php class Comment {}', file_get_contents($filePath));
    }

    public function testWriteRepositoryFileSuccess(): void
    {
        // Arrange
        $repository = [
            'name'         => 'UserRepository',
            'filename'     => 'UserRepository.php',
            'namespace'    => 'App\\Repository',
            'entity_class' => 'App\\Entity\\User',
        ];

        // Act
        $filePath = $this->fileWriter->writeRepositoryFile($repository);

        // Assert
        $expectedPath = $this->projectDir . '/src/Repository/UserRepository.php';
        $this->assertEquals($expectedPath, $filePath);
        $this->assertTrue(file_exists($filePath));

        $content = file_get_contents($filePath);
        $this->assertStringContains('namespace App\\Repository;', $content);
        $this->assertStringContains('class UserRepository', $content);
        $this->assertStringContains('use App\\Entity\\User;', $content);
    }

    public function testWriteRepositoryFileWithCustomOutputDir(): void
    {
        // Arrange
        $repository = [
            'name'         => 'ProductRepository',
            'filename'     => 'ProductRepository.php',
            'namespace'    => 'Custom\\Repository',
            'entity_class' => 'Custom\\Entity\\Product',
        ];

        // Act
        $filePath = $this->fileWriter->writeRepositoryFile($repository, 'custom/repositories');

        // Assert
        $expectedPath = $this->projectDir . '/custom/repositories/ProductRepository.php';
        $this->assertEquals($expectedPath, $filePath);
        $this->assertTrue(file_exists($filePath));
    }

    public function testWriteRepositoryFileThrowsExceptionWhenFileExistsWithoutForce(): void
    {
        // Arrange
        $repository = [
            'name'         => 'CategoryRepository',
            'filename'     => 'CategoryRepository.php',
            'namespace'    => 'App\\Repository',
            'entity_class' => 'App\\Entity\\Category',
        ];

        // Create file first
        $outputDir = $this->projectDir . '/src/Repository';
        mkdir($outputDir, 0o755, true);
        file_put_contents($outputDir . '/CategoryRepository.php', 'existing content');

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("Repository file 'CategoryRepository.php' already exists");

        // Act
        $this->fileWriter->writeRepositoryFile($repository);
    }

    public function testValidateOutputDirectorySuccess(): void
    {
        // Arrange
        $directory = 'valid/directory';
        mkdir($this->projectDir . '/' . $directory, 0o755, true);

        // Act & Assert
        $this->assertTrue($this->fileWriter->validateOutputDirectory($directory));
    }

    public function testValidateOutputDirectoryThrowsExceptionWhenPathIsFile(): void
    {
        // Arrange
        $filePath = 'not/a/directory.txt';
        $fullPath = $this->projectDir . '/' . $filePath;
        mkdir(dirname($fullPath), 0o755, true);
        file_put_contents($fullPath, 'content');

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("Path 'not/a/directory.txt' exists but is not a directory");

        // Act
        $this->fileWriter->validateOutputDirectory($filePath);
    }

    public function testValidateOutputDirectoryThrowsExceptionWhenNotWritable(): void
    {
        // Arrange
        $directory = 'readonly/directory';
        $fullPath  = $this->projectDir . '/' . $directory;
        mkdir($fullPath, 0o444, true); // Lecture seule

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("Directory 'readonly/directory' is not writable");

        // Act
        $this->fileWriter->validateOutputDirectory($directory);
    }

    public function testValidateOutputDirectorySucceedsForNonExistentDirectory(): void
    {
        // Act & Assert
        $this->assertTrue($this->fileWriter->validateOutputDirectory('non/existent/directory'));
    }

    public function testGenerateRepositoryCodeContainsCorrectContent(): void
    {
        // Arrange
        $repository = [
            'name'         => 'TestRepository',
            'filename'     => 'TestRepository.php',
            'namespace'    => 'App\\Repository',
            'entity_class' => 'App\\Entity\\Test',
        ];

        // Act
        $filePath = $this->fileWriter->writeRepositoryFile($repository);
        $content  = file_get_contents($filePath);

        // Assert
        $this->assertStringContains('<?php', $content);
        $this->assertStringContains('declare(strict_types=1);', $content);
        $this->assertStringContains('namespace App\\Repository;', $content);
        $this->assertStringContains('use App\\Entity\\Test;', $content);
        $this->assertStringContains('use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;', $content);
        $this->assertStringContains('use Doctrine\\Persistence\\ManagerRegistry;', $content);
        $this->assertStringContains('class TestRepository extends ServiceEntityRepository', $content);
        $this->assertStringContains('public function __construct(ManagerRegistry $registry)', $content);
        $this->assertStringContains('parent::__construct($registry, Test::class);', $content);
        $this->assertStringContains('public function find(', $content);
        $this->assertStringContains('public function findAll(', $content);
        $this->assertStringContains('public function findBy(', $content);
        $this->assertStringContains('public function findOneBy(', $content);
    }

    public function testWriteEntityFileWithConfiguredOutputDir(): void
    {
        // Arrange
        $config     = ['output_dir' => 'configured/entities'];
        $fileWriter = new FileWriter($this->projectDir, new NullLogger(), $config);

        $entity = [
            'name'     => 'ConfiguredEntity',
            'filename' => 'ConfiguredEntity.php',
            'code'     => '<?php class ConfiguredEntity {}',
        ];

        // Act
        $filePath = $fileWriter->writeEntityFile($entity);

        // Assert
        $expectedPath = $this->projectDir . '/configured/entities/ConfiguredEntity.php';
        $this->assertEquals($expectedPath, $filePath);
        $this->assertTrue(file_exists($filePath));
    }

    public function testWriteEntityFileThrowsExceptionOnWriteFailure(): void
    {
        // Arrange - Créer un répertoire en lecture seule
        $readOnlyDir = $this->projectDir . '/readonly';
        mkdir($readOnlyDir, 0o444, true);

        $entity = [
            'name'     => 'FailEntity',
            'filename' => 'FailEntity.php',
            'code'     => '<?php class FailEntity {}',
        ];

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage('Directory');

        // Act
        $this->fileWriter->writeEntityFile($entity, 'readonly');
    }

    public function testWriteRepositoryFileThrowsExceptionOnWriteFailure(): void
    {
        // Arrange - Créer un répertoire en lecture seule
        $readOnlyDir = $this->projectDir . '/readonly';
        mkdir($readOnlyDir, 0o444, true);

        $repository = [
            'name'         => 'FailRepository',
            'filename'     => 'FailRepository.php',
            'namespace'    => 'App\\Repository',
            'entity_class' => 'App\\Entity\\Fail',
        ];

        // Assert
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage('Directory');

        // Act
        $this->fileWriter->writeRepositoryFile($repository, 'readonly');
    }

    public function testEnsureDirectoryExistsThrowsExceptionWhenCannotCreateDirectory(): void
    {
        // Arrange - Create file with same name as directory to create
        $conflictPath = $this->projectDir . '/conflict';
        file_put_contents($conflictPath, 'content');

        $entity = [
            'name'     => 'ConflictEntity',
            'filename' => 'ConflictEntity.php',
            'code'     => '<?php class ConflictEntity {}',
        ];

        // Assert
        $this->expectException(FileWriteException::class);

        // Act
        $this->fileWriter->writeEntityFile($entity, 'conflict/subdir');
    }

    public function testRepositoryCodeGenerationWithComplexEntityClass(): void
    {
        // Arrange
        $repository = [
            'name'         => 'ComplexRepository',
            'filename'     => 'ComplexRepository.php',
            'namespace'    => 'Very\\Deep\\Namespace\\Repository',
            'entity_class' => 'Very\\Deep\\Namespace\\Entity\\ComplexEntity',
        ];

        // Act
        $filePath = $this->fileWriter->writeRepositoryFile($repository);
        $content  = file_get_contents($filePath);

        // Assert
        $this->assertStringContains('namespace Very\\Deep\\Namespace\\Repository;', $content);
        $this->assertStringContains('use Very\\Deep\\Namespace\\Entity\\ComplexEntity;', $content);
        $this->assertStringContains('class ComplexRepository extends ServiceEntityRepository', $content);
        $this->assertStringContains('parent::__construct($registry, ComplexEntity::class);', $content);
        $this->assertStringContains('): ?ComplexEntity', $content);
        $this->assertStringContains('ComplexEntity[]', $content);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'",
        );
    }
}
