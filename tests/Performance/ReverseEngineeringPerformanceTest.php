<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Performance;

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

use function count;

/**
 * Tests de performance pour le reverse engineering.
 */
class ReverseEngineeringPerformanceTest extends TestCase
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

        // Créer un répertoire temporaire
        $this->tempDir = sys_get_temp_dir() . '/perf_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        $this->setupServices();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPerformanceWithManyTables(): void
    {
        // Arrange - Créer 50 tables
        $tableCount = 50;
        $this->createManyTables($tableCount);

        // Act - Mesurer le temps d'exécution
        $startTime = microtime(true);

        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'dry_run'    => true, // Avoid file writing to focus on analysis
        ]);

        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertEquals($tableCount, $result['tables_processed']);
        $this->assertCount($tableCount, $result['entities']);

        // Le processus ne doit pas prendre plus de 10 secondes pour 50 tables
        $this->assertLessThan(
            10.0,
            $executionTime,
            "Le traitement de {$tableCount} tables a pris {$executionTime}s, ce qui est trop lent",
        );

        // Afficher les métriques de performance
        $this->addToAssertionCount(1); // Pour éviter les warnings sur les tests sans assertions
    }

    public function testPerformanceWithLargeTables(): void
    {
        // Arrange - Créer des tables avec beaucoup de colonnes
        $this->createLargeTable();

        // Act
        $startTime = microtime(true);

        $result = $this->service->generateEntities([
            'tables'     => ['large_table'],
            'output_dir' => $this->tempDir,
            'dry_run'    => true,
        ]);

        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertEquals(1, $result['tables_processed']);
        $entity = $result['entities'][0];

        // Vérifier qu'on a bien toutes les colonnes
        $this->assertGreaterThan(45, count($entity['properties'])); // 50 colonnes - quelques FK

        // Le traitement d'une grande table ne doit pas prendre plus de 2 secondes
        $this->assertLessThan(
            2.0,
            $executionTime,
            "Le traitement d'une grande table a pris {$executionTime}s, ce qui est trop lent",
        );
    }

    public function testPerformanceWithComplexRelations(): void
    {
        // Arrange - Créer des tables avec de nombreuses relations
        $this->createTablesWithComplexRelations();

        // Act
        $startTime = microtime(true);

        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'dry_run'    => true,
        ]);

        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertEquals(6, $result['tables_processed']); // 6 tables avec relations

        // Vérifier que les relations sont détectées
        $totalRelations = 0;

        foreach ($result['entities'] as $entity) {
            $totalRelations += count($entity['relations']);
        }
        $this->assertGreaterThan(5, $totalRelations);

        // Le traitement ne doit pas prendre plus de 3 secondes
        $this->assertLessThan(
            3.0,
            $executionTime,
            "Le traitement des relations complexes a pris {$executionTime}s, ce qui est trop lent",
        );
    }

    public function testMemoryUsageWithManyEntities(): void
    {
        // Arrange
        $this->createManyTables(30);

        // Act - Mesurer l'utilisation mémoire
        $memoryBefore = memory_get_usage(true);

        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'dry_run'    => true,
        ]);

        $memoryAfter = memory_get_usage(true);
        $memoryUsed  = $memoryAfter - $memoryBefore;

        // Assert
        $this->assertEquals(30, $result['tables_processed']);

        // L'utilisation mémoire ne doit pas dépasser 50MB
        $maxMemoryMB  = 50;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $this->assertLessThan(
            $maxMemoryMB,
            $memoryUsedMB,
            "L'utilisation mémoire ({$memoryUsedMB}MB) dépasse la limite de {$maxMemoryMB}MB",
        );
    }

    public function testFileGenerationPerformance(): void
    {
        // Arrange
        $this->createManyTables(20);

        // Act - Test real file generation
        $startTime = microtime(true);

        $result = $this->service->generateEntities([
            'output_dir' => $this->tempDir,
            'dry_run'    => false, // Real file generation
        ]);

        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertEquals(20, $result['tables_processed']);
        $this->assertCount(40, $result['files']); // 20 entities + 20 repositories

        // Verify all files exist
        foreach ($result['files'] as $file) {
            $this->assertFileExists($file);
        }

        // Generation of 40 files should not take more than 5 seconds
        $this->assertLessThan(
            5.0,
            $executionTime,
            "File generation took {$executionTime}s, which is too slow",
        );
    }

    public function testDatabaseAnalysisPerformance(): void
    {
        // Arrange
        $this->createManyTables(100);

        // Act - Test only database analysis
        $startTime = microtime(true);

        $tables = $this->service->getAvailableTables();

        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        // Assert
        $this->assertCount(100, $tables);

        // Analysis of 100 tables should not take more than 1 second
        $this->assertLessThan(
            1.0,
            $executionTime,
            "Database analysis took {$executionTime}s, which is too slow",
        );
    }

    /**
     * Setup services for performance testing.
     */
    private function setupServices(): void
    {
        $databaseConfig = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];

        // Pass existing connection to DatabaseAnalyzer to share same database
        $databaseAnalyzer  = new DatabaseAnalyzer($databaseConfig, new \Psr\Log\NullLogger(), $this->connection);
        $metadataExtractor = new MetadataExtractor($databaseAnalyzer, new \Psr\Log\NullLogger());

        // Template minimal pour les tests de performance
        $loader = new ArrayLoader([
            'entity.php.twig'     => '<?php class {{ entity_name }} { /* properties */ }',
            'repository.php.twig' => '<?php class {{ repository_name }} { /* repository */ }',
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

    private function createManyTables(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $tableName = "table_{$i}";
            $this->connection->executeStatement("
                CREATE TABLE {$tableName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME,
                    is_active BOOLEAN DEFAULT 1
                )
            ");
        }
    }

    private function createLargeTable(): void
    {
        $columns = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];

        // Créer 50 colonnes de différents types
        for ($i = 1; $i <= 50; ++$i) {
            $type = match ($i % 5) {
                0 => 'TEXT',
                1 => 'INTEGER',
                2 => 'DECIMAL(10,2)',
                3 => 'BOOLEAN',
                4 => 'DATETIME',
            };
            $columns[] = "column_{$i} {$type}";
        }

        $sql = 'CREATE TABLE large_table (' . implode(', ', $columns) . ')';
        $this->connection->executeStatement($sql);
    }

    private function createTablesWithComplexRelations(): void
    {
        // Table principale
        $this->connection->executeStatement('
            CREATE TABLE companies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE departments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                company_id INTEGER,
                FOREIGN KEY (company_id) REFERENCES companies(id)
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                department_id INTEGER,
                manager_id INTEGER,
                FOREIGN KEY (department_id) REFERENCES departments(id),
                FOREIGN KEY (manager_id) REFERENCES employees(id)
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                company_id INTEGER,
                FOREIGN KEY (company_id) REFERENCES companies(id)
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE employee_projects (
                employee_id INTEGER,
                project_id INTEGER,
                role TEXT,
                PRIMARY KEY (employee_id, project_id),
                FOREIGN KEY (employee_id) REFERENCES employees(id),
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                project_id INTEGER,
                assigned_to INTEGER,
                FOREIGN KEY (project_id) REFERENCES projects(id),
                FOREIGN KEY (assigned_to) REFERENCES employees(id)
            )
        ');
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
}
