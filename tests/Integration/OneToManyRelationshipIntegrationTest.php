<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Integration;

use Eprofos\ReverseEngineeringBundle\Service\DatabaseAnalyzer;
use Eprofos\ReverseEngineeringBundle\Service\EntityGenerator;
use Eprofos\ReverseEngineeringBundle\Service\MetadataExtractor;
use Eprofos\ReverseEngineeringBundle\Service\EnumClassGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Integration test for OneToMany relationship generation.
 * 
 * This test verifies that the complete pipeline from metadata extraction
 * to entity code generation works correctly for OneToMany relationships.
 */
class OneToManyRelationshipIntegrationTest extends TestCase
{
    private Environment $twig;
    private EntityGenerator $entityGenerator;
    private MetadataExtractor $metadataExtractor;

    protected function setUp(): void
    {
        // Set up Twig environment
        $templatePath = __DIR__ . '/../../src/Resources/templates';
        $loader = new FilesystemLoader($templatePath);
        $this->twig = new Environment($loader);

        // Set up services
        $enumClassGenerator = new EnumClassGenerator(
            __DIR__ . '/../../', // projectDir
            new NullLogger(),
            []
        );
        
        $this->entityGenerator = new EntityGenerator(
            $this->twig,
            $enumClassGenerator,
            new NullLogger(),
            [
                'namespace' => 'App\\Entity',
                'use_annotations' => false,
                'generate_repository' => true,
            ]
        );
    }

