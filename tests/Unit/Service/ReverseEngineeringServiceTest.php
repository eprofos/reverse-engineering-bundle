<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Service;

use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Eprofos\ReverseEngineeringBundle\Service\DatabaseAnalyzer;
use Eprofos\ReverseEngineeringBundle\Service\EntityGenerator;
use Eprofos\ReverseEngineeringBundle\Service\FileWriter;
use Eprofos\ReverseEngineeringBundle\Service\MetadataExtractor;
use Eprofos\ReverseEngineeringBundle\Service\ReverseEngineeringService;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ReverseEngineeringService.
 */
class ReverseEngineeringServiceTest extends TestCase
{
    private ReverseEngineeringService $service;

    private DatabaseAnalyzer|MockObject $databaseAnalyzer;

    private MetadataExtractor|MockObject $metadataExtractor;

    private EntityGenerator|MockObject $entityGenerator;

    private FileWriter|MockObject $fileWriter;

    protected function setUp(): void
    {
        $this->databaseAnalyzer  = $this->createMock(DatabaseAnalyzer::class);
        $this->metadataExtractor = $this->createMock(MetadataExtractor::class);
        $this->entityGenerator   = $this->createMock(EntityGenerator::class);
        $this->fileWriter        = $this->createMock(FileWriter::class);

        $this->service = new ReverseEngineeringService(
            $this->databaseAnalyzer,
            $this->metadataExtractor,
            $this->entityGenerator,
            $this->fileWriter,
            new NullLogger(),
        );
    }

    public function testGenerateEntitiesSuccess(): void
    {
        // Arrange
        $options = [
            'tables'  => ['users', 'posts'],
            'exclude' => [],
            'dry_run' => false,
            'force'   => false,
        ];

        $tables       = ['users', 'posts'];
        $userMetadata = [
            'entity_name' => 'User',
            'table_name'  => 'users',
        ];
        $postMetadata = [
            'entity_name' => 'Post',
            'table_name'  => 'posts',
        ];

        $userEntity = [
            'name'     => 'User',
            'table'    => 'users',
            'code'     => '<?php class User {}',
            'filename' => 'User.php',
        ];
        $postEntity = [
            'name'       => 'Post',
            'table'      => 'posts',
            'code'       => '<?php class Post {}',
            'filename'   => 'Post.php',
            'repository' => [
                'name'     => 'PostRepository',
                'filename' => 'PostRepository.php',
            ],
        ];

        // Mock expectations
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->with(['users', 'posts'], [])
            ->willReturn($tables);

        $this->metadataExtractor
            ->expects($this->exactly(2))
            ->method('extractTableMetadata')
            ->willReturnMap([
                ['users', $tables, $userMetadata],
                ['posts', $tables, $postMetadata],
            ]);

        $this->entityGenerator
            ->expects($this->exactly(2))
            ->method('generateEntity')
            ->willReturnMap([
                ['users', $userMetadata, $options, $userEntity],
                ['posts', $postMetadata, $options, $postEntity],
            ]);

        $this->fileWriter
            ->expects($this->exactly(2))
            ->method('writeEntityFile')
            ->willReturnOnConsecutiveCalls(
                '/path/to/User.php',
                '/path/to/Post.php',
            );

        $this->fileWriter
            ->expects($this->once())
            ->method('writeRepositoryFile')
            ->willReturn('/path/to/PostRepository.php');

        // Act
        $result = $this->service->generateEntities($options);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('entities', $result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('tables_processed', $result);
        $this->assertCount(2, $result['entities']);
        $this->assertEquals(2, $result['tables_processed']);
        $this->assertCount(3, $result['files']); // 2 entities + 1 repository
    }

    public function testGenerateEntitiesWithDryRun(): void
    {
        // Arrange
        $options  = ['dry_run' => true];
        $tables   = ['users'];
        $metadata = ['entity_name' => 'User'];
        $entity   = ['name' => 'User', 'table' => 'users'];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn($tables);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn($metadata);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willReturn($entity);

        // FileWriter ne doit pas être appelé en mode dry-run
        $this->fileWriter
            ->expects($this->never())
            ->method('writeEntityFile');

        // Act
        $result = $this->service->generateEntities($options);

        // Assert
        $this->assertEmpty($result['files']);
        $this->assertCount(1, $result['entities']);
    }

    public function testGenerateEntitiesThrowsExceptionWhenNoTables(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn([]);

        // Assert
        $this->expectException(ReverseEngineeringException::class);
        $this->expectExceptionMessage('No tables found to process');

        // Act
        $this->service->generateEntities();
    }

    public function testGenerateEntitiesThrowsExceptionOnDatabaseError(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willThrowException(new Exception('Database error'));

        // Assert
        $this->expectException(ReverseEngineeringException::class);
        $this->expectExceptionMessage('Entity generation failed:');

        // Act
        $this->service->generateEntities();
    }

    public function testGenerateEntitiesThrowsExceptionOnMetadataError(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['users']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willThrowException(new Exception('Metadata error'));

        // Assert
        $this->expectException(ReverseEngineeringException::class);
        $this->expectExceptionMessage('Entity generation failed:');

        // Act
        $this->service->generateEntities();
    }

    public function testGenerateEntitiesThrowsExceptionOnEntityGenerationError(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['users']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'User']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willThrowException(new Exception('Generation error'));

        // Assert
        $this->expectException(ReverseEngineeringException::class);
        $this->expectExceptionMessage('Entity generation failed:');

        // Act
        $this->service->generateEntities();
    }

