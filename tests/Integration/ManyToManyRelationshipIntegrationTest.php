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
 * Integration tests for ManyToMany relationship detection and generation.
 *
 * This test class validates the complete ManyToMany relationship implementation
 * including junction table detection, relationship extraction, and entity generation.
 */
class ManyToManyRelationshipIntegrationTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . '/reverse_engineering_manytomany_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        // Setup services
        $this->setupServices();

        // Create test tables with ManyToMany relationships
        $this->createManyToManyTestTables();
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        $this->removeDirectory($this->tempDir);
    }

    public function testSimpleManyToManyRelationshipDetection(): void
    {
        // Act - Generate entities for only the simple relationship tables
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'namespace'  => 'Test\\Entity',
            'tables'     => ['users', 'roles', 'user_roles'],  // Only process these tables
        ]);

        // Assert - Verify junction table was identified and skipped
        $this->assertIsArray($result);
        $this->assertArrayHasKey('entities', $result);
        
        // Should generate 2 entities (User, Role) but not the junction table (user_roles)
        $entityNames = array_column($result['entities'], 'name');
        $this->assertCount(2, $result['entities']);
        
        $this->assertContains('User', $entityNames);
        $this->assertContains('Role', $entityNames);
        $this->assertNotContains('UserRole', $entityNames);

        // Verify ManyToMany relationships are detected
        $this->verifyManyToManyRelationships($result);
    }

    public function testManyToManyRelationshipCodeGeneration(): void
    {
        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'namespace'  => 'Test\\Entity',
        ]);

        // Assert - Verify generated entity files contain ManyToMany relationships
        $userFile = $this->tempDir . '/User.php';
        $this->assertFileExists($userFile);

        $userContent = file_get_contents($userFile);
        
        // Verify Collection property
        $this->assertStringContains('private Collection $roles;', $userContent);
        
        // Verify inverse ManyToMany attribute on User (mappedBy)
        $this->assertStringContains("#[ORM\ManyToMany(targetEntity: Role::class, mappedBy: 'users')]", $userContent);
        
        // Verify collection methods
        $this->assertStringContains('public function getRoles(): Collection', $userContent);
        $this->assertStringContains('public function addRole(Role $role): static', $userContent);
        $this->assertStringContains('public function removeRole(Role $role): static', $userContent);
        
        // Verify collection initialization in constructor
        $this->assertStringContains('$this->roles = new ArrayCollection();', $userContent);

        // Check Role entity (owning side)
        $roleFile = $this->tempDir . '/Role.php';
        $this->assertFileExists($roleFile);
        
        $roleContent = file_get_contents($roleFile);
        
        // Verify Collection property
        $this->assertStringContains('private Collection $users;', $roleContent);
        
        // Verify owning ManyToMany attribute on Role with inversedBy
        $this->assertStringContains("#[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'roles')]", $roleContent);
        $this->assertStringContains("#[ORM\JoinTable(name: 'user_roles')]", $roleContent);
        
        // Verify collection methods
        $this->assertStringContains('public function getUsers(): Collection', $roleContent);
        $this->assertStringContains('public function addUser(User $user): static', $roleContent);
        $this->assertStringContains('public function removeUser(User $user): static', $roleContent);
    }

    public function testJunctionTableWithMetadataDetection(): void
    {
        // Arrange - Create junction table with additional metadata
        $this->connection->executeStatement('
            CREATE TABLE user_project_assignments (
                user_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                assigned_by INTEGER,
                PRIMARY KEY (user_id, project_id),
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (project_id) REFERENCES projects(id),
                FOREIGN KEY (assigned_by) REFERENCES users(id)
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT
            )
        ');

        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'tables' => ['users', 'projects', 'user_project_assignments']
        ]);

        // Assert - For junction tables with metadata, behavior depends on configuration
        // With default settings, it should still be treated as junction table due to metadata threshold
        $entityNames = array_column($result['entities'], 'name');
        
        // Should still detect as junction table and skip entity generation
        $this->assertNotContains('UserProjectAssignment', $entityNames);
        
        // Should generate ManyToMany relationships instead
        $userEntity = null;
        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'User') {
                $userEntity = $entity;
                break;
            }
        }
        
        $this->assertNotNull($userEntity);
        $this->assertArrayHasKey('relations', $userEntity);
        
        // Should have ManyToMany relation to projects
        $manyToManyRelations = array_filter($userEntity['relations'], fn($r) => $r['type'] === 'many_to_many');
        $this->assertNotEmpty($manyToManyRelations);
    }

    public function testSelfReferencingManyToManyRelationship(): void
    {
        // Arrange - Create self-referencing ManyToMany (e.g., user friendships)
        $this->connection->executeStatement('
            CREATE TABLE user_friends (
                user_id INTEGER NOT NULL,
                friend_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, friend_id),
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (friend_id) REFERENCES users(id)
            )
        ');

        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'tables' => ['users', 'user_friends']
        ]);

        // Assert - Should handle self-referencing relationships correctly
        $entityNames = array_column($result['entities'], 'name');
        $this->assertContains('User', $entityNames);
        $this->assertNotContains('UserFriend', $entityNames);

        $userEntity = null;
        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'User') {
                $userEntity = $entity;
                break;
            }
        }

        $this->assertNotNull($userEntity);
        
        // Should have self-referencing ManyToMany relationship
        $manyToManyRelations = array_filter($userEntity['relations'], fn($r) => $r['type'] === 'many_to_many');
        $this->assertNotEmpty($manyToManyRelations);
        
        $selfReferencing = array_filter($manyToManyRelations, fn($r) => $r['target_entity'] === 'User');
        $this->assertNotEmpty($selfReferencing);
    }

    public function testMultipleManyToManyRelationships(): void
    {
        // Arrange - Create multiple ManyToMany relationships
        // Check if permissions table already exists
        $hasPermissionsTable = false;
        try {
            $this->connection->executeQuery("SELECT 1 FROM permissions LIMIT 1");
            $hasPermissionsTable = true;
        } catch (\Exception $e) {
            // Table doesn't exist, we'll create it
        }

        if (!$hasPermissionsTable) {
            $this->connection->executeStatement('
                CREATE TABLE permissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT
                )
            ');

            $this->connection->executeStatement('
                CREATE TABLE role_permissions (
                    role_id INTEGER NOT NULL,
                    permission_id INTEGER NOT NULL,
                    PRIMARY KEY (role_id, permission_id),
                    FOREIGN KEY (role_id) REFERENCES roles(id),
                    FOREIGN KEY (permission_id) REFERENCES permissions(id)
                )
            ');
        }

        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
        ]);

        // Assert - Should detect both ManyToMany relationships
        $roleEntity = null;
        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'Role') {
                $roleEntity = $entity;
                break;
            }
        }

        $this->assertNotNull($roleEntity);
        $this->assertArrayHasKey('relations', $roleEntity);

        $manyToManyRelations = array_filter($roleEntity['relations'], fn($r) => $r['type'] === 'many_to_many');
        
        // Should have 2 ManyToMany relationships: users and permissions
        $this->assertCount(2, $manyToManyRelations);
        
        $targetEntities = array_column($manyToManyRelations, 'target_entity');
        $this->assertContains('User', $targetEntities);
        $this->assertContains('Permission', $targetEntities);
    }

    public function testOwningSideVsInverseSideDetection(): void
    {
        // Act - Generate entities
        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
        ]);

        // Assert - Verify owning/inverse side detection (alphabetically first table is owning side)
        $userEntity = null;
        $roleEntity = null;
        
        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'User') {
                $userEntity = $entity;
            } elseif ($entity['name'] === 'Role') {
                $roleEntity = $entity;
            }
        }

        $this->assertNotNull($userEntity);
        $this->assertNotNull($roleEntity);

        // Find ManyToMany relationships
        $userManyToMany = array_filter($userEntity['relations'], fn($r) => $r['type'] === 'many_to_many' && $r['target_entity'] === 'Role');
        $roleManyToMany = array_filter($roleEntity['relations'], fn($r) => $r['type'] === 'many_to_many' && $r['target_entity'] === 'User');

        $this->assertNotEmpty($userManyToMany);
        $this->assertNotEmpty($roleManyToMany);

        $userRelation = array_values($userManyToMany)[0];
        $roleRelation = array_values($roleManyToMany)[0];

        // Role comes alphabetically before User, so Role should be owning side
        $this->assertFalse($userRelation['is_owning_side'], 'User should be inverse side');
        $this->assertTrue($roleRelation['is_owning_side'], 'Role should be owning side');

        // Verify attributes
        $this->assertArrayHasKey('mapped_by', $userRelation);
        $this->assertArrayHasKey('junction_table', $roleRelation);
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

    /**
     * Create test tables for ManyToMany relationship testing.
     */
    private function createManyToManyTestTables(): void
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

        // Table roles
        $this->connection->executeStatement('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT
            )
        ');

        // Junction table for ManyToMany relationship
        $this->connection->executeStatement('
            CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            )
        ');

        // Additional table for multiple ManyToMany testing
        $this->connection->executeStatement('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT
            )
        ');

        // Another junction table
        $this->connection->executeStatement('
            CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )
        ');
    }

    /**
     * Verify that ManyToMany relationships are properly detected.
     */
    private function verifyManyToManyRelationships(array $result): void
    {
        $userEntity = null;
        $roleEntity = null;

        foreach ($result['entities'] as $entity) {
            if ($entity['name'] === 'User') {
                $userEntity = $entity;
            } elseif ($entity['name'] === 'Role') {
                $roleEntity = $entity;
            }
        }

        $this->assertNotNull($userEntity, 'User entity should be generated');
        $this->assertNotNull($roleEntity, 'Role entity should be generated');

        // Verify User has ManyToMany relation to Role
        $this->assertArrayHasKey('relations', $userEntity);
        $userManyToManyRelations = array_filter($userEntity['relations'], fn($r) => $r['type'] === 'many_to_many');
        $this->assertNotEmpty($userManyToManyRelations, 'User should have ManyToMany relations');

        // Verify Role has ManyToMany relation to User
        $this->assertArrayHasKey('relations', $roleEntity);
        $roleManyToManyRelations = array_filter($roleEntity['relations'], fn($r) => $r['type'] === 'many_to_many');
        $this->assertNotEmpty($roleManyToManyRelations, 'Role should have ManyToMany relations');

        // Verify relationship properties
        $userToRoleRelation = array_values($userManyToManyRelations)[0];
        $this->assertEquals('roles', $userToRoleRelation['property_name']);
        $this->assertEquals('Role', $userToRoleRelation['target_entity']);
        $this->assertEquals('user_roles', $userToRoleRelation['junction_table']);

        $roleToUserRelation = array_values($roleManyToManyRelations)[0];
        $this->assertEquals('users', $roleToUserRelation['property_name']);
        $this->assertEquals('User', $roleToUserRelation['target_entity']);
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
        // Use the actual entity template content
        return file_get_contents(__DIR__ . '/../../src/Resources/templates/entity.php.twig');
    }

    private function getRepositoryTemplate(): string
    {
        // Use the actual repository template content
        return file_get_contents(__DIR__ . '/../../src/Resources/templates/repository.php.twig');
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that string contains '{$needle}'"
        );
    }
}
