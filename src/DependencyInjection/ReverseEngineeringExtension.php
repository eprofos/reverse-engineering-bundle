<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension class for ReverseEngineering bundle configuration loading.
 *
 * This extension handles the loading and processing of bundle configuration,
 * service definitions, and parameter registration within the Symfony container.
 * It processes YAML configuration files and makes bundle services available
 * throughout the application.
 */
class ReverseEngineeringExtension extends Extension
{
    /**
     * Loads bundle configuration and services into the container.
     *
     * This method is responsible for loading the bundle's service definitions
     * from YAML configuration files and processing user-provided configuration
     * to set up container parameters. It handles the complete initialization
     * of the reverse engineering bundle within the Symfony application.
     *
     * The method performs the following operations:
     * 1. Loads service definitions from services.yaml
     * 2. Processes and validates user configuration
     * 3. Sets container parameters for bundle configuration
     * 4. Makes configuration available to bundle services
     *
     * @param array            $configs   Configuration arrays from various sources (app config, bundle defaults)
     * @param ContainerBuilder $container The container builder instance for service registration
     *
     * @throws Exception When configuration loading or processing fails
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Load service definitions from YAML configuration
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );

        // Load the main services configuration file
        $loader->load('services.yaml');

        // Process and validate bundle configuration
        $configuration = $this->getConfiguration($configs, $container);
        $config        = $this->processConfiguration($configuration, $configs);

        // Register configuration parameters in the container
        // These parameters will be available to bundle services via dependency injection
        $container->setParameter('reverse_engineering.config', $config);
        $container->setParameter('reverse_engineering.config.database', $config['database'] ?? []);
        $container->setParameter('reverse_engineering.config.generation', $config['generation'] ?? []);

        // Set additional derived parameters for convenience
        $container->setParameter('reverse_engineering.entity_namespace', $config['generation']['namespace'] ?? 'App\\Entity');
        $container->setParameter('reverse_engineering.entity_output_dir', $config['generation']['output_dir'] ?? 'src/Entity');
        $container->setParameter('reverse_engineering.enum_namespace', $config['generation']['enum_namespace'] ?? 'App\\Enum');
        $container->setParameter('reverse_engineering.enum_output_dir', $config['generation']['enum_output_dir'] ?? 'src/Enum');
    }

    /**
     * Returns the configuration instance for this bundle.
     *
     * This method creates and returns a Configuration instance that defines
     * the structure and validation rules for the bundle's configuration tree.
     * The Configuration class handles validation, default values, and
     * documentation for all configuration options.
     *
     * @param array            $config    Configuration array from various sources
     * @param ContainerBuilder $container The container builder instance
     *
     * @return Configuration The configuration instance for processing bundle config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    /**
     * Returns the bundle alias used in configuration files.
     *
     * This alias is used as the root key in configuration files (e.g., config/packages/reverse_engineering.yaml)
     * and determines how users reference this bundle's configuration in their applications.
     * The alias must be unique across all installed bundles.
     *
     * @return string The bundle alias for configuration reference
     */
    public function getAlias(): string
    {
        return 'reverse_engineering';
    }
}
