<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Service;

use Eprofos\ReverseEngineeringBundle\Exception\MetadataExtractionException;
use Eprofos\ReverseEngineeringBundle\Service\DatabaseAnalyzer;
use Eprofos\ReverseEngineeringBundle\Service\MetadataExtractor;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for MetadataExtractor.
 */
class MetadataExtractorTest extends TestCase
{
    private MetadataExtractor $metadataExtractor;

    private DatabaseAnalyzer|MockObject $databaseAnalyzer;

    protected function setUp(): void
    {
        $this->databaseAnalyzer  = $this->createMock(DatabaseAnalyzer::class);
        $this->metadataExtractor = new MetadataExtractor($this->databaseAnalyzer, new NullLogger());
    }

    public function testExtractTableMetadataSuccess(): void
    {
        // Arrange
        $tableName    = 'users';
        $tableDetails = [
            'name'    => 'users',
            'columns' => [
                [
                    'name'           => 'id',
                    'type'           => 'integer',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => 'Primary key',
                ],
                [
                    'name'           => 'email',
                    'type'           => 'string',
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => 'User email',
                ],
                [
                    'name'           => 'created_at',
                    'type'           => 'datetime',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => 'CURRENT_TIMESTAMP',
                    'auto_increment' => false,
                    'comment'        => 'Creation date',
                ],
            ],
            'indexes' => [
                [
                    'name'    => 'PRIMARY',
                    'columns' => ['id'],
                    'unique'  => true,
                    'primary' => true,
                ],
                [
                    'name'    => 'email_unique',
                    'columns' => ['email'],
                    'unique'  => true,
                    'primary' => false,
                ],
            ],
            'foreign_keys' => [],
            'primary_key'  => ['id'],
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('getTableDetails')
            ->with($tableName)
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($tableName, $result['table_name']);
        $this->assertEquals('User', $result['entity_name']);
        $this->assertEquals('UserRepository', $result['repository_name']);
        $this->assertCount(3, $result['columns']);
        $this->assertEquals(['id'], $result['primary_key']);

        // Vérifier les colonnes
        $idColumn = $result['columns'][0];
        $this->assertEquals('id', $idColumn['property_name']);
        $this->assertEquals('int', $idColumn['type']);
        $this->assertEquals('integer', $idColumn['doctrine_type']);
        $this->assertTrue($idColumn['auto_increment']);
        $this->assertFalse($idColumn['nullable']);

        $emailColumn = $result['columns'][1];
        $this->assertEquals('email', $emailColumn['property_name']);
        $this->assertEquals('string', $emailColumn['type']);
        $this->assertEquals('string', $emailColumn['doctrine_type']);
        $this->assertEquals(255, $emailColumn['length']);

        $createdAtColumn = $result['columns'][2];
        $this->assertEquals('createdAt', $createdAtColumn['property_name']);
        $this->assertEquals('\DateTimeInterface', $createdAtColumn['type']);
        $this->assertEquals('datetime', $createdAtColumn['doctrine_type']);
    }

    public function testExtractTableMetadataWithForeignKeys(): void
    {
        // Arrange
        $tableName    = 'posts';
        $tableDetails = [
            'name'    => 'posts',
            'columns' => [
                [
                    'name'           => 'id',
                    'type'           => 'integer',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                ],
                [
                    'name'           => 'user_id',
                    'type'           => 'integer',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                ],
                [
                    'name'           => 'title',
                    'type'           => 'string',
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [
                [
                    'name'            => 'fk_posts_user',
                    'local_columns'   => ['user_id'],
                    'foreign_table'   => 'users',
                    'foreign_columns' => ['id'],
                    'on_delete'       => 'CASCADE',
                    'on_update'       => null,
                ],
            ],
            'primary_key' => ['id'],
        ];

        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('getTableDetails')
            ->with($tableName)
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $this->assertCount(1, $result['relations']);

        $relation = $result['relations'][0];
        $this->assertEquals('many_to_one', $relation['type']);
        $this->assertEquals('User', $relation['target_entity']);
        $this->assertEquals('users', $relation['target_table']);
        $this->assertEquals(['user_id'], $relation['local_columns']);
        $this->assertEquals(['id'], $relation['foreign_columns']);
        $this->assertEquals('user', $relation['property_name']);
        $this->assertEquals('CASCADE', $relation['on_delete']);
        $this->assertFalse($relation['nullable']);

        // Vérifier que user_id est marqué comme clé étrangère
        $userIdColumn = array_filter($result['columns'], fn ($col) => $col['name'] === 'user_id');
        $this->assertCount(1, $userIdColumn);
        $userIdColumn = array_values($userIdColumn)[0];
        $this->assertTrue($userIdColumn['is_foreign_key']);
    }

    public function testGenerateEntityNameFromSnakeCase(): void
    {
        // Test avec différents formats de noms de tables
        $testCases = [
            'users'              => 'User',
            'user_profiles'      => 'UserProfile',
            'product_categories' => 'ProductCategory',
            'order_items'        => 'OrderItem',
            'companies'          => 'Company',
            'categories'         => 'Category',
        ];

        foreach ($testCases as $tableName => $expectedEntityName) {
            $this->databaseAnalyzer
                ->method('getTableDetails')
                ->willReturn([
                    'name'         => $tableName,
                    'columns'      => [],
                    'indexes'      => [],
                    'foreign_keys' => [],
                    'primary_key'  => [],
                ]);

            $result = $this->metadataExtractor->extractTableMetadata($tableName);
            $this->assertEquals(
                $expectedEntityName,
                $result['entity_name'],
                "Failed for table: {$tableName}",
            );
        }
    }

    public function testMapDatabaseTypesToPhp(): void
    {
        $tableDetails = [
            'name'    => 'test_types',
            'columns' => [
                ['name' => 'int_col', 'type' => 'integer', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'bigint_col', 'type' => 'bigint', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'float_col', 'type' => 'float', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'decimal_col', 'type' => 'decimal', 'nullable' => false, 'length' => null, 'precision' => 10, 'scale' => 2, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'bool_col', 'type' => 'boolean', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'date_col', 'type' => 'date', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'datetime_col', 'type' => 'datetime', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'json_col', 'type' => 'json', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'text_col', 'type' => 'text', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'varchar_col', 'type' => 'string', 'nullable' => false, 'length' => 255, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        $result = $this->metadataExtractor->extractTableMetadata('test_types');

        $expectedTypes = [
            'intCol'      => 'int',
            'bigintCol'   => 'int',
            'floatCol'    => 'float',
            'decimalCol'  => 'string',
            'boolCol'     => 'bool',
            'dateCol'     => '\DateTimeInterface',
            'datetimeCol' => '\DateTimeInterface',
            'jsonCol'     => 'array',
            'textCol'     => 'string',
            'varcharCol'  => 'string',
        ];

        foreach ($result['columns'] as $column) {
            $this->assertEquals(
                $expectedTypes[$column['property_name']],
                $column['type'],
                "Failed for column: {$column['property_name']}",
            );
        }
    }

    public function testExtractTableMetadataThrowsExceptionOnError(): void
    {
        // Arrange
        $tableName = 'invalid_table';
        $this->databaseAnalyzer
            ->expects($this->once())
            ->method('getTableDetails')
            ->with($tableName)
            ->willThrowException(new Exception('Table not found'));

        // Assert
        $this->expectException(MetadataExtractionException::class);
        $this->expectExceptionMessage("Metadata extraction failed for table 'invalid_table':");

        // Act
        $this->metadataExtractor->extractTableMetadata($tableName);
    }

    public function testGeneratePropertyNameFromColumnName(): void
    {
        $tableDetails = [
            'name'    => 'test_properties',
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'first_name', 'type' => 'string', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'created_at', 'type' => 'datetime', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
                ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'default' => null, 'auto_increment' => false, 'comment' => ''],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        $result = $this->metadataExtractor->extractTableMetadata('test_properties');

        $expectedPropertyNames = [
            'user_id'    => 'userId',
            'first_name' => 'firstName',
            'created_at' => 'createdAt',
            'is_active'  => 'isActive',
        ];

        foreach ($result['columns'] as $column) {
            $this->assertEquals(
                $expectedPropertyNames[$column['name']],
                $column['property_name'],
                "Failed for column: {$column['name']}",
            );
        }
    }

    public function testNeedsLifecycleCallbackWithCurrentTimestampOnDatetime(): void
    {
        // Arrange
        $tableName    = 'test_lifecycle';
        $tableDetails = [
            'name'    => 'test_lifecycle',
            'columns' => [
                [
                    'name'           => 'created_at',
                    'type'           => 'datetime',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => 'CURRENT_TIMESTAMP',
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $createdAtColumn = $result['columns'][0];
        $this->assertTrue($createdAtColumn['needs_lifecycle_callback']);
    }

    public function testNeedsLifecycleCallbackWithCurrentTimestampOnTimestamp(): void
    {
        // Arrange
        $tableName    = 'test_lifecycle';
        $tableDetails = [
            'name'    => 'test_lifecycle',
            'columns' => [
                [
                    'name'           => 'updated_at',
                    'type'           => 'timestamp',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => 'CURRENT_TIMESTAMP',
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $updatedAtColumn = $result['columns'][0];
        $this->assertTrue($updatedAtColumn['needs_lifecycle_callback']);
    }

    public function testNeedsLifecycleCallbackWithNonCurrentTimestampDefault(): void
    {
        // Arrange
        $tableName    = 'test_lifecycle';
        $tableDetails = [
            'name'    => 'test_lifecycle',
            'columns' => [
                [
                    'name'           => 'created_at',
                    'type'           => 'datetime',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => '2023-01-01 00:00:00',
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $createdAtColumn = $result['columns'][0];
        $this->assertFalse($createdAtColumn['needs_lifecycle_callback']);
    }

    public function testNeedsLifecycleCallbackWithCurrentTimestampOnNonDatetimeColumn(): void
    {
        // Arrange
        $tableName    = 'test_lifecycle';
        $tableDetails = [
            'name'    => 'test_lifecycle',
            'columns' => [
                [
                    'name'           => 'status',
                    'type'           => 'string',
                    'nullable'       => false,
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => 'CURRENT_TIMESTAMP',
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => [],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $statusColumn = $result['columns'][0];
        $this->assertFalse($statusColumn['needs_lifecycle_callback']);
    }

    public function testNeedsLifecycleCallbackWithMixedColumns(): void
    {
        // Arrange
        $tableName    = 'test_mixed_lifecycle';
        $tableDetails = [
            'name'    => 'test_mixed_lifecycle',
            'columns' => [
                [
                    'name'           => 'id',
                    'type'           => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                ],
                [
                    'name'           => 'created_at',
                    'type'           => 'datetime',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => 'CURRENT_TIMESTAMP',
                    'auto_increment' => false,
                    'comment'        => '',
                ],
                [
                    'name'           => 'updated_at',
                    'type'           => 'timestamp',
                    'nullable'       => true,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                ],
                [
                    'name'           => 'name',
                    'type'           => 'string',
                    'nullable'       => false,
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                ],
            ],
            'indexes'      => [],
            'foreign_keys' => [],
            'primary_key'  => ['id'],
        ];

        $this->databaseAnalyzer
            ->method('getTableDetails')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName);

        // Assert
        $this->assertCount(4, $result['columns']);

        // Check each column's lifecycle callback requirement
        $columnsByName = [];

        foreach ($result['columns'] as $column) {
            $columnsByName[$column['name']] = $column;
        }

        $this->assertFalse($columnsByName['id']['needs_lifecycle_callback']);
        $this->assertTrue($columnsByName['created_at']['needs_lifecycle_callback']);
        $this->assertFalse($columnsByName['updated_at']['needs_lifecycle_callback']);
        $this->assertFalse($columnsByName['name']['needs_lifecycle_callback']);
    }
}
