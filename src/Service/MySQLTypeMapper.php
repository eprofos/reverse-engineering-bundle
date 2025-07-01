<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Service;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;

/**
 * Service for mapping special MySQL types to supported Doctrine types.
 *
 * This service handles the registration and mapping of MySQL-specific data types
 * that are not natively supported by Doctrine DBAL. It provides comprehensive
 * support for:
 * - ENUM and SET types
 * - Spatial/Geometry types (GEOMETRY, POINT, POLYGON, etc.)
 * - MySQL-specific numeric types (YEAR, BIT)
 * - Other MySQL extensions
 */
class MySQLTypeMapper
{
    /**
     * Registers custom MySQL types with Doctrine DBAL.
     *
     * This method registers MySQL-specific types that need to be mapped to
     * existing Doctrine types. All custom types are mapped to StringType
     * as the safest and most compatible option for entity generation.
     *
     * The method handles registration of:
     * - ENUM types for enumerated values
     * - SET types for multiple choice values
     * - GEOMETRY types for spatial data
     */
    public static function registerCustomTypes(): void
    {
        // Register ENUM type as STRING for compatibility
        // This allows Doctrine to handle MySQL ENUM columns without errors
        if (! Type::hasType('enum')) {
            Type::addType('enum', StringType::class);
        }

        // Register SET type as STRING for compatibility
        // SET columns can contain multiple values from a predefined list
        if (! Type::hasType('set')) {
            Type::addType('set', StringType::class);
        }

        // Register GEOMETRY type as STRING for spatial data handling
        // Spatial types are stored as strings for maximum compatibility
        if (! Type::hasType('geometry')) {
            Type::addType('geometry', StringType::class);
        }
    }

    /**
     * Configures the database platform to map MySQL types to Doctrine types.
     *
     * This method registers type mappings at the platform level to ensure
     * that MySQL-specific types are properly recognized and handled by
     * Doctrine DBAL during schema introspection and entity generation.
     *
     * @param AbstractPlatform $platform The database platform to configure
     */
    public static function configurePlatform(AbstractPlatform $platform): void
    {
        // Map ENUM to STRING for entity properties
        $platform->registerDoctrineTypeMapping('enum', 'string');

        // Map SET to STRING for entity properties
        $platform->registerDoctrineTypeMapping('set', 'string');

        // Map GEOMETRY and other spatial types to STRING for compatibility
        // These spatial types are commonly used in GIS applications
        $platform->registerDoctrineTypeMapping('geometry', 'string');
        $platform->registerDoctrineTypeMapping('point', 'string');
        $platform->registerDoctrineTypeMapping('linestring', 'string');
        $platform->registerDoctrineTypeMapping('polygon', 'string');
        $platform->registerDoctrineTypeMapping('multipoint', 'string');
        $platform->registerDoctrineTypeMapping('multilinestring', 'string');
        $platform->registerDoctrineTypeMapping('multipolygon', 'string');
        $platform->registerDoctrineTypeMapping('geometrycollection', 'string');

        // Other useful MySQL-specific type mappings
        $platform->registerDoctrineTypeMapping('year', 'integer');
        $platform->registerDoctrineTypeMapping('bit', 'boolean');
    }

    /**
     * Extracts values from an ENUM type definition.
     *
     * This method parses MySQL ENUM column definitions to extract the list
     * of possible values. It handles proper parsing of quoted values and
     * returns a clean array of enum options.
     *
     * @param string $enumDefinition The MySQL ENUM definition (e.g., "enum('value1','value2')")
     *
     * @return array Array of possible enum values, empty array if parsing fails
     *
     * @example
     * extractEnumValues("enum('G','PG','PG-13','R','NC-17')")
     * // Returns: ['G', 'PG', 'PG-13', 'R', 'NC-17']
     */
    public static function extractEnumValues(string $enumDefinition): array
    {
        // Parse ENUM definition using regex to extract quoted values
        // Example: enum('G','PG','PG-13','R','NC-17')
        if (preg_match('/^enum\\((.+)\\)$/i', $enumDefinition, $matches)) {
            // Use str_getcsv to properly handle quoted values with commas
            $values = str_getcsv($matches[1], ',', "'");

            // Trim whitespace from each value and return clean array
            return array_map('trim', $values);
        }

        // Return empty array if parsing fails
        return [];
    }

    /**
     * Extracts values from a SET type definition.
     *
     * This method parses MySQL SET column definitions to extract the list
     * of possible values. SET columns can contain multiple values from
     * the defined set, separated by commas.
     *
     * @param string $setDefinition The MySQL SET definition (e.g., "set('option1','option2')")
     *
     * @return array Array of possible set values, empty array if parsing fails
     *
     * @example
     * extractSetValues("set('Trailers','Commentaries','Deleted Scenes')")
     * // Returns: ['Trailers', 'Commentaries', 'Deleted Scenes']
     */
    public static function extractSetValues(string $setDefinition): array
    {
        // Parse SET definition using regex to extract quoted values
        // Example: set('Trailers','Commentaries','Deleted Scenes','Behind the Scenes')
        if (preg_match('/^set\\((.+)\\)$/i', $setDefinition, $matches)) {
            // Use str_getcsv to properly handle quoted values with commas
            $values = str_getcsv($matches[1], ',', "'");

            // Trim whitespace from each value and return clean array
            return array_map('trim', $values);
        }

        // Return empty array if parsing fails
        return [];
    }

