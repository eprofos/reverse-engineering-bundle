<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Eprofos\ReverseEngineeringBundle\Exception\DatabaseConnectionException;
use Exception;
use Psr\Log\LoggerInterface;

use function count;

/**
 * Service for analyzing database structure and providing database introspection capabilities.
 *
 * This service handles database connection management, table discovery, and extraction
 * of detailed table metadata including columns, indexes, foreign key relationships,
 * and table constraints. It provides comprehensive database analysis features
 * required for reverse engineering operations.
 */
class DatabaseAnalyzer
{
    /**
     * Database connection instance.
     */
    private ?Connection $connection = null;

    /**
     * DatabaseAnalyzer constructor.
     *
     * @param array           $databaseConfig Database configuration parameters
     * @param LoggerInterface $logger         Logger instance for operation tracking
     * @param Connection|null $connection     Optional pre-configured database connection
     */
    public function __construct(
        private readonly array $databaseConfig,
        private readonly LoggerInterface $logger,
        ?Connection $connection = null,
    ) {
        $this->connection = $connection;
        $this->logger->info('DatabaseAnalyzer initialized', [
            'has_connection' => $connection !== null,
            'config_keys'    => array_keys($databaseConfig),
        ]);
    }

    /**
     * Tests database connection and validates connectivity.
     *
     * This method attempts to establish a connection to the database
     * and verifies that the connection is active and responsive.
     *
     * @throws DatabaseConnectionException When connection fails or is invalid
     *
     * @return bool True if connection is successful, false otherwise
     */
    public function testConnection(): bool
    {
        $this->logger->info('Testing database connection');

        try {
            $connection = $this->getConnection();
            $this->logger->debug('Attempting to connect to database', [
                'driver' => $this->databaseConfig['driver'] ?? 'unknown',
                'host'   => $this->databaseConfig['host'] ?? 'unknown',
            ]);

            $connection->connect();
            $isConnected = $connection->isConnected();

            if ($isConnected) {
                $this->logger->info('Database connection successful');
            } else {
                $this->logger->warning('Database connection failed - not connected');
            }

            return $isConnected;
        } catch (Exception $e) {
            $this->logger->error('Database connection test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new DatabaseConnectionException(
                'Database connection failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Retrieves the list of all database tables, excluding system tables.
     *
     * This method connects to the database and extracts all user-defined tables,
     * filtering out system and metadata tables that are not relevant for
     * entity generation.
     *
     * @throws DatabaseConnectionException When table retrieval fails
     *
     * @return array List of user table names
     */
    public function listTables(): array
    {
        $this->logger->info('Starting to list database tables');

        try {
            $connection    = $this->getConnection();
            $schemaManager = $connection->createSchemaManager();

            $this->logger->debug('Retrieving table names from schema manager');
            $tables = $schemaManager->listTableNames();

            $this->logger->info('Retrieved raw table list', [
                'total_tables' => count($tables),
                'tables'       => $tables,
            ]);

            // Filter system tables based on database type
            $userTables = array_filter($tables, [$this, 'isUserTable']);

            $this->logger->info('Filtered user tables', [
                'user_tables_count' => count($userTables),
                'user_tables'       => array_values($userTables),
            ]);

            return array_values($userTables);
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve database tables', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new DatabaseConnectionException(
                'Failed to retrieve tables: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Analyzes specified tables or all tables based on include/exclude filters.
     *
     * This method provides flexible table selection by allowing users to specify
     * which tables to include or exclude from the analysis process. If no
     * include list is provided, all user tables are analyzed.
     *
     * @param array $includeTables List of tables to include (empty means all tables)
     * @param array $excludeTables List of tables to exclude from analysis
     *
     * @throws DatabaseConnectionException When table listing fails
     *
     * @return array Filtered list of table names to analyze
     */
    public function analyzeTables(array $includeTables = [], array $excludeTables = []): array
    {
        $this->logger->info('Starting table analysis', [
            'include_tables' => $includeTables,
            'exclude_tables' => $excludeTables,
        ]);

        $allTables = $this->listTables();
        $this->logger->debug('Retrieved all available tables', [
            'all_tables_count' => count($allTables),
        ]);

        // If specific tables are requested
        if (! empty($includeTables)) {
            $tables = array_intersect($allTables, $includeTables);
            $this->logger->info('Filtered tables by include list', [
                'filtered_count' => count($tables),
                'missing_tables' => array_diff($includeTables, $allTables),
            ]);
        } else {
            $tables = $allTables;
            $this->logger->debug('Using all available tables for analysis');
        }

        // Exclude specified tables
        if (! empty($excludeTables)) {
            $beforeCount = count($tables);
            $tables      = array_diff($tables, $excludeTables);
            $this->logger->info('Applied exclude filter', [
                'before_count'    => $beforeCount,
                'after_count'     => count($tables),
                'excluded_tables' => array_intersect($excludeTables, $allTables),
            ]);
        }

        $finalTables = array_values($tables);
        $this->logger->info('Table analysis completed', [
            'final_table_count' => count($finalTables),
            'final_tables'      => $finalTables,
        ]);

        return $finalTables;
    }

    /**
     * Retrieves detailed information about a specific database table.
     *
     * This method extracts comprehensive metadata for a table including
     * column definitions, indexes, foreign key relationships, and primary keys.
     * It handles special MySQL types (ENUM, SET, GEOMETRY) gracefully.
     *
     * @param string $tableName Name of the table to analyze
     *
     * @throws DatabaseConnectionException When table analysis fails
     *
     * @return array Comprehensive table metadata structure
     */
    public function getTableDetails(string $tableName): array
    {
        $this->logger->info('Starting detailed table analysis', [
            'table_name' => $tableName,
        ]);

        try {
            $connection    = $this->getConnection();
            $schemaManager = $connection->createSchemaManager();

            $this->logger->debug('Attempting standard Doctrine table introspection', [
                'table' => $tableName,
            ]);

            // Try first with standard SchemaManager
            try {
                $table = $schemaManager->introspectTable($tableName);

                $tableDetails = [
                    'name'         => $table->getName(),
                    'columns'      => $this->getColumnsInfo($table),
                    'indexes'      => $this->getIndexesInfo($table),
                    'foreign_keys' => $this->getForeignKeysInfo($table),
                    'primary_key'  => $table->getPrimaryKey()?->getColumns() ?? [],
                ];

                $this->logger->info('Table analysis completed successfully', [
                    'table_name'         => $tableName,
                    'columns_count'      => count($tableDetails['columns']),
                    'indexes_count'      => count($tableDetails['indexes']),
                    'foreign_keys_count' => count($tableDetails['foreign_keys']),
                    'has_primary_key'    => ! empty($tableDetails['primary_key']),
                ]);

                return $tableDetails;
            } catch (\Doctrine\DBAL\Exception $doctrineException) {
                $this->logger->warning('Standard introspection failed, using fallback method', [
                    'table' => $tableName,
                    'error' => $doctrineException->getMessage(),
                ]);

                // If Doctrine fails due to ENUM/SET/GEOMETRY types, use our fallback method
                if (str_contains($doctrineException->getMessage(), 'Unknown database type enum')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type set')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type geometry')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type point')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type polygon')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type linestring')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type multipoint')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type multilinestring')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type multipolygon')
                    || str_contains($doctrineException->getMessage(), 'Unknown database type geometrycollection')) {
                    return $this->getTableDetailsWithFallback($tableName);
                }

                throw $doctrineException;
            }
        } catch (Exception $e) {
            throw new DatabaseConnectionException(
                "Failed to analyze table '{$tableName}': " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Gets or creates database connection.
     *
     * @throws DatabaseConnectionException
     */
    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            try {
                // Register custom MySQL types
                MySQLTypeMapper::registerCustomTypes();

                $this->connection = DriverManager::getConnection($this->databaseConfig);

                // Configure platform for MySQL types
                $platform = $this->connection->getDatabasePlatform();
                MySQLTypeMapper::configurePlatform($platform);
            } catch (Exception $e) {
                throw new DatabaseConnectionException(
                    'Database connection creation failed: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        return $this->connection;
    }

    /**
     * Checks if a table is a user table (not system).
     */
    private function isUserTable(string $tableName): bool
    {
        $systemTables = [
            // MySQL
            'information_schema',
            'performance_schema',
            'mysql',
            'sys',
            // PostgreSQL
            'pg_catalog',
            'information_schema',
            // SQLite
            'sqlite_master',
            'sqlite_sequence',
        ];

        foreach ($systemTables as $systemTable) {
            if (str_starts_with($tableName, $systemTable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extracts column information.
     */
    private function getColumnsInfo(\Doctrine\DBAL\Schema\Table $table): array
    {
        $columns = [];

        // Get detailed column information via SHOW COLUMNS
        $detailedColumns = $this->getDetailedColumnInfo($table->getName());

        foreach ($table->getColumns() as $column) {
            $columnName   = $column->getName();
            $detailedInfo = $detailedColumns[$columnName] ?? [];

            $columns[] = [
                'name'           => $columnName,
                'type'           => $column->getType()->getName(),
                'raw_type'       => $detailedInfo['Type'] ?? $column->getType()->getName(),
                'length'         => $column->getLength(),
                'precision'      => $column->getPrecision(),
                'scale'          => $column->getScale(),
                'nullable'       => ! $column->getNotnull(),
                'default'        => $column->getDefault(),
                'auto_increment' => $column->getAutoincrement(),
                'comment'        => $column->getComment(),
                'enum_values'    => $detailedInfo['enum_values'] ?? null,
                'set_values'     => $detailedInfo['set_values'] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Extracts index information.
     */
    private function getIndexesInfo(\Doctrine\DBAL\Schema\Table $table): array
    {
        $indexes = [];

        foreach ($table->getIndexes() as $index) {
            $indexes[] = [
                'name'    => $index->getName(),
                'columns' => $index->getColumns(),
                'unique'  => $index->isUnique(),
                'primary' => $index->isPrimary(),
            ];
        }

        return $indexes;
    }

    /**
     * Extracts foreign key information.
     */
    private function getForeignKeysInfo(\Doctrine\DBAL\Schema\Table $table): array
    {
        $foreignKeys = [];

        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = [
                'name'            => $foreignKey->getName(),
                'local_columns'   => $foreignKey->getLocalColumns(),
                'foreign_table'   => $foreignKey->getForeignTableName(),
                'foreign_columns' => $foreignKey->getForeignColumns(),
                'on_update'       => $foreignKey->onUpdate() ?? 'RESTRICT',
                'on_delete'       => $foreignKey->onDelete() ?? 'RESTRICT',
            ];
        }

        // If no foreign keys found via Doctrine, try fallback method
        if (empty($foreignKeys)) {
            $foreignKeys = $this->getForeignKeysWithFallback($table->getName());
        }

        return $foreignKeys;
    }

    /**
     * Gets detailed column information via SHOW COLUMNS.
     */
    private function getDetailedColumnInfo(string $tableName): array
    {
        try {
            $connection = $this->getConnection();
            $sql        = "SHOW COLUMNS FROM `{$tableName}`";
            $result     = $connection->executeQuery($sql);

            $columns = [];

            while ($row = $result->fetchAssociative()) {
                $columnInfo = [
                    'Field'   => $row['Field'],
                    'Type'    => $row['Type'],
                    'Null'    => $row['Null'],
                    'Key'     => $row['Key'],
                    'Default' => $row['Default'],
                    'Extra'   => $row['Extra'],
                ];

                // Extract ENUM/SET values
                if (preg_match('/^enum\((.+)\)$/i', $row['Type'], $matches)) {
                    $columnInfo['enum_values'] = MySQLTypeMapper::extractEnumValues($row['Type']);
                } elseif (preg_match('/^set\((.+)\)$/i', $row['Type'], $matches)) {
                    $columnInfo['set_values'] = MySQLTypeMapper::extractSetValues($row['Type']);
                }

                $columns[$row['Field']] = $columnInfo;
            }

            return $columns;
        } catch (Exception $e) {
            // In case of error, return empty array to not block the process
            return [];
        }
    }

    /**
     * Fallback method to get table details when Doctrine fails.
     */
    private function getTableDetailsWithFallback(string $tableName): array
    {
        $connection = $this->getConnection();

        // Get column information via SHOW COLUMNS
        $detailedColumns = $this->getDetailedColumnInfo($tableName);

        // Build column information
        $columns = [];

        foreach ($detailedColumns as $columnName => $columnInfo) {
            $type = $this->mapMySQLTypeToDoctrineType($columnInfo['Type']);

            $columns[] = [
                'name'           => $columnName,
                'type'           => $type,
                'raw_type'       => $columnInfo['Type'],
                'length'         => $this->extractLength($columnInfo['Type']),
                'precision'      => null,
                'scale'          => null,
                'nullable'       => $columnInfo['Null'] === 'YES',
                'default'        => $columnInfo['Default'],
                'auto_increment' => str_contains($columnInfo['Extra'], 'auto_increment'),
                'comment'        => '',
                'enum_values'    => $columnInfo['enum_values'] ?? null,
                'set_values'     => $columnInfo['set_values'] ?? null,
            ];
        }

        // Get primary keys
        $primaryKey = [];

        foreach ($detailedColumns as $columnName => $columnInfo) {
            if ($columnInfo['Key'] === 'PRI') {
                $primaryKey[] = $columnName;
            }
        }

        // Get foreign keys via INFORMATION_SCHEMA
        $foreignKeys = $this->getForeignKeysWithFallback($tableName);

        // Get indexes via SHOW INDEX
        $indexes = $this->getIndexesWithFallback($tableName);

        return [
            'name'         => $tableName,
            'columns'      => $columns,
            'indexes'      => $indexes,
            'foreign_keys' => $foreignKeys,
            'primary_key'  => $primaryKey,
        ];
    }

    /**
     * Maps MySQL type to Doctrine type.
     */
    private function mapMySQLTypeToDoctrineType(string $mysqlType): string
    {
        // Clean type by removing modifiers like 'unsigned'
        $cleanType = preg_replace('/\s+(unsigned|signed|zerofill)/i', '', $mysqlType);
        $baseType  = strtolower(explode('(', $cleanType)[0]);

        return match ($baseType) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer' => 'integer',
            'bigint' => 'bigint',
            'decimal', 'numeric' => 'decimal',
            'float', 'double', 'real' => 'float',
            'bit', 'boolean', 'bool' => 'boolean',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'time' => 'time',
            'year' => 'integer',
            'char', 'varchar' => 'string',
            'text', 'tinytext', 'mediumtext', 'longtext' => 'text',
            'binary', 'varbinary' => 'binary',
            'blob', 'tinyblob', 'mediumblob', 'longblob' => 'blob',
            'json' => 'json',
            'enum', 'set' => 'string',
            // MySQL spatial/geometry types - all mapped to string for compatibility
            'geometry', 'point', 'linestring', 'polygon',
            'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' => 'string',
            default => 'string',
        };
    }

    /**
     * Extracts length from MySQL type.
     */
    private function extractLength(string $mysqlType): ?int
    {
        if (preg_match('/\((\d+)\)/', $mysqlType, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Gets foreign keys via INFORMATION_SCHEMA.
     */
    private function getForeignKeysWithFallback(string $tableName): array
    {
        try {
            $connection = $this->getConnection();
            $sql        = '
                SELECT
                    kcu.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    rc.UPDATE_RULE,
                    rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ';

            $result      = $connection->executeQuery($sql, [$tableName]);
            $foreignKeys = [];
            $groupedKeys = [];

            while ($row = $result->fetchAssociative()) {
                $constraintName = $row['CONSTRAINT_NAME'];

                if (! isset($groupedKeys[$constraintName])) {
                    $groupedKeys[$constraintName] = [
                        'name'            => $constraintName,
                        'local_columns'   => [],
                        'foreign_table'   => $row['REFERENCED_TABLE_NAME'],
                        'foreign_columns' => [],
                        'on_update'       => $row['UPDATE_RULE'] ?? 'RESTRICT',
                        'on_delete'       => $row['DELETE_RULE'] ?? 'RESTRICT',
                    ];
                }

                $groupedKeys[$constraintName]['local_columns'][]   = $row['COLUMN_NAME'];
                $groupedKeys[$constraintName]['foreign_columns'][] = $row['REFERENCED_COLUMN_NAME'];
            }

            return array_values($groupedKeys);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Gets indexes via SHOW INDEX.
     */
    private function getIndexesWithFallback(string $tableName): array
    {
        try {
            $connection = $this->getConnection();
            $sql        = "SHOW INDEX FROM `{$tableName}`";
            $result     = $connection->executeQuery($sql);

            $indexes   = [];
            $indexData = [];

            while ($row = $result->fetchAssociative()) {
                $indexName = $row['Key_name'];

                if (! isset($indexData[$indexName])) {
                    $indexData[$indexName] = [
                        'name'    => $indexName,
                        'columns' => [],
                        'unique'  => $row['Non_unique'] === 0,
                        'primary' => $indexName === 'PRIMARY',
                    ];
                }
                $indexData[$indexName]['columns'][] = $row['Column_name'];
            }

            foreach ($indexData as $index) {
                $indexes[] = $index;
            }

            return $indexes;
        } catch (Exception $e) {
            return [];
        }
    }
}
