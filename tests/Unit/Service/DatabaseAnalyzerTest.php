<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Eprofos\ReverseEngineeringBundle\Exception\DatabaseConnectionException;
use Eprofos\ReverseEngineeringBundle\Service\DatabaseAnalyzer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

use function count;

/**
 * Unit tests for DatabaseAnalyzer.
 */
class DatabaseAnalyzerTest extends TestCase
{
    private DatabaseAnalyzer $databaseAnalyzer;

    private array $databaseConfig;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->databaseConfig = [
            'driver' => 'pdo_sqlite',
            'path'   => ':memory:',
        ];

        // Create shared connection for all tests
        $this->connection       = DriverManager::getConnection($this->databaseConfig);
        $this->databaseAnalyzer = new DatabaseAnalyzer($this->databaseConfig, new NullLogger(), $this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testTestConnectionSuccess(): void
    {
        // Test with real SQLite in-memory connection
        $result = $this->databaseAnalyzer->testConnection();

        $this->assertTrue($result);
    }

    public function testTestConnectionFailure(): void
    {
        // Invalid configuration to force error
        $invalidConfig = [
            'driver'   => 'pdo_mysql',
            'host'     => 'invalid_host',
            'dbname'   => 'invalid_db',
            'user'     => 'invalid_user',
            'password' => 'invalid_password',
        ];

        $analyzer = new DatabaseAnalyzer($invalidConfig, new NullLogger());

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Database connection failed:');

        $analyzer->testConnection();
    }

    public function testListTablesWithRealDatabase(): void
    {
        // Créer une table de test
        $this->connection->executeStatement('CREATE TABLE test_users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE test_posts (id INTEGER PRIMARY KEY, title TEXT)');

        $tables = $this->databaseAnalyzer->listTables();

        $this->assertIsArray($tables);
        $this->assertContains('test_users', $tables);
        $this->assertContains('test_posts', $tables);

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE test_users');
        $this->connection->executeStatement('DROP TABLE test_posts');
    }

    public function testListTablesFiltersSystemTables(): void
    {
        $tables = $this->databaseAnalyzer->listTables();

        // Vérifier qu'aucune table système n'est retournée
        $this->assertIsArray($tables);

        foreach ($tables as $table) {
            $this->assertFalse(str_starts_with($table, 'sqlite_'));
            $this->assertFalse(str_starts_with($table, 'information_schema'));
            $this->assertFalse(str_starts_with($table, 'performance_schema'));
        }
    }

    public function testAnalyzeTablesWithIncludeFilter(): void
    {
        // Créer des tables de test
        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE comments (id INTEGER PRIMARY KEY)');

        // Tester avec filtre d'inclusion
        $result = $this->databaseAnalyzer->analyzeTables(['users', 'posts']);

        $this->assertCount(2, $result);
        $this->assertContains('users', $result);
        $this->assertContains('posts', $result);
        $this->assertNotContains('comments', $result);

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE users');
        $this->connection->executeStatement('DROP TABLE posts');
        $this->connection->executeStatement('DROP TABLE comments');
    }

    public function testAnalyzeTablesWithExcludeFilter(): void
    {
        // Créer des tables de test
        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE temp_table (id INTEGER PRIMARY KEY)');

        // Tester avec filtre d'exclusion
        $result = $this->databaseAnalyzer->analyzeTables([], ['temp_table']);

        $this->assertContains('users', $result);
        $this->assertContains('posts', $result);
        $this->assertNotContains('temp_table', $result);

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE users');
        $this->connection->executeStatement('DROP TABLE posts');
        $this->connection->executeStatement('DROP TABLE temp_table');
    }

    public function testGetTableDetailsWithComplexTable(): void
    {
        // Create a complex table with different column types
        $sql = '
            CREATE TABLE complex_table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                age INTEGER,
                salary DECIMAL(10,2),
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                description TEXT
            )
        ';
        $this->connection->executeStatement($sql);

        // Créer un index
        $this->connection->executeStatement('CREATE INDEX idx_email ON complex_table(email)');

        $details = $this->databaseAnalyzer->getTableDetails('complex_table');

        // Vérifier la structure de base
        $this->assertEquals('complex_table', $details['name']);
        $this->assertIsArray($details['columns']);
        $this->assertIsArray($details['indexes']);
        $this->assertIsArray($details['foreign_keys']);
        $this->assertIsArray($details['primary_key']);

        // Vérifier les colonnes
        $this->assertGreaterThan(0, count($details['columns']));

        // Vérifier qu'on a bien la clé primaire
        $this->assertContains('id', $details['primary_key']);

        // Vérifier les index
        $this->assertGreaterThan(0, count($details['indexes']));

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE complex_table');
    }