    public function testGenerateEntitiesThrowsExceptionOnFileWriteError(): void
    {
        // Arrange
        $options = ['dry_run' => false];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['users']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'User']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willReturn(['name' => 'User', 'filename' => 'User.php']);

        $this->fileWriter
            ->expects($this->once())
            ->method('writeEntityFile')
            ->willThrowException(new Exception('Write error'));

        // Assert
        $this->expectException(ReverseEngineeringException::class);
        $this->expectExceptionMessage('Entity generation failed:');

        // Act
        $this->service->generateEntities($options);
    }

    public function testValidateDatabaseConnectionSuccess(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('testConnection')
            ->willReturn(true);

        // Act
        $result = $this->service->validateDatabaseConnection();

        // Assert
        $this->assertTrue($result);
    }

    public function testValidateDatabaseConnectionFailure(): void
    {
        // Arrange
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('testConnection')
            ->willReturn(false);

        // Act
        $result = $this->service->validateDatabaseConnection();

        // Assert
        $this->assertFalse($result);
    }

    public function testGetAvailableTables(): void
    {
        // Arrange
        $expectedTables = ['users', 'posts', 'comments'];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('listTables')
            ->willReturn($expectedTables);

        // Act
        $result = $this->service->getAvailableTables();

        // Assert
        $this->assertEquals($expectedTables, $result);
    }

    public function testGetTableInfo(): void
    {
        // Arrange
        $tableName        = 'users';
        $expectedMetadata = [
            'entity_name' => 'User',
            'table_name'  => 'users',
            'columns'     => [],
        ];

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->with($tableName)
            ->willReturn($expectedMetadata);

        // Act
        $result = $this->service->getTableInfo($tableName);

        // Assert
        $this->assertEquals($expectedMetadata, $result);
    }

    public function testGenerateEntitiesWithSpecificTables(): void
    {
        // Arrange
        $options = [
            'tables'  => ['users'],
            'exclude' => ['temp_table'],
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->with(['users'], ['temp_table'])
            ->willReturn(['users']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'User']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willReturn(['name' => 'User', 'filename' => 'User.php']);

        $this->fileWriter
            ->expects($this->once())
            ->method('writeEntityFile')
            ->willReturn('/path/to/User.php');

        // Act
        $result = $this->service->generateEntities($options);

        // Assert
        $this->assertEquals(1, $result['tables_processed']);
    }

    public function testGenerateEntitiesWithCustomOptions(): void
    {
        // Arrange
        $options = [
            'output_dir' => 'custom/entities',
            'force'      => true,
            'namespace'  => 'Custom\\Entity',
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['products']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'Product']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->with('products', ['entity_name' => 'Product'], $options)
            ->willReturn(['name' => 'Product', 'filename' => 'Product.php']);

        $this->fileWriter
            ->expects($this->once())
            ->method('writeEntityFile')
            ->with(
                ['name' => 'Product', 'filename' => 'Product.php'],
                'custom/entities',
                true,
            )
            ->willReturn('/path/to/Product.php');

        // Act
        $result = $this->service->generateEntities($options);

        // Assert
        $this->assertCount(1, $result['files']);
    }

    public function testGenerateEntitiesHandlesRepositoryGeneration(): void
    {
        // Arrange
        $entity = [
            'name'       => 'Category',
            'filename'   => 'Category.php',
            'repository' => [
                'name'     => 'CategoryRepository',
                'filename' => 'CategoryRepository.php',
            ],
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['categories']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'Category']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willReturn($entity);

        $this->fileWriter
            ->expects($this->once())
            ->method('writeEntityFile')
            ->willReturn('/path/to/Category.php');

        $this->fileWriter
            ->expects($this->once())
            ->method('writeRepositoryFile')
            ->with($entity['repository'], null, false)
            ->willReturn('/path/to/CategoryRepository.php');

        // Act
        $result = $this->service->generateEntities();

        // Assert
        $this->assertCount(2, $result['files']); // Entity + Repository
    }

    public function testGenerateEntitiesSkipsRepositoryWhenNotPresent(): void
    {
        // Arrange
        $entity = [
            'name'     => 'Log',
            'filename' => 'Log.php',
            // No repository
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('analyzeTables')
            ->willReturn(['logs']);

        $this->metadataExtractor
            ->expects($this->once())
            ->method('extractTableMetadata')
            ->willReturn(['entity_name' => 'Log']);

        $this->entityGenerator
            ->expects($this->once())
            ->method('generateEntity')
            ->willReturn($entity);

        $this->fileWriter
            ->expects($this->once())
            ->method('writeEntityFile')
            ->willReturn('/path/to/Log.php');

        // writeRepositoryFile should not be called
        $this->fileWriter
            ->expects($this->never())
            ->method('writeRepositoryFile');

        // Act
        $result = $this->service->generateEntities();

        // Assert
        $this->assertCount(1, $result['files']); // Only the entity
    }
}
