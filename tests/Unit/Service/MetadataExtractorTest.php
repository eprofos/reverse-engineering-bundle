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

    public function testExtractOneToManyRelationships(): void
    {
        // Arrange
        $currentTableName = 'categories';
        $allTables = ['categories', 'products'];
        
        // Mock current table details (categories)
        $currentTableDetails = [
            'table_name'    => 'categories',
            'columns'       => [
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
                    'property_name'  => 'id',
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name'           => 'name',
                    'type'           => 'string',
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => false,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => 'Category name',
                    'property_name'  => 'name',
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'foreign_keys'  => [],
            'primary_key'   => ['id'],
            'indexes'       => [],
        ];

        // Mock products table details (has foreign key to categories)
        $productsTableDetails = [
            'table_name'    => 'products',
            'columns'       => [
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
                    'property_name'  => 'id',
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name'           => 'category_id',
                    'type'           => 'integer',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => true,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => 'Category foreign key',
                    'property_name'  => 'categoryId',
                    'is_foreign_key' => true,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'foreign_keys'  => [
                [
                    'local_columns'   => ['category_id'],
                    'foreign_table'   => 'categories',
                    'foreign_columns' => ['id'],
                    'on_delete'       => 'CASCADE',
                    'on_update'       => 'CASCADE',
                ],
            ],
            'primary_key'   => ['id'],
            'indexes'       => [],
        ];

        // Set up mock expectations
        $this->databaseAnalyzer
            ->expects($this->exactly(2))
            ->method('getTableDetails')
            ->willReturnMap([
                ['categories', $currentTableDetails],
                ['products', $productsTableDetails],
            ]);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($currentTableName, $allTables);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('categories', $result['table_name']);
        $this->assertEquals('Category', $result['entity_name']);
        
        // Should have one OneToMany relation to products
        $this->assertCount(1, $result['relations']);
        
        $relation = $result['relations'][0];
        $this->assertEquals('one_to_many', $relation['type']);
        $this->assertEquals('Product', $relation['target_entity']);
        $this->assertEquals('products', $relation['target_table']);
        $this->assertEquals('products', $relation['property_name']);
        $this->assertEquals('category', $relation['mapped_by']);
        $this->assertEquals('getProducts', $relation['getter_name']);
        $this->assertEquals('addProduct', $relation['add_method_name']);
        $this->assertEquals('removeProduct', $relation['remove_method_name']);
        $this->assertEquals('product', $relation['singular_parameter_name']);
    }

    public function testExtractSelfReferencingOneToManyRelationship(): void
    {
        // Arrange
        $tableName = 'categories';
        $allTables = ['categories'];
        
        // Mock table with self-referencing foreign key
        $tableDetails = [
            'table_name'    => 'categories',
            'columns'       => [
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
                    'property_name'  => 'id',
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name'           => 'parent_id',
                    'type'           => 'integer',
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'nullable'       => true,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => 'Parent category',
                    'property_name'  => 'parentId',
                    'is_foreign_key' => true,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'foreign_keys'  => [
                [
                    'local_columns'   => ['parent_id'],
                    'foreign_table'   => 'categories',
                    'foreign_columns' => ['id'],
                    'on_delete'       => 'CASCADE',
                    'on_update'       => 'CASCADE',
                ],
            ],
            'primary_key'   => ['id'],
            'indexes'       => [],
        ];

        // Set up mock expectations
        $this->databaseAnalyzer
            ->expects($this->exactly(1))
            ->method('getTableDetails')
            ->with('categories')
            ->willReturn($tableDetails);

        // Act
        $result = $this->metadataExtractor->extractTableMetadata($tableName, $allTables);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('categories', $result['table_name']);
        $this->assertEquals('Category', $result['entity_name']);
        
        // Should have both ManyToOne (parent) and OneToMany (children) relations
        $this->assertCount(2, $result['relations']);
        
        // Find the OneToMany relation
        $oneToManyRelation = null;
        $manyToOneRelation = null;
        
        foreach ($result['relations'] as $relation) {
            if ($relation['type'] === 'one_to_many') {
                $oneToManyRelation = $relation;
            } elseif ($relation['type'] === 'many_to_one') {
                $manyToOneRelation = $relation;
            }
        }
        
        // Verify ManyToOne relation (parent)
        $this->assertNotNull($manyToOneRelation);
        $this->assertEquals('many_to_one', $manyToOneRelation['type']);
        $this->assertEquals('Category', $manyToOneRelation['target_entity']);
        $this->assertEquals('parent', $manyToOneRelation['property_name']);
        
        // Verify OneToMany relation (children)
        $this->assertNotNull($oneToManyRelation);
        $this->assertEquals('one_to_many', $oneToManyRelation['type']);
        $this->assertEquals('Category', $oneToManyRelation['target_entity']);
        $this->assertEquals('children', $oneToManyRelation['property_name']);
        $this->assertEquals('parent', $oneToManyRelation['mapped_by']);
        $this->assertEquals('getChildren', $oneToManyRelation['getter_name']);
        $this->assertEquals('addChild', $oneToManyRelation['add_method_name']);
        $this->assertEquals('removeChild', $oneToManyRelation['remove_method_name']);
        $this->assertEquals('child', $oneToManyRelation['singular_parameter_name']);
        $this->assertTrue($oneToManyRelation['is_self_referencing']);
    }
}
