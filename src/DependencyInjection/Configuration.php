<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration class for ReverseEngineering bundle settings.
 *
 * This class defines the configuration tree structure for the reverse engineering
 * bundle, including database connection parameters, entity generation options,
 * output settings, and various feature flags. It provides validation and
 * default values for all configuration options.
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder for the bundle.
     *
     * This method defines the complete configuration structure for the reverse
     * engineering bundle, including database connection parameters, entity
     * generation options, output directories, and feature flags. It provides
     * validation rules, default values, and documentation for all configuration
     * options available to users.
     *
     * The configuration is organized into two main sections:
     * - database: Connection parameters and database-specific settings
     * - generation: Entity generation options, namespaces, and output settings
     *
     * @return TreeBuilder The configuration tree builder with all bundle options
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('reverse_engineering');
        $rootNode    = $treeBuilder->getRootNode();

        // Build the configuration tree with database and generation sections
        $rootNode
            ->children()
                // Database configuration section
                ->arrayNode('database')
                    ->info('Database connection configuration')
                    ->children()
                        ->scalarNode('driver')
                            ->defaultValue('pdo_mysql')
                            ->info('Database driver (pdo_mysql, pdo_pgsql, pdo_sqlite)')
                            ->validate()
                                ->ifNotInArray(['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'])
                                ->thenInvalid('Invalid database driver "%s". Supported drivers: pdo_mysql, pdo_pgsql, pdo_sqlite')
                            ->end()
                        ->end()
                        ->scalarNode('host')
                            ->defaultValue('localhost')
                            ->info('Database host address or hostname')
                        ->end()
                        ->integerNode('port')
                            ->defaultNull()
                            ->info('Database port number (uses driver default if not specified)')
                            ->validate()
                                ->ifTrue(fn ($value) => $value !== null && ($value < 1 || $value > 65535))
                                ->thenInvalid('Port must be between 1 and 65535')
                            ->end()
                        ->end()
                        ->scalarNode('dbname')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('Database name to analyze and reverse engineer')
                        ->end()
                        ->scalarNode('user')
                            ->defaultValue('root')
                            ->info('Database username for authentication')
                        ->end()
                        ->scalarNode('password')
                            ->defaultValue('')
                            ->info('Database password for authentication')
                        ->end()
                        ->scalarNode('charset')
                            ->defaultValue('utf8mb4')
                            ->info('Database charset for connection')
                        ->end()
                    ->end()
                ->end()
                // Entity generation configuration section
                ->arrayNode('generation')
                    ->info('Entity generation configuration options')
                    ->children()
                        ->scalarNode('namespace')
                            ->defaultValue('App\\Entity')
                            ->info('Base namespace for generated entity classes')
                            ->validate()
                                ->ifTrue(fn ($value) => ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value))
                                ->thenInvalid('Invalid namespace format "%s"')
                            ->end()
                        ->end()
                        ->scalarNode('output_dir')
                            ->defaultValue('src/Entity')
                            ->info('Output directory for generated entity files')
                        ->end()
                        ->arrayNode('tables')
                            ->scalarPrototype()->end()
                            ->info('List of specific tables to process (processes all tables if empty)')
                        ->end()
                        ->arrayNode('exclude_tables')
                            ->scalarPrototype()->end()
                            ->info('List of tables to exclude from processing')
                        ->end()
                        ->booleanNode('generate_repository')
                            ->defaultTrue()
                            ->info('Whether to generate Repository classes for entities')
                        ->end()
                        ->booleanNode('use_annotations')
                            ->defaultFalse()
                            ->info('Use Doctrine annotations instead of PHP 8 attributes')
                        ->end()
                        ->scalarNode('enum_namespace')
                            ->defaultValue('App\\Enum')
                            ->info('Base namespace for generated enum classes')
                            ->validate()
                                ->ifTrue(fn ($value) => ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value))
                                ->thenInvalid('Invalid enum namespace format "%s"')
                            ->end()
                        ->end()
                        ->scalarNode('enum_output_dir')
                            ->defaultValue('src/Enum')
                            ->info('Output directory for generated enum class files')
                        ->end()
                        ->arrayNode('many_to_many')
                            ->info('ManyToMany relationship configuration options')
                            ->children()
                                ->enumNode('junction_strategy')
                                    ->values(['auto', 'skip_simple', 'always_entity'])
                                    ->defaultValue('auto')
                                    ->info('Strategy for handling junction tables (auto, skip_simple, always_entity)')
                                ->end()
                                ->integerNode('metadata_threshold')
                                    ->defaultValue(1)
                                    ->min(0)
                                    ->info('Metadata threshold - if junction has more than X non-FK columns, create entity')
                                ->end()
                                ->scalarNode('junction_table_pattern')
                                    ->defaultValue('%s_%s')
                                    ->info('Naming pattern for junction tables (table1_table2)')
                                    ->validate()
                                        ->ifTrue(fn ($value) => ! str_contains($value, '%s'))
                                        ->thenInvalid('Junction table pattern must contain at least one %s placeholder')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
