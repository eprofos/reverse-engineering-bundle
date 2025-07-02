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
     * Current table being processed - used for self-referencing relationship detection.
     */
    private ?string $currentTableName = null;
    
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
        
        // Set current table name for self-referencing relationship detection
        $this->currentTableName = $tableName;

        try {
            // Get raw table details from database analyzer
            $this->logger->debug("Fetching table details for: {$tableName}");
            $tableDetails = $this->databaseAnalyzer->getTableDetails($tableName);
            
            // Ensure table_name is set in the details
            $tableDetails['table_name'] = $tableName;

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

            $result = [
                'table_name'      => $tableName,
                'entity_name'     => $entityName,
                'columns'         => $processedColumns,
                'relations'       => $relations,
                'indexes'         => $indexes,
                'primary_key'     => $tableDetails['primary_key'],
                'repository_name' => $repositoryName,
            ];
            
            // Clear current table name
            $this->currentTableName = null;
            
            return $result;
        } catch (Exception $e) {
            // Clear current table name on error as well
            $this->currentTableName = null;
            
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

        $this->logger->debug("Extracting relations for table: {$tableDetails['table_name']}", [
            'foreign_keys_count' => count($tableDetails['foreign_keys']),
            'all_tables_count'   => count($allTables),
        ]);

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
                'getter_name'     => 'get' . ucfirst($propertyName),
                'setter_name'     => 'set' . ucfirst($propertyName),
                'on_delete'       => $foreignKey['on_delete'],
                'on_update'       => $foreignKey['on_update'],
                'nullable'        => $this->isRelationNullable($foreignKey['local_columns'], $tableDetails['columns']),
            ];

            $this->logger->debug("Added ManyToOne relation: {$propertyName}", [
                'target_entity' => $targetEntity,
                'target_table'  => $targetTable,
            ]);
        }

        // Extract OneToMany relationships by analyzing other tables' foreign keys
        if (! empty($allTables)) {
            $oneToManyRelations = $this->extractOneToManyRelations($tableDetails['table_name'], $allTables, $usedPropertyNames, $tableDetails);
            $relations          = array_merge($relations, $oneToManyRelations);
        }

        $this->logger->info("Extracted relations for table: {$tableDetails['table_name']}", [
            'total_relations' => count($relations),
            'many_to_one'     => count(array_filter($relations, static fn($r) => $r['type'] === 'many_to_one')),
            'one_to_many'     => count(array_filter($relations, static fn($r) => $r['type'] === 'one_to_many')),
        ]);

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
        // For self-referencing relationships (same table), prioritize column-based name
        $isCurrentTable = isset($this->currentTableName) && $this->currentTableName === $targetTable;
        
        if ($isCurrentTable) {
            // For self-referencing, use column name (e.g., parent_id -> parent)
            $columnBasedName = $this->generatePropertyNameFromColumn($localColumn, $targetTable);
            
            // For common self-referencing patterns, use semantic names
            $columnWithoutId = preg_replace('/_id$/', '', $localColumn);
            if (in_array($columnWithoutId, ['parent', 'manager', 'leader', 'supervisor'], true)) {
                return $columnWithoutId;
            }
            
            return $columnBasedName;
        }
        
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

    /**
     * Extracts OneToMany relationships by analyzing foreign keys from other tables.
     *
     * This method identifies inverse relationships by examining foreign key constraints
     * in other tables that reference the current table. For each such relationship,
     * it creates a OneToMany relationship definition with proper collection handling.
     *
     * @param string $currentTableName The current table being processed
     * @param array  $allTables        List of all available tables for analysis
     * @param array  $usedPropertyNames Already used property names to avoid conflicts
     * @param array  $currentTableDetails Current table details to avoid duplicate calls
     *
     * @return array Array of OneToMany relationship definitions
     */
    private function extractOneToManyRelations(string $currentTableName, array $allTables, array &$usedPropertyNames, array $currentTableDetails = []): array
    {
        $this->logger->debug("Extracting OneToMany relations for table: {$currentTableName}");
        $oneToManyRelations = [];

        foreach ($allTables as $otherTableName) {
            // Skip self (will be handled separately for self-referencing)
            if ($otherTableName === $currentTableName) {
                continue;
            }

            try {
                // Get details of the other table to check its foreign keys
                $otherTableDetails = $this->databaseAnalyzer->getTableDetails($otherTableName);

                foreach ($otherTableDetails['foreign_keys'] as $foreignKey) {
                    // Check if this foreign key references our current table
                    if ($foreignKey['foreign_table'] === $currentTableName) {
                        $this->logger->debug("Found OneToMany relation: {$currentTableName} -> {$otherTableName}", [
                            'foreign_key_columns' => $foreignKey['local_columns'],
                            'referenced_columns'  => $foreignKey['foreign_columns'],
                        ]);

                        // Generate collection property name (plural form of the referencing table)
                        $collectionPropertyName = $this->generateCollectionPropertyName($otherTableName, $usedPropertyNames);
                        $usedPropertyNames[]    = $collectionPropertyName;

                        // Determine the mappedBy property (the ManyToOne property name in the other entity)
                        $mappedByProperty = $this->generateUniqueRelationPropertyName(
                            $currentTableName,
                            $foreignKey['local_columns'][0],
                            [], // Don't check conflicts for mappedBy, it's in another entity
                        );

                        $oneToManyRelations[] = [
                            'type'                   => 'one_to_many',
                            'target_entity'          => $this->generateEntityName($otherTableName),
                            'target_table'           => $otherTableName,
                            'property_name'          => $collectionPropertyName,
                            'mapped_by'              => $mappedByProperty,
                            'foreign_key_columns'    => $foreignKey['local_columns'],
                            'referenced_columns'     => $foreignKey['foreign_columns'],
                            'getter_name'            => 'get' . ucfirst($collectionPropertyName),
                            'add_method_name'        => 'add' . ucfirst($this->generateEntityName($otherTableName)),
                            'remove_method_name'     => 'remove' . ucfirst($this->generateEntityName($otherTableName)),
                            'singular_parameter_name' => lcfirst($this->generateEntityName($otherTableName)),
                            'on_delete'              => $foreignKey['on_delete'],
                            'on_update'              => $foreignKey['on_update'],
                        ];
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to analyze table {$otherTableName} for OneToMany relations", [
                    'error' => $e->getMessage(),
                ]);
                // Continue processing other tables
            }
        }

        // Handle self-referencing relationships (e.g., categories with parent_id)
        $selfReferencingRelations = $this->extractSelfReferencingOneToManyRelations($currentTableName, $usedPropertyNames, $currentTableDetails);
        $oneToManyRelations       = array_merge($oneToManyRelations, $selfReferencingRelations);

        $this->logger->debug("Extracted OneToMany relations for {$currentTableName}", [
            'relations_count'      => count($oneToManyRelations),
            'self_referencing'     => count($selfReferencingRelations),
        ]);

        return $oneToManyRelations;
    }

    /**
     * Extracts self-referencing OneToMany relationships.
     *
     * This method detects self-referencing relationships where a table has foreign keys
     * that reference its own primary key (e.g., categories with parent_id).
     *
     * @param string $tableName         The table name to analyze
     * @param array  $usedPropertyNames Already used property names to avoid conflicts
     * @param array  $tableDetails      Table details to avoid duplicate calls
     *
     * @return array Array of self-referencing OneToMany relationship definitions
     */
    private function extractSelfReferencingOneToManyRelations(string $tableName, array &$usedPropertyNames, array $tableDetails = []): array
    {
        $this->logger->debug("Checking for self-referencing relations in table: {$tableName}");
        $selfReferencingRelations = [];

        try {
            // Use provided table details or fetch them
            if (empty($tableDetails)) {
                $tableDetails = $this->databaseAnalyzer->getTableDetails($tableName);
            }

            foreach ($tableDetails['foreign_keys'] as $foreignKey) {
                // Check if this foreign key references the same table
                if ($foreignKey['foreign_table'] === $tableName) {
                    $this->logger->debug("Found self-referencing relation in table: {$tableName}", [
                        'local_columns'   => $foreignKey['local_columns'],
                        'foreign_columns' => $foreignKey['foreign_columns'],
                    ]);

                    // Generate collection property name for children (e.g., "children" for parent-child relationship)
                    $collectionPropertyName = $this->generateSelfReferencingCollectionPropertyName(
                        $foreignKey['local_columns'][0],
                        $usedPropertyNames,
                    );
                    $usedPropertyNames[] = $collectionPropertyName;

                    // The mappedBy property is the ManyToOne property name (e.g., "parent")
                    $mappedByProperty = $this->generateUniqueRelationPropertyName(
                        $tableName,
                        $foreignKey['local_columns'][0],
                        [],
                    );

                    $entityName = $this->generateEntityName($tableName);

                    $selfReferencingRelations[] = [
                        'type'                    => 'one_to_many',
                        'target_entity'           => $entityName,
                        'target_table'            => $tableName,
                        'property_name'           => $collectionPropertyName,
                        'mapped_by'               => $mappedByProperty,
                        'foreign_key_columns'     => $foreignKey['local_columns'],
                        'referenced_columns'      => $foreignKey['foreign_columns'],
                        'getter_name'             => 'get' . ucfirst($collectionPropertyName),
                        'add_method_name'         => 'add' . ucfirst($collectionPropertyName === 'children' ? 'Child' : $entityName),
                        'remove_method_name'      => 'remove' . ucfirst($collectionPropertyName === 'children' ? 'Child' : $entityName),
                        'singular_parameter_name' => $collectionPropertyName === 'children' ? 'child' : lcfirst($entityName),
                        'is_self_referencing'     => true,
                        'on_delete'               => $foreignKey['on_delete'],
                        'on_update'               => $foreignKey['on_update'],
                    ];
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to extract self-referencing relations for table {$tableName}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $selfReferencingRelations;
    }

    /**
     * Generates collection property name for OneToMany relationships.
     *
     * This method creates appropriate collection property names for OneToMany relationships
     * by converting table names to plural camelCase properties.
     * Examples: 'products' table -> 'products' property, 'category' table -> 'categories' property
     *
     * @param string $tableName         The target table name
     * @param array  $usedPropertyNames Already used property names to avoid conflicts
     *
     * @return string The generated collection property name
     */
    private function generateCollectionPropertyName(string $tableName, array $usedPropertyNames): string
    {
        // Convert table name to camelCase
        $basePropertyName = $this->generatePropertyName($tableName);
        
        // Make it plural if it's not already
        $pluralPropertyName = $this->pluralizePropertyName($basePropertyName);

        // Handle conflicts by adding suffix
        $finalPropertyName = $pluralPropertyName;
        $counter = 1;
        while (in_array($finalPropertyName, $usedPropertyNames, true)) {
            $finalPropertyName = $pluralPropertyName . ucfirst((string) $counter);
            $counter++;
        }

        $this->logger->debug("Generated collection property name", [
            'table_name'      => $tableName,
            'base_name'       => $basePropertyName,
            'plural_name'     => $pluralPropertyName,
            'final_name'      => $finalPropertyName,
        ]);

        return $finalPropertyName;
    }

    /**
     * Generates collection property name for self-referencing relationships.
     *
     * This method creates appropriate collection property names for self-referencing
     * OneToMany relationships, typically using semantic names like 'children' for
     * parent-child relationships.
     *
     * @param string $foreignKeyColumn  The foreign key column name (e.g., 'parent_id')
     * @param array  $usedPropertyNames Already used property names to avoid conflicts
     *
     * @return string The generated collection property name
     */
    private function generateSelfReferencingCollectionPropertyName(string $foreignKeyColumn, array $usedPropertyNames): string
    {
        // Common semantic mappings for self-referencing relationships
        $semanticMappings = [
            'parent_id'   => 'children',
            'manager_id'  => 'subordinates',
            'leader_id'   => 'members',
            'category_id' => 'subcategories',
        ];

        // Try semantic mapping first
        if (isset($semanticMappings[$foreignKeyColumn])) {
            $basePropertyName = $semanticMappings[$foreignKeyColumn];
        } else {
            // Fall back to generic naming based on column name
            $columnWithoutId = str_replace('_id', '', $foreignKeyColumn);
            $basePropertyName = $this->pluralizePropertyName($columnWithoutId);
        }

        // Handle conflicts
        $finalPropertyName = $basePropertyName;
        $counter = 1;
        while (in_array($finalPropertyName, $usedPropertyNames, true)) {
            $finalPropertyName = $basePropertyName . ucfirst((string) $counter);
            $counter++;
        }

        $this->logger->debug("Generated self-referencing collection property name", [
            'foreign_key_column' => $foreignKeyColumn,
            'base_name'          => $basePropertyName,
            'final_name'         => $finalPropertyName,
        ]);

        return $finalPropertyName;
    }

    /**
     * Pluralizes a property name using basic English pluralization rules.
     *
     * @param string $propertyName The singular property name
     *
     * @return string The pluralized property name
     */
    private function pluralizePropertyName(string $propertyName): string
    {
        // Common words that are already plural or should stay unchanged
        $alreadyPlural = [
            'products', 'categories', 'users', 'items', 'orders', 'details',
            'files', 'images', 'documents', 'settings', 'permissions', 'roles',
            'news', 'series', 'species', 'data', 'information'
        ];
        
        // If the word is already plural, return as-is
        if (in_array(strtolower($propertyName), $alreadyPlural, true)) {
            return $propertyName;
        }
        
        // Basic pluralization rules
        if (str_ends_with($propertyName, 'y') && ! in_array(substr($propertyName, -2, 1), ['a', 'e', 'i', 'o', 'u'], true)) {
            // Category -> categories
            return substr($propertyName, 0, -1) . 'ies';
        }

        if (str_ends_with($propertyName, 's') || str_ends_with($propertyName, 'x') || str_ends_with($propertyName, 'z') || 
            str_ends_with($propertyName, 'ch') || str_ends_with($propertyName, 'sh')) {
            // Bus -> buses, Box -> boxes
            return $propertyName . 'es';
        }

        if (str_ends_with($propertyName, 'f')) {
            // Leaf -> leaves
            return substr($propertyName, 0, -1) . 'ves';
        }

        if (str_ends_with($propertyName, 'fe')) {
            // Life -> lives
            return substr($propertyName, 0, -2) . 'ves';
        }

        // Default: just add 's'
        return $propertyName . 's';
    }
}
