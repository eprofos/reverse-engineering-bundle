<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests;

use BadMethodCallException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function array_key_exists;
use function is_array;

/**
 * Utility class for tests.
 */
class TestHelper
{
    /**
     * Creates an in-memory SQLite connection for tests.
     */
    public static function createInMemoryDatabase(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    /**
     * Creates a temporary directory for tests.
     */
    public static function createTempDirectory(string $prefix = 'test_'): string
    {
        $tempDir = sys_get_temp_dir() . '/' . $prefix . uniqid();
        mkdir($tempDir, 0o755, true);

        return $tempDir;
    }

    /**
     * Recursively deletes a directory.
     */
    public static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Creates standard test tables.
     */
    public static function createStandardTestTables(Connection $connection): void
    {
        // users table
        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // posts table with relation to users
        $connection->executeStatement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT,
                user_id INTEGER NOT NULL,
                published_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');

        // comments table with relations
        $connection->executeStatement('
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT NOT NULL,
                post_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');
    }

    /**
     * Returns a simple Twig template for tests.
     */
    public static function getSimpleEntityTemplate(): string
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

    /**
     * Checks if a string contains another string.
     */
    public static function assertStringContains(string $needle, string $haystack, string $message = ''): bool
    {
        return str_contains($haystack, $needle);
    }

    /**
     * Generates test data for metadata.
     */
    public static function generateTestMetadata(string $tableName, string $entityName): array
    {
        return [
            'entity_name'     => $entityName,
            'table_name'      => $tableName,
            'repository_name' => $entityName . 'Repository',
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
                    'name'           => 'name',
                    'property_name'  => 'name',
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
    }

    /**
     * Creates a test database configuration.
     */
    public static function getTestDatabaseConfig(): array
    {
        return [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];
    }

    /**
     * Measures the execution time of a function.
     */
    public static function measureExecutionTime(callable $callback): array
    {
        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        $result = $callback();

        $endTime   = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'result'         => $result,
            'execution_time' => $endTime - $startTime,
            'memory_used'    => $endMemory - $startMemory,
            'peak_memory'    => memory_get_peak_usage(true),
        ];
    }

    /**
     * Validates the structure of a generated entity.
     */
    public static function validateEntityStructure(array $entity): array
    {
        $errors = [];

        $requiredKeys = ['name', 'table', 'namespace', 'filename', 'code', 'properties', 'relations'];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $entity)) {
                $errors[] = "Missing key: {$key}";
            }
        }

        if (isset($entity['properties']) && ! is_array($entity['properties'])) {
            $errors[] = 'Properties must be an array';
        }

        if (isset($entity['relations']) && ! is_array($entity['relations'])) {
            $errors[] = 'Relations must be an array';
        }

        return $errors;
    }

    /**
     * Creates test data for a relation.
     */
    public static function createTestRelation(
        string $type = 'many_to_one',
        string $propertyName = 'user',
        string $targetEntity = 'User',
    ): array {
        return [
            'type'            => $type,
            'property_name'   => $propertyName,
            'target_entity'   => $targetEntity,
            'target_table'    => strtolower($targetEntity) . 's',
            'local_columns'   => [$propertyName . '_id'],
            'foreign_columns' => ['id'],
            'on_delete'       => 'CASCADE',
            'on_update'       => null,
            'nullable'        => false,
        ];
    }

    /**
     * Generates a unique temporary file name.
     */
    public static function generateTempFileName(string $extension = 'php'): string
    {
        return tempnam(sys_get_temp_dir(), 'test_') . '.' . $extension;
    }

    /**
     * Checks if a PHP file is syntactically valid.
     */
    public static function isValidPhpSyntax(string $phpCode): bool
    {
        $tempFile = self::generateTempFileName();
        file_put_contents($tempFile, $phpCode);

        $output     = [];
        $returnCode = 0;
        exec("php -l {$tempFile} 2>&1", $output, $returnCode);

        unlink($tempFile);

        return $returnCode === 0;
    }

    /**
     * Extracts class names from PHP code.
     */
    public static function extractClassNames(string $phpCode): array
    {
        preg_match_all('/class\s+(\w+)/', $phpCode, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Extracts method names from PHP code.
     */
    public static function extractMethodNames(string $phpCode): array
    {
        preg_match_all('/(?:public|private|protected)\s+function\s+(\w+)/', $phpCode, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Creates a service mock with predefined methods.
     */
    public static function createServiceMock(string $className, array $methods = []): object
    {
        return new class($className, $methods) {
            private string $className;

            private array $methods;

            public function __construct(string $className, array $methods)
            {
                $this->className = $className;
                $this->methods   = $methods;
            }

            public function __call(string $name, array $arguments)
            {
                if (isset($this->methods[$name])) {
                    return $this->methods[$name];
                }

                throw new BadMethodCallException("Method {$name} not found in mock {$this->className}");
            }
        };
    }
}
