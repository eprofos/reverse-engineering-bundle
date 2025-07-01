<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Service;

use Eprofos\ReverseEngineeringBundle\Exception\MetadataExtractionException;
use Exception;
use Psr\Log\LoggerInterface;

use function count;
use function in_array;

/**
 * Service for extracting and transforming database table metadata into entity metadata.
 *
 * This service handles the conversion of raw database schema information into
 * structured metadata that can be used for PHP entity generation. It processes
 * table columns, relationships, indexes, and generates appropriate PHP and Doctrine
 * type mappings. The service supports complex type mapping including ENUM, SET,
 * spatial types, and handles relationship detection and naming conventions.
 *
 * Key Features:
 * - Database type to PHP type mapping
 * - Doctrine DBAL type conversion
 * - Relationship extraction and naming
 * - ENUM/SET value processing
 * - Index and constraint analysis
 * - Entity and repository name generation
 */
class MetadataExtractor
{
    /**
     * Constructor - Initializes the metadata extractor with required dependencies.
     *
     * @param DatabaseAnalyzer $databaseAnalyzer Service for analyzing database structure
     * @param LoggerInterface  $logger           Logger for tracking metadata extraction operations
     */
    public function __construct(
        private readonly DatabaseAnalyzer $databaseAnalyzer,
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('MetadataExtractor service initialized');
    }

