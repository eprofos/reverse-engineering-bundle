<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Service;

use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Exception;
use Psr\Log\LoggerInterface;

use function count;

/**
 * Main service for reverse engineering orchestration and coordination.
 *
 * This service acts as the primary coordinator for the reverse engineering process,
 * orchestrating the interaction between database analysis, metadata extraction,
 * entity generation, and file writing. It provides the high-level interface
 * for converting database schemas into Doctrine entity classes.
 */
class ReverseEngineeringService
{
    /**
     * ReverseEngineeringService constructor.
     *
     * @param DatabaseAnalyzer  $databaseAnalyzer  Service for database structure analysis
     * @param MetadataExtractor $metadataExtractor Service for metadata extraction
     * @param EntityGenerator   $entityGenerator   Service for entity code generation
     * @param FileWriter        $fileWriter        Service for writing generated files
     * @param LoggerInterface   $logger            Logger instance for operation tracking
     * @param array             $config            Service configuration options
     */
    public function __construct(
        private readonly DatabaseAnalyzer $databaseAnalyzer,
        private readonly MetadataExtractor $metadataExtractor,
        private readonly EntityGenerator $entityGenerator,
        private readonly FileWriter $fileWriter,
        private readonly LoggerInterface $logger,
        private readonly array $config = [],
    ) {
        // Register custom MySQL types during initialization
        MySQLTypeMapper::registerCustomTypes();

        $this->logger->info('ReverseEngineeringService initialized', [
            'config_keys' => array_keys($config),
        ]);
    }

    /**
     * Generates entities from the database tables.
     *
     * This method orchestrates the complete reverse engineering process:
     * 1. Analyzes database tables
     * 2. Extracts metadata from each table
     * 3. Generates entity classes
     * 4. Writes files to disk (unless in dry-run mode)
     *
     * @param array $options Generation options including tables, namespace, output directory
     *
     * @throws ReverseEngineeringException When any step of the process fails
     *
     * @return array Generation results with statistics and file information
     */
    public function generateEntities(array $options = []): array
    {
        $startTime = microtime(true);
        $this->logger->info('Starting reverse engineering process', [
            'options' => $options,
        ]);

        try {
            // 1. Analyze the database
            $this->logger->info('Step 1: Analyzing database tables');
            $tables = $this->databaseAnalyzer->analyzeTables(
                $options['tables'] ?? [],
                $options['exclude'] ?? [],
            );

            if (empty($tables)) {
                $this->logger->error('No tables found to process');

                throw new ReverseEngineeringException('No tables found to process');
            }

            $this->logger->info('Tables analysis completed', [
                'tables_count' => count($tables),
                'tables'       => $tables,
            ]);

            // 2. Extract metadata
            $this->logger->info('Step 2: Extracting table metadata');
            $metadata = [];

            foreach ($tables as $table) {
                $this->logger->debug('Extracting metadata for table', ['table' => $table]);
                $metadata[$table] = $this->metadataExtractor->extractTableMetadata($table, $tables);
            }

            $this->logger->info('Metadata extraction completed', [
                'tables_processed' => count($metadata),
            ]);

            // 3. Generate entities
            $this->logger->info('Step 3: Generating entity classes');
            $entities = [];

            foreach ($metadata as $tableName => $tableMetadata) {
                $this->logger->debug('Generating entity for table', ['table' => $tableName]);
                $entities[] = $this->entityGenerator->generateEntity(
                    $tableName,
                    $tableMetadata,
                    $options,
                );
            }

            $this->logger->info('Entity generation completed', [
                'entities_count' => count($entities),
            ]);

            // 4. Write files (if not in dry-run mode)
            $files    = [];
            $isDryRun = $options['dry_run'] ?? false;

            $this->logger->info('Step 4: Writing files to disk', [
                'dry_run' => $isDryRun,
            ]);

            if (! $isDryRun) {
                foreach ($entities as $entity) {
                    $this->logger->debug('Writing entity file', [
                        'entity'   => $entity['name'],
                        'filename' => $entity['filename'],
                    ]);

                    $filePath = $this->fileWriter->writeEntityFile(
                        $entity,
                        $options['output_dir'] ?? null,
                        $options['force'] ?? false,
                    );
                    $files[] = $filePath;

                    // Write repository if present
                    if (isset($entity['repository'])) {
                        $repositoryPath = $this->fileWriter->writeRepositoryFile(
                            $entity['repository'],
                            $options['output_dir'] ?? null,
                            $options['force'] ?? false,
                        );
                        $files[] = $repositoryPath;
                    }
                }
            }

            return [
                'entities'         => $entities,
                'files'            => $files,
                'tables_processed' => count($tables),
            ];
        } catch (Exception $e) {
            throw new ReverseEngineeringException(
                'Entity generation failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Validates database configuration and tests connectivity.
     *
     * This method verifies that the database connection is properly configured
     * and that the application can successfully connect to the database server.
     * It's typically called before starting the reverse engineering process
     * to ensure all prerequisites are met.
     *
     * @throws ReverseEngineeringException When database connection validation fails
     *
     * @return bool True if database connection is valid and working
     */
    public function validateDatabaseConnection(): bool
    {
        return $this->databaseAnalyzer->testConnection();
    }

    /**
     * Retrieves the list of available database tables for reverse engineering.
     *
     * This method queries the database to get a list of all user-defined tables
     * that are available for entity generation. System tables and metadata
     * tables are automatically filtered out to provide only relevant tables
     * for the reverse engineering process.
     *
     * @throws ReverseEngineeringException When table retrieval fails
     *
     * @return array List of available table names
     */
    public function getAvailableTables(): array
    {
        return $this->databaseAnalyzer->listTables();
    }

    /**
     * Retrieves detailed information about a specific database table.
     *
     * This method extracts comprehensive metadata for a single table including
     * column definitions, relationships, indexes, and constraints. It's useful
     * for inspecting table structure before or during the reverse engineering
     * process, or for providing detailed table information to users.
     *
     * @param string $tableName Name of the table to analyze
     *
     * @throws ReverseEngineeringException When table information retrieval fails
     *
     * @return array Complete table metadata including columns, relations, and indexes
     */
    public function getTableInfo(string $tableName): array
    {
        return $this->metadataExtractor->extractTableMetadata($tableName);
    }
}