    /**
     * Determines the appropriate PHP type for a column type.
     *
     * This method maps database column types to their corresponding PHP types
     * for use in entity properties. It handles nullable types by adding the
     * appropriate nullable prefix when required.
     *
     * @param string $columnType The database column type (e.g., 'varchar', 'int', 'enum')
     * @param bool   $nullable   Whether the column allows NULL values
     *
     * @return string The corresponding PHP type (e.g., 'string', 'int', '?string')
     */
    public static function mapToPhpType(string $columnType, bool $nullable = false): string
    {
        $baseType = strtolower(explode('(', $columnType)[0]);

        $typeMap = [
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
            'year'               => 'int',
            'bit'                => 'bool',
            'tinyint'            => 'int',
            'smallint'           => 'int',
            'mediumint'          => 'int',
            'int'                => 'int',
            'integer'            => 'int',
            'bigint'             => 'int',
            'decimal'            => 'string',
            'numeric'            => 'string',
            'float'              => 'float',
            'double'             => 'float',
            'real'               => 'float',
            'varchar'            => 'string',
            'char'               => 'string',
            'text'               => 'string',
            'mediumtext'         => 'string',
            'longtext'           => 'string',
            'date'               => '\DateTimeInterface',
            'datetime'           => '\DateTimeInterface',
            'timestamp'          => '\DateTimeInterface',
            'time'               => '\DateTimeInterface',
            'json'               => 'array',
            'blob'               => 'string',
            'binary'             => 'string',
            'varbinary'          => 'string',
        ];

        $phpType = $typeMap[$baseType] ?? 'string';

        return $nullable ? "?{$phpType}" : $phpType;
    }

    /**
     * Generates class constants for ENUM values.
     *
     * This method creates PHP class constants for ENUM values, providing
     * a type-safe way to reference enum options in code. Constants are
     * named using the property name as a prefix followed by the enum value.
     *
     * @param array  $enumValues   Array of ENUM values from database
     * @param string $propertyName The property name to use as constant prefix
     *
     * @return array Associative array of constant names to values
     *
     * @example
     * generateEnumConstants(['active', 'inactive'], 'status')
     * // Returns: ['STATUS_ACTIVE' => 'active', 'STATUS_INACTIVE' => 'inactive']
     */
    public static function generateEnumConstants(array $enumValues, string $propertyName): array
    {
        $constants = [];
        $prefix    = self::normalizeConstantName($propertyName);

        // Generate a constant for each enum value
        foreach ($enumValues as $value) {
            $constantName             = $prefix . '_' . self::normalizeConstantName($value);
            $constants[$constantName] = $value;
        }

        return $constants;
    }

    /**
     * Generates class constants for SET values.
     *
     * This method creates PHP class constants for SET values, providing
     * a type-safe way to reference set options in code. Constants are
     * named using the property name as a prefix followed by the set value.
     *
     * @param array  $setValues    Array of SET values from database
     * @param string $propertyName The property name to use as constant prefix
     *
     * @return array Associative array of constant names to values
     *
     * @example
     * generateSetConstants(['read', 'write', 'execute'], 'permissions')
     * // Returns: ['PERMISSIONS_READ' => 'read', 'PERMISSIONS_WRITE' => 'write', ...]
     */
    public static function generateSetConstants(array $setValues, string $propertyName): array
    {
        $constants = [];
        $prefix    = self::normalizeConstantName($propertyName);

        // Generate a constant for each set value
        foreach ($setValues as $value) {
            $constantName             = $prefix . '_' . self::normalizeConstantName($value);
            $constants[$constantName] = $value;
        }

        return $constants;
    }

    /**
     * Checks if a column type is an ENUM or SET type.
     *
     * This method determines whether a given column type definition
     * represents a MySQL ENUM or SET type by checking the type prefix.
     *
     * @param string $columnType The column type definition to check
     *
     * @return bool True if the type is ENUM or SET, false otherwise
     *
     * @example
     * isEnumOrSetType("enum('active','inactive')") // Returns: true
     * isEnumOrSetType("varchar(255)") // Returns: false
     */
    public static function isEnumOrSetType(string $columnType): bool
    {
        $lowerType = strtolower($columnType);

        // Check if the type starts with 'enum(' or 'set('
        return str_starts_with($lowerType, 'enum(') || str_starts_with($lowerType, 'set(');
    }

    /**
     * Normalizes a string to be used as a PHP constant name.
     *
     * This method converts input strings to valid PHP constant names by:
     * - Converting to uppercase
     * - Replacing non-alphanumeric characters with underscores
     * - Removing consecutive underscores
     * - Trimming leading/trailing underscores
     *
     * @param string $input The input string to normalize
     *
     * @return string A valid PHP constant name
     *
     * @example
     * normalizeConstantName('user-status') // Returns: 'USER_STATUS'
     * normalizeConstantName('my value!') // Returns: 'MY_VALUE'
     */
    private static function normalizeConstantName(string $input): string
    {
        // Convert to uppercase first
        $normalized = strtoupper($input);

        // Replace sequences of non-alphanumeric characters with single underscores
        $normalized = preg_replace('/[^A-Z0-9]+/', '_', $normalized);

        // Remove leading and trailing underscores
        return trim($normalized, '_');
    }
}
