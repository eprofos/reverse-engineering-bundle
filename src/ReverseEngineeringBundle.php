<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle;

use Eprofos\ReverseEngineeringBundle\DependencyInjection\ReverseEngineeringExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Main bundle for database reverse engineering functionality.
 *
 * This bundle provides comprehensive database reverse engineering capabilities,
 * allowing automatic generation of Doctrine entities from existing database
 * schemas. It supports MySQL, PostgreSQL, and SQLite databases with advanced
 * features like relationship mapping, lifecycle callbacks, and enum handling.
 */
class ReverseEngineeringBundle extends Bundle
{
    /**
     * Builds the bundle and configures the container.
     *
     * This method is called during the container compilation phase and allows
     * the bundle to register compiler passes, modify service definitions,
     * or perform other container-level configurations. It's executed after
     * all extensions have been loaded but before the container is compiled.
     *
     * The method can be used to:
     * - Register compiler passes for advanced service configuration
     * - Add tagged service collectors
     * - Modify existing service definitions
     * - Register additional services programmatically
     *
     * @param ContainerBuilder $container The container builder instance for service registration
     */
    public function build(ContainerBuilder $container): void
    {
        // Call parent build method to ensure proper bundle initialization
        parent::build($container);

        // Additional container configuration can be added here if needed
        // For example: compiler passes, service modifications, etc.
    }

    /**
     * Returns the container extension for this bundle.
     *
     * This method provides the extension instance that handles configuration
     * loading and service registration for the bundle. The extension is
     * responsible for processing user configuration, loading service definitions,
     * and setting up container parameters.
     *
     * The extension handles:
     * - Loading service definitions from YAML files
     * - Processing and validating bundle configuration
     * - Setting container parameters for bundle services
     * - Registering bundle-specific services and aliases
     *
     * @return ReverseEngineeringExtension The container extension instance for configuration handling
     */
    public function getContainerExtension(): ReverseEngineeringExtension
    {
        return new ReverseEngineeringExtension();
    }
}
