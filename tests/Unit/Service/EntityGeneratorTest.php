<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Service;

use Eprofos\ReverseEngineeringBundle\Exception\EntityGenerationException;
use Eprofos\ReverseEngineeringBundle\Service\EntityGenerator;
use Eprofos\ReverseEngineeringBundle\Service\EnumClassGenerator;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;

/**
 * Unit tests for EntityGenerator.
 */
class EntityGeneratorTest extends TestCase
{
    private EntityGenerator $entityGenerator;

    private Environment|MockObject $twig;

    private EnumClassGenerator|MockObject $enumClassGenerator;

    private array $config;

    protected function setUp(): void
    {
        $this->twig               = $this->createMock(Environment::class);
        $this->enumClassGenerator = $this->createMock(EnumClassGenerator::class);
        $this->config             = [
            'namespace'           => 'App\\Entity',
            'generate_repository' => true,
            'use_annotations'     => false,
        ];

        $this->entityGenerator = new EntityGenerator($this->twig, $this->enumClassGenerator, new NullLogger(), $this->config);
    }

    public function testGenerateEntitySuccess(): void
    {
        // Arrange
        $tableName = 'users';
        $metadata  = [
            'entity_name'     => 'User',
            'table_name'      => 'users',
            'repository_name' => 'UserRepository',
            'columns'         => [
                [
                    'name'           => 'id',
                    'property_name'  => 'id',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
                [
                    'name'           => 'email',
                    'property_name'  => 'email',
                    'type'           => 'string',
                    'doctrine_type'  => 'string',
                    'nullable'       => false,
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class User {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class UserRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('User', $result['name']);
        $this->assertEquals('users', $result['table']);
        $this->assertEquals('App\\Entity', $result['namespace']);
        $this->assertEquals('User.php', $result['filename']);
        $this->assertEquals($expectedCode, $result['code']);
        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('relations', $result);
        $this->assertArrayHasKey('repository', $result);
    }

    public function testGenerateEntityWithCustomNamespace(): void
    {
        // Arrange
        $tableName = 'products';
        $metadata  = [
            'entity_name'     => 'Product',
            'table_name'      => 'products',
            'repository_name' => 'ProductRepository',
            'columns'         => [],
            'relations'       => [],
            'indexes'         => [],
            'primary_key'     => [],
        ];

        $options      = ['namespace' => 'Custom\\Entity'];
        $expectedCode = '<?php class Product {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class ProductRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata, $options);

        // Assert
        $this->assertEquals('Custom\\Entity', $result['namespace']);
    }

    public function testGenerateEntityWithoutRepository(): void
    {
        // Arrange
        $tableName = 'logs';
        $metadata  = [
            'entity_name'     => 'Log',
            'table_name'      => 'logs',
            'repository_name' => 'LogRepository',
            'columns'         => [],
            'relations'       => [],
            'indexes'         => [],
            'primary_key'     => [],
        ];

        $options      = ['generate_repository' => false];
        $expectedCode = '<?php class Log {}';

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('entity.php.twig', $this->anything())
            ->willReturn($expectedCode);

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata, $options);

        // Assert
        $this->assertArrayNotHasKey('repository', $result);
    }

    public function testGenerateEntityWithRelations(): void
    {
        // Arrange
        $tableName = 'posts';
        $metadata  = [
            'entity_name'     => 'Post',
            'table_name'      => 'posts',
            'repository_name' => 'PostRepository',
            'columns'         => [
                [
                    'name'           => 'id',
                    'property_name'  => 'id',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
                [
                    'name'           => 'user_id',
                    'property_name'  => 'userId',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => true,
                ],
            ],
            'relations' => [
                [
                    'type'            => 'many_to_one',
                    'property_name'   => 'user',
                    'target_entity'   => 'User',
                    'target_table'    => 'users',
                    'local_columns'   => ['user_id'],
                    'foreign_columns' => ['id'],
                    'on_delete'       => 'CASCADE',
                    'on_update'       => null,
                    'nullable'        => false,
                ],
            ],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class Post {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class PostRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertCount(1, $result['properties']); // Seul 'id' car 'user_id' est une FK
        $this->assertCount(1, $result['relations']);

        $relation = $result['relations'][0];
        $this->assertEquals('many_to_one', $relation['type']);
        $this->assertEquals('user', $relation['property_name']);
        $this->assertEquals('User', $relation['target_entity']);
        $this->assertEquals('getUser', $relation['getter_name']);
        $this->assertEquals('setUser', $relation['setter_name']);
    }

    public function testGenerateEntityWithDateTimeColumns(): void
    {
        // Arrange
        $tableName = 'events';
        $metadata  = [
            'entity_name'     => 'Event',
            'table_name'      => 'events',
            'repository_name' => 'EventRepository',
            'columns'         => [
                [
                    'name'           => 'created_at',
                    'property_name'  => 'createdAt',
                    'type'           => '\DateTimeInterface',
                    'doctrine_type'  => 'datetime',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => [],
        ];

        $expectedCode = '<?php class Event {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $this->assertContains('DateTimeInterface', $data['imports']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class EventRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertIsArray($result);
    }

    public function testGenerateEntityWithAnnotations(): void
    {
        // Arrange
        $tableName = 'categories';
        $metadata  = [
            'entity_name'     => 'Category',
            'table_name'      => 'categories',
            'repository_name' => 'CategoryRepository',
            'columns'         => [],
            'relations'       => [],
            'indexes'         => [],
            'primary_key'     => [],
        ];

        $options      = ['use_annotations' => true];
        $expectedCode = '<?php class Category {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $this->assertTrue($data['use_annotations']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class CategoryRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata, $options);

        // Assert
        $this->assertIsArray($result);
    }

    public function testGenerateEntityThrowsExceptionOnTwigError(): void
    {
        // Arrange
        $tableName = 'invalid';
        $metadata  = [
            'entity_name'     => 'Invalid',
            'table_name'      => 'invalid',
            'repository_name' => 'InvalidRepository',
            'columns'         => [],
            'relations'       => [],
            'indexes'         => [],
            'primary_key'     => [],
        ];

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->willThrowException(new Exception('Template error'));

        // Assert
        $this->expectException(EntityGenerationException::class);
        $this->expectExceptionMessage("Entity generation failed for table 'invalid':");

        // Act
        $this->entityGenerator->generateEntity($tableName, $metadata);
    }

    public function testPreparePropertiesExcludesForeignKeys(): void
    {
        // Arrange
        $tableName = 'orders';
        $metadata  = [
            'entity_name'     => 'Order',
            'table_name'      => 'orders',
            'repository_name' => 'OrderRepository',
            'columns'         => [
                [
                    'name'           => 'id',
                    'property_name'  => 'id',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
                [
                    'name'           => 'customer_id',
                    'property_name'  => 'customerId',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => true, // Cette colonne doit être exclue
                ],
                [
                    'name'           => 'total',
                    'property_name'  => 'total',
                    'type'           => 'string',
                    'doctrine_type'  => 'decimal',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => 10,
                    'scale'          => 2,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class Order {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class OrderRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertCount(2, $result['properties']); // id et total, pas customer_id

        $propertyNames = array_column($result['properties'], 'name');
        $this->assertContains('id', $propertyNames);
        $this->assertContains('total', $propertyNames);
        $this->assertNotContains('customerId', $propertyNames);
    }

    public function testGenerateGetterAndSetterNames(): void
    {
        // Arrange
        $tableName = 'users';
        $metadata  = [
            'entity_name'     => 'User',
            'table_name'      => 'users',
            'repository_name' => 'UserRepository',
            'columns'         => [
                [
                    'name'           => 'first_name',
                    'property_name'  => 'firstName',
                    'type'           => 'string',
                    'doctrine_type'  => 'string',
                    'nullable'       => false,
                    'length'         => 255,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => [],
        ];

        $expectedCode = '<?php class User {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class UserRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $property = $result['properties'][0];
        $this->assertEquals('getFirstName', $property['getter_name']);
        $this->assertEquals('setFirstName', $property['setter_name']);
    }

    public function testGenerateRepositoryData(): void
    {
        // Arrange
        $tableName = 'products';
        $metadata  = [
            'entity_name'     => 'Product',
            'table_name'      => 'products',
            'repository_name' => 'ProductRepository',
            'columns'         => [],
            'relations'       => [],
            'indexes'         => [],
            'primary_key'     => [],
        ];

        $options      = ['namespace' => 'Custom\\Entity'];
        $expectedCode = '<?php class Product {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class ProductRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata, $options);

        // Assert
        $repository = $result['repository'];
        $this->assertEquals('ProductRepository', $repository['name']);
        $this->assertEquals('Custom\\Repository', $repository['namespace']);
        $this->assertEquals('ProductRepository.php', $repository['filename']);
        $this->assertEquals('Custom\\Entity\\Product', $repository['entity_class']);
    }

    public function testGenerateImportsWithoutAnnotations(): void
    {
        // Arrange
        $tableName = 'articles';
        $metadata  = [
            'entity_name'     => 'Article',
            'table_name'      => 'articles',
            'repository_name' => 'ArticleRepository',
            'columns'         => [
                [
                    'name'           => 'published_at',
                    'property_name'  => 'publishedAt',
                    'type'           => '\DateTimeInterface',
                    'doctrine_type'  => 'datetime',
                    'nullable'       => true,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
            ],
            'relations' => [
                [
                    'type'            => 'many_to_one',
                    'property_name'   => 'author',
                    'target_entity'   => 'User',
                    'local_columns'   => ['author_id'],
                    'foreign_columns' => ['id'],
                    'on_delete'       => null,
                    'on_update'       => null,
                    'nullable'        => true,
                ],
            ],
            'indexes'     => [],
            'primary_key' => [],
        ];

        $options      = ['use_annotations' => false];
        $expectedCode = '<?php class Article {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $imports = $data['imports'];
                    $this->assertContains('DateTimeInterface', $imports);
                    $this->assertContains('Doctrine\\ORM\\Mapping as ORM', $imports);
                    $this->assertContains('App\\Repository\\ArticleRepository', $imports);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class ArticleRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata, $options);

        // Assert
        $this->assertIsArray($result);
    }

    public function testHasLifecycleCallbacksWithCurrentTimestampProperties(): void
    {
        // Arrange
        $tableName = 'test_lifecycle';
        $metadata  = [
            'entity_name'     => 'TestLifecycle',
            'table_name'      => 'test_lifecycle',
            'repository_name' => 'TestLifecycleRepository',
            'columns'         => [
                [
                    'name'                     => 'id',
                    'property_name'            => 'id',
                    'type'                     => 'int',
                    'doctrine_type'            => 'integer',
                    'nullable'                 => false,
                    'length'                   => null,
                    'precision'                => null,
                    'scale'                    => null,
                    'default'                  => null,
                    'auto_increment'           => true,
                    'comment'                  => '',
                    'is_foreign_key'           => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name'                     => 'created_at',
                    'property_name'            => 'createdAt',
                    'type'                     => '\DateTimeInterface',
                    'doctrine_type'            => 'datetime',
                    'nullable'                 => false,
                    'length'                   => null,
                    'precision'                => null,
                    'scale'                    => null,
                    'default'                  => 'CURRENT_TIMESTAMP',
                    'auto_increment'           => false,
                    'comment'                  => '',
                    'is_foreign_key'           => false,
                    'needs_lifecycle_callback' => true,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class TestLifecycle {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $this->assertTrue($data['has_lifecycle_callbacks']);
                    $this->assertContains('DateTime', $data['imports']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class TestLifecycleRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertTrue($result['has_lifecycle_callbacks']);
        $this->assertCount(2, $result['properties']);

        // Check that the property with lifecycle callback is correctly marked
        $createdAtProperty = null;

        foreach ($result['properties'] as $property) {
            if ($property['name'] === 'createdAt') {
                $createdAtProperty = $property;
                break;
            }
        }

        $this->assertNotNull($createdAtProperty);
        $this->assertTrue($createdAtProperty['needs_lifecycle_callback']);
    }

    public function testHasLifecycleCallbacksWithoutCurrentTimestampProperties(): void
    {
        // Arrange
        $tableName = 'test_no_lifecycle';
        $metadata  = [
            'entity_name'     => 'TestNoLifecycle',
            'table_name'      => 'test_no_lifecycle',
            'repository_name' => 'TestNoLifecycleRepository',
            'columns'         => [
                [
                    'name'                     => 'id',
                    'property_name'            => 'id',
                    'type'                     => 'int',
                    'doctrine_type'            => 'integer',
                    'nullable'                 => false,
                    'length'                   => null,
                    'precision'                => null,
                    'scale'                    => null,
                    'default'                  => null,
                    'auto_increment'           => true,
                    'comment'                  => '',
                    'is_foreign_key'           => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name'                     => 'name',
                    'property_name'            => 'name',
                    'type'                     => 'string',
                    'doctrine_type'            => 'string',
                    'nullable'                 => false,
                    'length'                   => 255,
                    'precision'                => null,
                    'scale'                    => null,
                    'default'                  => null,
                    'auto_increment'           => false,
                    'comment'                  => '',
                    'is_foreign_key'           => false,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class TestNoLifecycle {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $this->assertFalse($data['has_lifecycle_callbacks']);
                    $this->assertNotContains('DateTime', $data['imports']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class TestNoLifecycleRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertFalse($result['has_lifecycle_callbacks']);
    }

    public function testGenerateImportsIncludesDateTimeForLifecycleCallbacks(): void
    {
        // Arrange
        $tableName = 'test_datetime_import';
        $metadata  = [
            'entity_name'     => 'TestDateTimeImport',
            'table_name'      => 'test_datetime_import',
            'repository_name' => 'TestDateTimeImportRepository',
            'columns'         => [
                [
                    'name'                     => 'created_at',
                    'property_name'            => 'createdAt',
                    'type'                     => '\DateTimeInterface',
                    'doctrine_type'            => 'datetime',
                    'nullable'                 => false,
                    'length'                   => null,
                    'precision'                => null,
                    'scale'                    => null,
                    'default'                  => 'CURRENT_TIMESTAMP',
                    'auto_increment'           => false,
                    'comment'                  => '',
                    'is_foreign_key'           => false,
                    'needs_lifecycle_callback' => true,
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => [],
        ];

        $expectedCode = '<?php class TestDateTimeImport {}';

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    $imports = $data['imports'];
                    $this->assertContains('DateTimeInterface', $imports);
                    $this->assertContains('DateTime', $imports);
                    $this->assertContains('Doctrine\\ORM\\Mapping as ORM', $imports);
                    $this->assertTrue($data['has_lifecycle_callbacks']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class TestDateTimeImportRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertTrue($result['has_lifecycle_callbacks']);
    }

    public function testGenerateEntityWithEnumColumn(): void
    {
        // Arrange
        $tableName = 'users';
        $metadata  = [
            'entity_name'     => 'User',
            'table_name'      => 'users',
            'repository_name' => 'UserRepository',
            'columns'         => [
                [
                    'name'           => 'id',
                    'property_name'  => 'id',
                    'type'           => 'int',
                    'doctrine_type'  => 'integer',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => true,
                    'comment'        => '',
                    'is_foreign_key' => false,
                ],
                [
                    'name'           => 'status',
                    'property_name'  => 'status',
                    'type'           => 'string',
                    'doctrine_type'  => 'string',
                    'nullable'       => false,
                    'length'         => null,
                    'precision'      => null,
                    'scale'          => null,
                    'default'        => null,
                    'auto_increment' => false,
                    'comment'        => '',
                    'is_foreign_key' => false,
                    'enum_values'    => ['active', 'inactive', 'pending'],
                ],
            ],
            'relations'   => [],
            'indexes'     => [],
            'primary_key' => ['id'],
        ];

        $expectedCode = '<?php class User {}';

        // Mock the enum class generator
        $this->enumClassGenerator
            ->expects($this->exactly(2))
            ->method('generateEnumClassName')
            ->with('users', 'status')
            ->willReturn('UserStatusEnum');

        $this->enumClassGenerator
            ->expects($this->once())
            ->method('generateEnumContent')
            ->with('UserStatusEnum', ['active', 'inactive', 'pending'], 'users', 'status')
            ->willReturn('<?php enum UserStatusEnum: string {}');

        $this->enumClassGenerator
            ->expects($this->once())
            ->method('writeEnumFile')
            ->with('UserStatusEnum', '<?php enum UserStatusEnum: string {}', true)
            ->willReturn('/path/to/UserStatusEnum.php');

        $this->enumClassGenerator
            ->expects($this->exactly(2))
            ->method('getEnumFullyQualifiedName')
            ->with('UserStatusEnum')
            ->willReturn('App\\Enum\\UserStatusEnum');

        $this->twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $data) use ($expectedCode) {
                if ($template === 'entity.php.twig') {
                    // Verify that enum import is included
                    $this->assertContains('App\\Enum\\UserStatusEnum', $data['imports']);

                    // Verify that the status property has enum class information
                    $statusProperty = null;

                    foreach ($data['properties'] as $property) {
                        if ($property['name'] === 'status') {
                            $statusProperty = $property;
                            break;
                        }
                    }

                    $this->assertNotNull($statusProperty);
                    $this->assertTrue($statusProperty['has_enum_class']);
                    $this->assertEquals('UserStatusEnum', $statusProperty['enum_class']);
                    $this->assertEquals('App\\Enum\\UserStatusEnum', $statusProperty['enum_fqn']);

                    return $expectedCode;
                }

                if ($template === 'repository.php.twig') {
                    return '<?php class UserRepository {}';
                }

                return '';
            });

        // Act
        $result = $this->entityGenerator->generateEntity($tableName, $metadata);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('User', $result['name']);
        $this->assertCount(2, $result['properties']);
    }
}