    public function testGetTableDetailsWithForeignKeys(): void
    {
        // Créer des tables avec clés étrangères
        $this->connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');

        $details = $this->databaseAnalyzer->getTableDetails('posts');

        // Vérifier les clés étrangères
        $this->assertIsArray($details['foreign_keys']);

        if (! empty($details['foreign_keys'])) {
            $fk = $details['foreign_keys'][0];
            $this->assertArrayHasKey('name', $fk);
            $this->assertArrayHasKey('local_columns', $fk);
            $this->assertArrayHasKey('foreign_table', $fk);
            $this->assertArrayHasKey('foreign_columns', $fk);
            $this->assertEquals('users', $fk['foreign_table']);
            $this->assertContains('user_id', $fk['local_columns']);
        }

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE posts');
        $this->connection->executeStatement('DROP TABLE users');
    }

    public function testGetTableDetailsThrowsExceptionForInvalidTable(): void
    {
        $analyzer = new DatabaseAnalyzer($this->databaseConfig, new NullLogger());

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage("Failed to analyze table 'non_existent_table':");

        $analyzer->getTableDetails('non_existent_table');
    }

    public function testIsUserTableFiltersSystemTables(): void
    {
        $analyzer = new DatabaseAnalyzer($this->databaseConfig, new NullLogger());

        // Utiliser la réflexion pour tester la méthode privée
        $reflection = new ReflectionClass($analyzer);
        $method     = $reflection->getMethod('isUserTable');
        $method->setAccessible(true);

        // Tables utilisateur
        $this->assertTrue($method->invoke($analyzer, 'users'));
        $this->assertTrue($method->invoke($analyzer, 'posts'));
        $this->assertTrue($method->invoke($analyzer, 'my_custom_table'));

        // Tables système MySQL
        $this->assertFalse($method->invoke($analyzer, 'information_schema_tables'));
        $this->assertFalse($method->invoke($analyzer, 'performance_schema_events'));
        $this->assertFalse($method->invoke($analyzer, 'mysql_user'));
        $this->assertFalse($method->invoke($analyzer, 'sys_config'));

        // Tables système PostgreSQL
        $this->assertFalse($method->invoke($analyzer, 'pg_catalog_tables'));

        // Tables système SQLite
        $this->assertFalse($method->invoke($analyzer, 'sqlite_master'));
        $this->assertFalse($method->invoke($analyzer, 'sqlite_sequence'));
    }

    public function testGetColumnsInfoExtractsCorrectInformation(): void
    {
        // Créer une table avec différents types de colonnes
        $this->connection->executeStatement('
            CREATE TABLE test_columns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                age INTEGER DEFAULT 0,
                description TEXT
            )
        ');

        $details = $this->databaseAnalyzer->getTableDetails('test_columns');

        $columns = $details['columns'];
        $this->assertIsArray($columns);
        $this->assertGreaterThan(0, count($columns));

        // Vérifier la structure des colonnes
        foreach ($columns as $column) {
            $this->assertArrayHasKey('name', $column);
            $this->assertArrayHasKey('type', $column);
            $this->assertArrayHasKey('nullable', $column);
            $this->assertArrayHasKey('default', $column);
            $this->assertArrayHasKey('auto_increment', $column);
            $this->assertArrayHasKey('comment', $column);
        }

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE test_columns');
    }

    public function testListTablesThrowsExceptionOnConnectionError(): void
    {
        // Invalid configuration
        $invalidConfig = [
            'driver' => 'pdo_mysql',
            'host'   => 'invalid_host',
            'dbname' => 'invalid_db',
        ];

        $analyzer = new DatabaseAnalyzer($invalidConfig, new NullLogger());

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Failed to retrieve tables:');

        $analyzer->listTables();
    }

    public function testAnalyzeTablesReturnsEmptyArrayWhenNoTablesMatch(): void
    {
        $analyzer = new DatabaseAnalyzer($this->databaseConfig, new NullLogger());

        // Demander des tables qui n'existent pas
        $result = $analyzer->analyzeTables(['non_existent_table']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetTableDetailsWithIndexes(): void
    {
        // Créer une table avec plusieurs index
        $this->connection->executeStatement('
            CREATE TABLE indexed_table (
                id INTEGER PRIMARY KEY,
                email TEXT UNIQUE,
                name TEXT,
                category_id INTEGER
            )
        ');

        $this->connection->executeStatement('CREATE INDEX idx_name ON indexed_table(name)');
        $this->connection->executeStatement('CREATE INDEX idx_category ON indexed_table(category_id)');

        $details = $this->databaseAnalyzer->getTableDetails('indexed_table');

        $indexes = $details['indexes'];
        $this->assertIsArray($indexes);
        $this->assertGreaterThan(0, count($indexes));

        // Vérifier la structure des index
        foreach ($indexes as $index) {
            $this->assertArrayHasKey('name', $index);
            $this->assertArrayHasKey('columns', $index);
            $this->assertArrayHasKey('unique', $index);
            $this->assertArrayHasKey('primary', $index);
        }

        // Nettoyer
        $this->connection->executeStatement('DROP TABLE indexed_table');
    }
}