    public function testOneToManyRelationshipGeneration(): void
    {
        // Sample metadata for a Category entity with OneToMany products relationship
        $categoryMetadata = [
            'table_name' => 'categories',
            'entity_name' => 'Category',
            'repository_name' => 'CategoryRepository',
            'primary_key' => ['id'],
            'columns' => [
                [
                    'name' => 'id',
                    'property_name' => 'id',
                    'type' => 'int',
                    'doctrine_type' => 'integer',
                    'nullable' => false,
                    'length' => null,
                    'precision' => null,
                    'scale' => null,
                    'default' => null,
                    'auto_increment' => true,
                    'comment' => 'Primary key',
                    'is_primary' => true,
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
                [
                    'name' => 'name',
                    'property_name' => 'name',
                    'type' => 'string',
                    'doctrine_type' => 'string',
                    'nullable' => false,
                    'length' => 255,
                    'precision' => null,
                    'scale' => null,
                    'default' => null,
                    'auto_increment' => false,
                    'comment' => 'Category name',
                    'is_primary' => false,
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'relations' => [
                [
                    'type' => 'one_to_many',
                    'target_entity' => 'Product',
                    'target_table' => 'products',
                    'property_name' => 'products',
                    'mapped_by' => 'category',
                    'foreign_key_columns' => ['category_id'],
                    'referenced_columns' => ['id'],
                    'getter_name' => 'getProducts',
                    'add_method_name' => 'addProduct',
                    'remove_method_name' => 'removeProduct',
                    'singular_parameter_name' => 'product',
                    'on_delete' => 'CASCADE',
                    'on_update' => 'CASCADE',
                ],
            ],
            'indexes' => [],
        ];

        // Generate entity
        $result = $this->entityGenerator->generateEntity('categories', $categoryMetadata);

        // Verify basic entity structure
        $this->assertEquals('Category', $result['name']);
        $this->assertEquals('categories', $result['table']);
        $this->assertEquals('App\\Entity', $result['namespace']);
        $this->assertEquals('Category.php', $result['filename']);

        // Verify generated code contains OneToMany relationship
        $generatedCode = $result['code'];
        
        // Should contain Collection imports
        $this->assertStringContainsString('use Doctrine\\Common\\Collections\\ArrayCollection;', $generatedCode);
        $this->assertStringContainsString('use Doctrine\\Common\\Collections\\Collection;', $generatedCode);
        
        // Should contain OneToMany attribute/annotation
        $this->assertStringContainsString('#[ORM\\OneToMany(targetEntity: Product::class, mappedBy: \'category\')]', $generatedCode);
        
        // Should contain collection property
        $this->assertStringContainsString('private Collection $products;', $generatedCode);
        
        // Should contain constructor with ArrayCollection initialization
        $this->assertStringContainsString('public function __construct()', $generatedCode);
        $this->assertStringContainsString('$this->products = new ArrayCollection();', $generatedCode);
        
        // Should contain collection getter
        $this->assertStringContainsString('public function getProducts(): Collection', $generatedCode);
        $this->assertStringContainsString('return $this->products;', $generatedCode);
        
        // Should contain add method
        $this->assertStringContainsString('public function addProduct(Product $product): static', $generatedCode);
        $this->assertStringContainsString('if (!$this->products->contains($product)) {', $generatedCode);
        $this->assertStringContainsString('$this->products->add($product);', $generatedCode);
        $this->assertStringContainsString('$product->setCategory($this);', $generatedCode);
        
        // Should contain remove method
        $this->assertStringContainsString('public function removeProduct(Product $product): static', $generatedCode);
        $this->assertStringContainsString('if ($this->products->removeElement($product)) {', $generatedCode);
        $this->assertStringContainsString('$product->setCategory(null);', $generatedCode);
    }

    public function testSelfReferencingOneToManyRelationship(): void
    {
        // Sample metadata for a Category entity with self-referencing children relationship
        $categoryMetadata = [
            'table_name' => 'categories',
            'entity_name' => 'Category',
            'repository_name' => 'CategoryRepository',
            'primary_key' => ['id'],
            'columns' => [
                [
                    'name' => 'id',
                    'property_name' => 'id',
                    'type' => 'int',
                    'doctrine_type' => 'integer',
                    'nullable' => false,
                    'length' => null,
                    'precision' => null,
                    'scale' => null,
                    'default' => null,
                    'auto_increment' => true,
                    'comment' => 'Primary key',
                    'is_primary' => true,
                    'is_foreign_key' => false,
                    'needs_lifecycle_callback' => false,
                ],
            ],
            'relations' => [
                [
                    'type' => 'many_to_one',
                    'target_entity' => 'Category',
                    'target_table' => 'categories',
                    'property_name' => 'parent',
                    'local_columns' => ['parent_id'],
                    'foreign_columns' => ['id'],
                    'getter_name' => 'getParent',
                    'setter_name' => 'setParent',
                    'nullable' => true,
                    'on_delete' => 'CASCADE',
                    'on_update' => 'CASCADE',
                ],
                [
                    'type' => 'one_to_many',
                    'target_entity' => 'Category',
                    'target_table' => 'categories',
                    'property_name' => 'children',
                    'mapped_by' => 'parent',
                    'foreign_key_columns' => ['parent_id'],
                    'referenced_columns' => ['id'],
                    'getter_name' => 'getChildren',
                    'add_method_name' => 'addChild',
                    'remove_method_name' => 'removeChild',
                    'singular_parameter_name' => 'child',
                    'is_self_referencing' => true,
                    'on_delete' => 'CASCADE',
                    'on_update' => 'CASCADE',
                ],
            ],
            'indexes' => [],
        ];

        // Generate entity
        $result = $this->entityGenerator->generateEntity('categories', $categoryMetadata);

        $generatedCode = $result['code'];
        
        // Should contain both ManyToOne and OneToMany relationships
        $this->assertStringContainsString('#[ORM\\ManyToOne(targetEntity: Category::class)]', $generatedCode);
        $this->assertStringContainsString('#[ORM\\OneToMany(targetEntity: Category::class, mappedBy: \'parent\')]', $generatedCode);
        
        // Should contain both properties
        $this->assertStringContainsString('private ?Category $parent = null;', $generatedCode);
        $this->assertStringContainsString('private Collection $children;', $generatedCode);
        
        // Should contain self-referencing methods
        $this->assertStringContainsString('public function addChild(Category $child): static', $generatedCode);
        $this->assertStringContainsString('public function removeChild(Category $child): static', $generatedCode);
        $this->assertStringContainsString('$child->setParent($this);', $generatedCode);
        $this->assertStringContainsString('$child->setParent(null);', $generatedCode);
    }
}
