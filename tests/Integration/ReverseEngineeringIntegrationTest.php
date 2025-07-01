<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Eprofos\ReverseEngineeringBundle\Service\DatabaseAnalyzer;
use Eprofos\ReverseEngineeringBundle\Service\EntityGenerator;
use Eprofos\ReverseEngineeringBundle\Service\EnumClassGenerator;
use Eprofos\ReverseEngineeringBundle\Service\FileWriter;
use Eprofos\ReverseEngineeringBundle\Service\MetadataExtractor;
use Eprofos\ReverseEngineeringBundle\Service\ReverseEngineeringService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Tests d'intégration pour le processus complet de reverse engineering.
 */
class ReverseEngineeringIntegrationTest extends TestCase
{
    private Connection $connection;

    private ReverseEngineeringService $service;

    private string $tempDir;

    protected function setUp(): void
    {
        // Create SQLite in-memory database
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Create temporary directory for generated files
        $this->tempDir = sys_get_temp_dir() . '/reverse_engineering_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        // Configurer les services
        $this->setupServices();

        // Créer les tables de test
        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        // Nettoyer le répertoire temporaire
        $this->removeDirectory($this->tempDir);
    }

    public function testCompleteReverseEngineeringProcess(): void
    {
        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'namespace'  => 'Test\\Entity',
        ]);

        // Assert - Vérifier le résultat
        $this->assertIsArray($result);
        $this->assertArrayHasKey('entities', $result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('tables_processed', $result);

        // Vérifier qu'on a traité les bonnes tables
        $this->assertEquals(3, $result['tables_processed']);
        $this->assertCount(3, $result['entities']);

        // Verify files were created
        $this->assertCount(6, $result['files']); // 3 entities + 3 repositories

        // Verify generated entity content
        $this->verifyGeneratedEntities($result);
    }

    public function testReverseEngineeringWithSpecificTables(): void
    {
        // Act - Générer seulement la table users
        $result = $this->service->generateEntities([
            'tables'     => ['users'],
            'output_dir' => $this->tempDir,
        ]);

        // Assert
        $this->assertEquals(1, $result['tables_processed']);
        $this->assertCount(1, $result['entities']);

        $entity = $result['entities'][0];
        $this->assertEquals('User', $entity['name']);
        $this->assertEquals('users', $entity['table']);
    }

    public function testReverseEngineeringWithExcludedTables(): void
    {
        // Act - Exclure la table logs
        $result = $this->service->generateEntities([
            'exclude'    => ['logs'],
            'output_dir' => $this->tempDir,
        ]);

        // Assert
        $this->assertEquals(2, $result['tables_processed']);

        $tableNames = array_column($result['entities'], 'table');
        $this->assertContains('users', $tableNames);
        $this->assertContains('posts', $tableNames);
        $this->assertNotContains('logs', $tableNames);
    }

    public function testReverseEngineeringWithDryRun(): void
    {
        // Act - Mode dry-run
        $result = $this->service->generateEntities([
            'dry_run' => true,
        ]);

        // Assert
        $this->assertEmpty($result['files']);
        $this->assertCount(3, $result['entities']);

        // Verify no files were created
        $files = glob($this->tempDir . '/*');
        $this->assertEmpty($files);
    }

    public function testReverseEngineeringWithRelations(): void
    {
        // Act
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
        ]);

        // Assert - Vérifier que les relations sont correctement détectées
        $postEntity = null;

        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'Post') {
                $postEntity = $entity;
                break;
            }
        }

        $this->assertNotNull($postEntity);
        $this->assertArrayHasKey('relations', $postEntity);
        $this->assertCount(1, $postEntity['relations']);

        $relation = $postEntity['relations'][0];
        $this->assertEquals('many_to_one', $relation['type']);
        $this->assertEquals('User', $relation['target_entity']);
        $this->assertEquals('user', $relation['property_name']);
    }

    public function testGeneratedEntityFileContent(): void
    {
        // Act
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'namespace'  => 'Test\\Entity',
        ]);

        // Assert - Verify User.php file content
        $userFile = $this->tempDir . '/User.php';
        $this->assertFileExists($userFile);

        $content = file_get_contents($userFile);
        $this->assertStringContains('<?php', $content);
        $this->assertStringContains('namespace Test\\Entity;', $content);
        $this->assertStringContains('class User', $content);
        $this->assertStringContains('private int $id;', $content);
        $this->assertStringContains('private string $email;', $content);
        $this->assertStringContains('public function getId()', $content);
        $this->assertStringContains('public function setEmail(', $content);
    }

    public function testGeneratedRepositoryFileContent(): void
    {
        // Act
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'namespace'  => 'Test\\Entity',
        ]);

        // Assert - Verify UserRepository.php file content
        $repositoryFile = $this->tempDir . '/UserRepository.php';
        $this->assertFileExists($repositoryFile);

        $content = file_get_contents($repositoryFile);
        $this->assertStringContains('<?php', $content);
        $this->assertStringContains('namespace Test\\Repository;', $content);
        $this->assertStringContains('class UserRepository', $content);
        $this->assertStringContains('use Test\\Entity\\User;', $content);
        $this->assertStringContains('extends ServiceEntityRepository', $content);
    }

    public function testDatabaseConnectionValidation(): void
    {
        // Act & Assert
        $this->assertTrue($this->service->validateDatabaseConnection());
    }

    public function testGetAvailableTables(): void
    {
        // Act
        $tables = $this->service->getAvailableTables();

        // Assert
        $this->assertIsArray($tables);
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
        $this->assertContains('logs', $tables);
        $this->assertCount(3, $tables);
    }

    public function testGetTableInfo(): void
    {
        // Act
        $tableInfo = $this->service->getTableInfo('users');

        // Assert
        $this->assertIsArray($tableInfo);
        $this->assertEquals('User', $tableInfo['entity_name']);
        $this->assertEquals('users', $tableInfo['table_name']);
        $this->assertArrayHasKey('columns', $tableInfo);
        $this->assertArrayHasKey('relations', $tableInfo);
        $this->assertArrayHasKey('primary_key', $tableInfo);
    }

    public function testReverseEngineeringWithForceOverwrite(): void
    {
        // Arrange - Create file first
        $userFile = $this->tempDir . '/User.php';
        file_put_contents($userFile, '<?php // Old content');

        // Act - Générer avec force
        $result = $this->service->generateEntities([
            'tables'     => ['users'],
            'output_dir' => $this->tempDir,
            'force'      => true,
        ]);

        // Assert
        $this->assertFileExists($userFile);
        $content = file_get_contents($userFile);
        $this->assertStringNotContains('// Old content', $content);
        $this->assertStringContains('class User', $content);
    }

    public function testReverseEngineeringWithComplexDataTypes(): void
    {
        // Arrange - Créer une table avec différents types de données
        $this->connection->executeStatement('
            CREATE TABLE complex_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price DECIMAL(10,2),
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                metadata TEXT,
                description TEXT
            )
        ');

        // Act
        $result = $this->service->generateEntities([
            'tables'     => ['complex_types'],
            'output_dir' => $this->tempDir,
        ]);

        // Assert
        $entity = $result['entities'][0];
        $this->assertEquals('ComplexType', $entity['name']);

        $propertyTypes = [];

        foreach ($entity['properties'] as $property) {
            $propertyTypes[$property['name']] = $property['type'];
        }

        $this->assertEquals('int', $propertyTypes['id']);
        $this->assertEquals('string', $propertyTypes['name']);
        $this->assertEquals('?string', $propertyTypes['price']); // DECIMAL mapped to string, nullable
        $this->assertEquals('bool', $propertyTypes['isActive']);
        $this->assertEquals('?\DateTimeInterface', $propertyTypes['createdAt']);
    }

    /**
     * Setup services for integration tests.
     */
    private function setupServices(): void
    {
        $databaseConfig = [
            'driver' => 'pdo_sqlite',
            'path'   => ':memory:',
        ];

        $databaseAnalyzer  = new DatabaseAnalyzer($databaseConfig, new \Psr\Log\NullLogger(), $this->connection);
        $metadataExtractor = new MetadataExtractor($databaseAnalyzer, new \Psr\Log\NullLogger());

        // Setup Twig with entity templates
        $loader = new ArrayLoader([
            'entity.php.twig'     => $this->getEntityTemplate(),
            'repository.php.twig' => $this->getRepositoryTemplate(),
        ]);
        $twig = new Environment($loader);

        // Create EnumClassGenerator for testing
        $enumClassGenerator = new EnumClassGenerator(
            sys_get_temp_dir(),
            new \Psr\Log\NullLogger(),
            ['enum_namespace' => 'App\\Enum'],
        );

        $entityGenerator = new EntityGenerator($twig, $enumClassGenerator, new \Psr\Log\NullLogger());
        $fileWriter      = new FileWriter($this->tempDir, new \Psr\Log\NullLogger());

        $this->service = new ReverseEngineeringService(
            $databaseAnalyzer,
            $metadataExtractor,
            $entityGenerator,
            $fileWriter,
            new \Psr\Log\NullLogger(),
        );
    }

    private function createTestTables(): void
    {
        // Table users
        $this->connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Table posts avec relation vers users
        $this->connection->executeStatement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT,
                user_id INTEGER NOT NULL,
                published_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');

        // Table logs simple
        $this->connection->executeStatement('
            CREATE TABLE logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                level TEXT DEFAULT "info",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    private function verifyGeneratedEntities(array $result): void
    {
        $entityNames = array_column($result['entities'], 'name');
        $this->assertContains('User', $entityNames);
        $this->assertContains('Post', $entityNames);
        $this->assertContains('Log', $entityNames);

        // Verify all files exist
        foreach ($result['files'] as $file) {
            $this->assertFileExists($file);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function getEntityTemplate(): string
    {
        return '<?php

declare(strict_types=1);

namespace {{ namespace }};

{% for import in imports %}
use {{ import }};
{% endfor %}

/**
 * Entity {{ entity_name }}.
 */
class {{ entity_name }}
{
{% for property in properties %}
    private {{ property.type }} ${{ property.name }};

{% endfor %}
{% for property in properties %}
    public function {{ property.getter_name }}(): {{ property.type }}
    {
        return $this->{{ property.name }};
    }

    public function {{ property.setter_name }}({{ property.type }} ${{ property.name }}): self
    {
        $this->{{ property.name }} = ${{ property.name }};
        return $this;
    }

{% endfor %}
}
';
    }

    private function getRepositoryTemplate(): string
    {
        return '<?php

declare(strict_types=1);

namespace {{ namespace }};

use {{ entity_namespace }}\{{ entity_name }};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for entity {{ entity_name }}.
 */
class {{ repository_name }} extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, {{ entity_name }}::class);
    }
}
';
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'",
        );
    }

    private function assertStringNotContains(string $needle, string $haystack): void
    {
        $this->assertFalse(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' does not contain '{$needle}'",
        );
    }
}