    /**
     * Extracts complete metadata from a database table and transforms it for entity generation.
     *
     * This method processes a single table to extract all relevant metadata including:
     * - Column definitions and type mappings
     * - Primary key information
     * - Foreign key relationships
     * - Indexes and constraints
     * - Entity and repository naming
     *
     * @param string $tableName The name of the database table to process
     * @param array  $allTables List of all available tables for relationship detection
     *
     * @throws MetadataExtractionException When metadata extraction fails
     *
     * @return array Complete metadata structure for entity generation
     */
    public function extractTableMetadata(string $tableName, array $allTables = []): array
    {
        $this->logger->info("Starting metadata extraction for table: {$tableName}");

        try {
            // Get raw table details from database analyzer
            $this->logger->debug("Fetching table details for: {$tableName}");
            $tableDetails = $this->databaseAnalyzer->getTableDetails($tableName);

            // Process column definitions and type mappings
            $this->logger->debug("Processing columns for table: {$tableName}", [
                'column_count'      => count($tableDetails['columns']),
                'foreign_key_count' => count($tableDetails['foreign_keys']),
            ]);
            $processedColumns = $this->processColumns(
                $tableDetails['columns'],
                $tableDetails['foreign_keys'],
                $tableDetails['primary_key'],
            );

            // Extract relationship information
            $this->logger->debug("Extracting relationships for table: {$tableName}");
            $relations = $this->extractRelations($tableDetails, $allTables);

            // Process indexes
            $this->logger->debug("Processing indexes for table: {$tableName}");
            $indexes = $this->processIndexes($tableDetails['indexes']);

            // Generate entity and repository names
            $entityName     = $this->generateEntityName($tableName);
            $repositoryName = $this->generateRepositoryName($tableName);

            $this->logger->info("Successfully extracted metadata for table: {$tableName}", [
                'entity_name'       => $entityName,
                'repository_name'   => $repositoryName,
                'columns_processed' => count($processedColumns),
                'relations_found'   => count($relations),
                'indexes_processed' => count($indexes),
            ]);

            return [
                'table_name'      => $tableName,
                'entity_name'     => $entityName,
                'columns'         => $processedColumns,
                'relations'       => $relations,
                'indexes'         => $indexes,
                'primary_key'     => $tableDetails['primary_key'],
                'repository_name' => $repositoryName,
            ];
        } catch (Exception $e) {
            $this->logger->error("Metadata extraction failed for table: {$tableName}", [
                'error_message' => $e->getMessage(),
                'error_trace'   => $e->getTraceAsString(),
            ]);

            throw new MetadataExtractionException(
                "Metadata extraction failed for table '{$tableName}': " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Processes database columns and converts them to entity property definitions.
     *
     * This method transforms raw database column information into structured metadata
     * suitable for entity generation. It handles type mapping, nullability,
     * primary key detection, foreign key relationships, and special column types
     * like ENUM and SET.
     *
     * @param array $columns     Raw column data from database analyzer
     * @param array $foreignKeys Foreign key constraints for the table
     * @param array $primaryKey  Primary key column names
     *
     * @return array Processed column metadata for entity generation
     */
    private function processColumns(array $columns, array $foreignKeys = [], array $primaryKey = []): array
    {
        $this->logger->debug('Processing table columns', [
            'total_columns' => count($columns),
            'foreign_keys'  => count($foreignKeys),
        ]);

        $processedColumns  = [];
        $foreignKeyColumns = [];

        // Extract columns that are foreign keys for later reference
        foreach ($foreignKeys as $fk) {
            foreach ($fk['local_columns'] as $localColumn) {
                $foreignKeyColumns[] = $localColumn;
            }
        }

        $this->logger->debug('Identified foreign key columns', [
            'foreign_key_columns' => $foreignKeyColumns,
        ]);

        foreach ($columns as $column) {
            // Log processing of individual column
            $this->logger->debug("Processing column: {$column['name']}", [
                'type'     => $column['type'],
                'raw_type' => $column['raw_type'] ?? 'not_available',
                'nullable' => $column['nullable'],
            ]);

            // Use raw type if available, otherwise Doctrine type
            $typeToMap   = $column['raw_type'] ?? $column['type'];
            $basePhpType = $this->mapDatabaseTypeToPhp($typeToMap);

            // Add ? prefix for nullable types (except bool and primary keys)
            $phpType      = $basePhpType;
            $isPrimaryKey = in_array($column['name'], $primaryKey, true);

            // Primary keys and NOT NULL columns should not be nullable
            // Exception: DateTime types can be nullable even if not explicitly NULL
            if ($column['nullable'] && ! $isPrimaryKey && $basePhpType !== 'bool') {
                // Handle types with namespace (starting with \)
                $phpType = '?' . $basePhpType;
                $this->logger->debug("Column {$column['name']} marked as nullable PHP type");
            }

            $processedColumn = [
                'name'                     => $column['name'],
                'property_name'            => $this->generatePropertyName($column['name']),
                'type'                     => $phpType,
                'doctrine_type'            => $this->mapDatabaseTypeToDoctrineType($column['type']),
                'nullable'                 => $column['nullable'],
                'length'                   => $column['length'],
                'precision'                => $column['precision'],
                'scale'                    => $column['scale'],
                'default'                  => $column['default'],
                'auto_increment'           => $column['auto_increment'],
                'comment'                  => $column['comment'],
                'is_primary'               => $isPrimaryKey,
                'is_foreign_key'           => in_array($column['name'], $foreignKeyColumns, true),
                'needs_lifecycle_callback' => $this->needsLifecycleCallback($column),
            ];

            // Add ENUM/SET information if available
            if (isset($column['enum_values'])) {
                $this->logger->debug("Processing ENUM values for column: {$column['name']}", [
                    'enum_values' => $column['enum_values'],
                ]);
                $processedColumn['enum_values'] = $column['enum_values'];
                $processedColumn['comment']     = $this->enhanceCommentWithEnumValues(
                    $column['comment'],
                    $column['enum_values'],
                );
            }

            if (isset($column['set_values'])) {
                $this->logger->debug("Processing SET values for column: {$column['name']}", [
                    'set_values' => $column['set_values'],
                ]);
                $processedColumn['set_values'] = $column['set_values'];
                $processedColumn['comment']    = $this->enhanceCommentWithSetValues(
                    $column['comment'],
                    $column['set_values'],
                );
            }

            $processedColumns[] = $processedColumn;
        }

        $this->logger->info('Column processing completed', [
            'processed_columns' => count($processedColumns),
        ]);

        return $processedColumns;
    }

    /**
     * Extracts relationships between tables.
     */
    private function extractRelations(array $tableDetails, array $allTables = []): array
    {
        $relations         = [];
        $usedPropertyNames = [];

        // Relations based on foreign keys (ManyToOne)
        foreach ($tableDetails['foreign_keys'] as $foreignKey) {
            $targetTable  = $foreignKey['foreign_table'];
            $targetEntity = $this->generateEntityName($targetTable);
            $localColumn  = $foreignKey['local_columns'][0]; // Take the first local column

            // Generate unique property name
            $propertyName = $this->generateUniqueRelationPropertyName(
                $targetTable,
                $localColumn,
                $usedPropertyNames,
            );
            $usedPropertyNames[] = $propertyName;

            $relations[] = [
                'type'            => 'many_to_one',
                'target_entity'   => $targetEntity,
                'target_table'    => $targetTable,
                'local_columns'   => $foreignKey['local_columns'],
                'foreign_columns' => $foreignKey['foreign_columns'],
                'property_name'   => $propertyName,
                'on_delete'       => $foreignKey['on_delete'],
                'on_update'       => $foreignKey['on_update'],
                'nullable'        => $this->isRelationNullable($foreignKey['local_columns'], $tableDetails['columns']),
            ];
        }

        return $relations;
    }

    /**
     * Processes table indexes.
     */
    private function processIndexes(array $indexes): array
    {
        $processedIndexes = [];

        foreach ($indexes as $index) {
            if (! $index['primary']) { // Exclude primary key
                $processedIndexes[] = [
                    'name'    => $index['name'],
                    'columns' => $index['columns'],
                    'unique'  => $index['unique'],
                ];
            }
        }

        return $processedIndexes;
    }

    /**
     * Generates entity name from table name.
     */
    private function generateEntityName(string $tableName): string
    {
        // Convert snake_case to PascalCase
        $entityName = str_replace('_', ' ', $tableName);
        $entityName = ucwords($entityName);
        $entityName = str_replace(' ', '', $entityName);

        // Singularize if necessary (basic rules)
        if (str_ends_with($entityName, 'ies')) {
            $entityName = substr($entityName, 0, -3) . 'y';
        } elseif (str_ends_with($entityName, 's') && ! str_ends_with($entityName, 'ss')) {
            $entityName = substr($entityName, 0, -1);
        }

        return $entityName;
    }

    /**
     * Generates property name from column name.
     */
    private function generatePropertyName(string $columnName): string
    {
        // Convert snake_case to camelCase
        $parts        = explode('_', $columnName);
        $propertyName = array_shift($parts);

        foreach ($parts as $part) {
            $propertyName .= ucfirst($part);
        }

        return $propertyName;
    }

    /**
     * Generates relation property name.
     */
    private function generateRelationPropertyName(string $tableName): string
    {
        $entityName = $this->generateEntityName($tableName);

        return lcfirst($entityName);
    }

    /**
     * Generates unique relation property name considering conflicts.
     */
    private function generateUniqueRelationPropertyName(string $targetTable, string $localColumn, array $usedPropertyNames): string
    {
        // Base name based on target table
        $basePropertyName = $this->generateRelationPropertyName($targetTable);

        // If base name is not used, return it
        if (! in_array($basePropertyName, $usedPropertyNames, true)) {
            return $basePropertyName;
        }

        // Otherwise, generate name based on local column
        $columnBasedName = $this->generatePropertyNameFromColumn($localColumn, $targetTable);

        // If this name is not used, return it
        if (! in_array($columnBasedName, $usedPropertyNames, true)) {
            return $columnBasedName;
        }

        // As last resort, add numeric suffix
        $counter    = 2;
        $uniqueName = $basePropertyName . $counter;

        while (in_array($uniqueName, $usedPropertyNames, true)) {
            ++$counter;
            $uniqueName = $basePropertyName . $counter;
        }

        return $uniqueName;
    }

    /**
     * Generates property name based on local column and target table.
     */
    private function generatePropertyNameFromColumn(string $localColumn, string $targetTable): string
    {
        // Remove '_id' suffix from local column
        $columnWithoutId = preg_replace('/_id$/', '', $localColumn);

        // If column contains target table name, use column directly
        $targetEntityLower = strtolower($this->generateEntityName($targetTable));

        if (str_contains(strtolower($columnWithoutId), $targetEntityLower)) {
            return $this->generatePropertyName($columnWithoutId);
        }

        // Otherwise, combine column with target entity
        $propertyName = $this->generatePropertyName($columnWithoutId);
        $targetEntity = $this->generateEntityName($targetTable);

        // If property doesn't already contain entity name, add it
        if (stripos($propertyName, $targetEntity) === false) {
            $propertyName .= $targetEntity;
        }

        return $propertyName;
    }

    /**
     * Generates repository name.
     */
    private function generateRepositoryName(string $tableName): string
    {
        return $this->generateEntityName($tableName) . 'Repository';
    }

    /**
     * Maps database column types to corresponding PHP types.
     *
     * This method converts database-specific column types to appropriate PHP types
     * for entity properties. It handles all major database types including:
     * - Numeric types (int, float, decimal)
     * - String types (varchar, text, etc.)
     * - Date/time types
     * - Boolean types
     * - Special types (JSON, ENUM, SET, spatial types)
     *
     * @param string $databaseType The database column type (e.g., 'VARCHAR(255)', 'INT(11)')
     *
     * @return string The corresponding PHP type (e.g., 'string', 'int', '?\DateTimeInterface')
     */
    private function mapDatabaseTypeToPhp(string $databaseType): string
    {
        // Clean type by removing modifiers like 'unsigned'
        $cleanType = preg_replace('/\s+(unsigned|signed|zerofill)/i', '', $databaseType);
        // Extract base type (without parameters)
        $baseType = strtolower(explode('(', $cleanType)[0]);

        $this->logger->debug('Mapping database type to PHP', [
            'original_type' => $databaseType,
            'clean_type'    => $cleanType,
            'base_type'     => $baseType,
        ]);

        $phpType = match ($baseType) {
            'int', 'integer' => 'int',
            'bigint'    => 'int',
            'smallint'  => 'int',
            'mediumint' => 'int',
            'tinyint'   => 'int',
            'year'      => 'int',
            'float', 'double', 'real' => 'float',
            'decimal', 'numeric' => 'string',
            'boolean', 'bool' => 'bool',
            'bit' => 'bool',
            'date', 'datetime', 'timestamp' => '\DateTimeInterface',
            'time' => '\DateTimeInterface',
            'json' => 'array',
            'text', 'longtext', 'mediumtext', 'tinytext' => 'string',
            'varchar', 'char' => 'string',
            'blob', 'longblob', 'mediumblob', 'tinyblob' => 'string',
            'binary', 'varbinary' => 'string',
            'uuid'               => 'string',
            'enum'               => 'string',
            'set'                => 'string',
            'geometry'           => 'string',
            'point'              => 'string',
            'linestring'         => 'string',
            'polygon'            => 'string',
            'multipoint'         => 'string',
            'multilinestring'    => 'string',
            'multipolygon'       => 'string',
            'geometrycollection' => 'string',
            default              => 'string',
        };

        $this->logger->debug('Database type mapped to PHP', [
            'database_type' => $databaseType,
            'php_type'      => $phpType,
        ]);

        return $phpType;
    }

    /**
     * Maps database column types to corresponding Doctrine DBAL types.
     *
     * This method converts database-specific column types to Doctrine DBAL types
     * that are used for ORM mapping and schema generation. It ensures that
     * the generated entities have proper Doctrine annotations for database
     * interaction.
     *
     * @param string $databaseType The database column type
     *
     * @return string The corresponding Doctrine DBAL type
     */
    private function mapDatabaseTypeToDoctrineType(string $databaseType): string
    {
        $baseType = strtolower($databaseType);

        $this->logger->debug('Mapping database type to Doctrine', [
            'database_type' => $databaseType,
            'base_type'     => $baseType,
        ]);

        $doctrineType = match ($baseType) {
            'int', 'integer' => 'integer',
            'bigint'   => 'bigint',
            'smallint' => 'smallint',
            'tinyint'  => 'smallint',
            'float', 'double', 'real' => 'float',
            'decimal', 'numeric' => 'decimal',
            'boolean', 'bool' => 'boolean',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'time' => 'time',
            'json' => 'json',
            'text', 'longtext', 'mediumtext', 'tinytext' => 'text',
            'varchar', 'char' => 'string',
            'blob', 'longblob', 'mediumblob', 'tinyblob' => 'blob',
            'binary', 'varbinary' => 'binary',
            'uuid'               => 'uuid',
            'enum'               => 'string',
            'set'                => 'string',
            'geometry'           => 'string',
            'point'              => 'string',
            'linestring'         => 'string',
            'polygon'            => 'string',
            'multipoint'         => 'string',
            'multilinestring'    => 'string',
            'multipolygon'       => 'string',
            'geometrycollection' => 'string',
            default              => 'string',
        };

        $this->logger->debug('Database type mapped to Doctrine', [
            'database_type' => $databaseType,
            'doctrine_type' => $doctrineType,
        ]);

        return $doctrineType;
    }

    /**
     * Determines if a relation is nullable based on local columns.
     */
    private function isRelationNullable(array $localColumns, array $tableColumns): bool
    {
        foreach ($localColumns as $localColumn) {
            foreach ($tableColumns as $column) {
                if ($column['name'] === $localColumn) {
                    return $column['nullable'];
                }
            }
        }

        return false;
    }

    /**
     * Enhances column comment with ENUM values.
     */
    private function enhanceCommentWithEnumValues(?string $originalComment, array $enumValues): string
    {
        $enumComment = 'Possible values: ' . implode(', ', array_map(fn ($v) => "'{$v}'", $enumValues));

        if ($originalComment) {
            return $originalComment . ' - ' . $enumComment;
        }

        return $enumComment;
    }

    /**
     * Enhances column comment with SET values.
     */
    private function enhanceCommentWithSetValues(?string $originalComment, array $setValues): string
    {
        $setComment = 'Possible SET values: ' . implode(', ', array_map(fn ($v) => "'{$v}'", $setValues));

        if ($originalComment) {
            return $originalComment . ' - ' . $setComment;
        }

        return $setComment;
    }

    /**
     * Determines if a column needs lifecycle callback for CURRENT_TIMESTAMP handling.
     */
    private function needsLifecycleCallback(array $column): bool
    {
        // Check if column has CURRENT_TIMESTAMP default and is a datetime/timestamp type
        if ($column['default'] === 'CURRENT_TIMESTAMP') {
            $doctrineType = $this->mapDatabaseTypeToDoctrineType($column['type']);

            return in_array($doctrineType, ['datetime', 'timestamp'], true);
        }

        return false;
    }
}
