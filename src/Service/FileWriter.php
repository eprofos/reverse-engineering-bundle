<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Service;

use Eprofos\ReverseEngineeringBundle\Exception\FileWriteException;
use Exception;
use Psr\Log\LoggerInterface;

use function sprintf;
use function strlen;

/**
 * Service for writing generated entity and repository files to disk.
 *
 * This service handles the physical file creation process, including directory
 * management, file conflict resolution, and proper file formatting. It supports
 * configurable output directories and provides safety checks to prevent
 * accidental file overwrites.
 */
class FileWriter
{
    /**
     * FileWriter constructor.
     *
     * @param string          $projectDir Project root directory path
     * @param LoggerInterface $logger     Logger instance for operation tracking
     * @param array           $config     File writing configuration options
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly array $config = [],
    ) {
        $this->logger->info('FileWriter initialized', [
            'project_dir' => $projectDir,
            'config_keys' => array_keys($config),
        ]);
    }

    /**
     * Writes entity file to disk with proper error handling and logging.
     *
     * This method creates the entity file in the specified output directory,
     * handling directory creation, file conflict detection, and proper error
     * reporting. It supports both absolute and relative path configurations.
     *
     * @param array       $entity    Entity data including name, code, and metadata
     * @param string|null $outputDir Custom output directory (optional)
     * @param bool        $force     Whether to overwrite existing files
     *
     * @throws FileWriteException When file writing fails or conflicts occur
     *
     * @return string The absolute path of the created file
     */
    public function writeEntityFile(array $entity, ?string $outputDir = null, bool $force = false): string
    {
        $this->logger->info('Starting entity file write process', [
            'entity_name' => $entity['name'] ?? 'unknown',
            'filename'    => $entity['filename'] ?? 'unknown',
            'output_dir'  => $outputDir,
            'force'       => $force,
        ]);

        try {
            $outputDir ??= $this->config['output_dir'] ?? 'src/Entity';

            // If path is absolute, use as is, otherwise combine with projectDir
            if (str_starts_with($outputDir, '/')) {
                $fullOutputDir = $outputDir;
            } else {
                $fullOutputDir = $this->projectDir . '/' . ltrim($outputDir, '/');
            }

            $this->logger->debug('Resolved output directory', [
                'full_output_dir' => $fullOutputDir,
            ]);

            // Create directory if it doesn't exist
            $this->ensureDirectoryExists($fullOutputDir);

            $filePath = $fullOutputDir . '/' . $entity['filename'];

            // Check if file already exists
            if (file_exists($filePath) && ! $force) {
                $this->logger->warning('File already exists and force flag not set', [
                    'file_path' => $filePath,
                ]);

                throw new FileWriteException(
                    "File '{$entity['filename']}' already exists. Use --force option to overwrite.",
                );
            }

            // Write file
            $bytesWritten = file_put_contents($filePath, $entity['code']);

            if ($bytesWritten === false) {
                throw new FileWriteException(
                    "Failed to write file '{$filePath}'",
                );
            }

            return $filePath;
        } catch (Exception $e) {
            if ($e instanceof FileWriteException) {
                throw $e;
            }

            throw new FileWriteException(
                'Entity file write failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Writes repository file to disk with proper error handling and logging.
     *
     * This method creates the repository file in the specified output directory,
     * handling directory creation, file conflict detection, and proper error
     * reporting. It supports both absolute and relative path configurations.
     *
     * @param array       $repository Repository data including name, code, and metadata
     * @param string|null $outputDir  Custom output directory (optional)
     * @param bool        $force      Whether to overwrite existing files
     *
     * @throws FileWriteException When file writing fails or conflicts occur
     *
     * @return string The absolute path of the created file
     */
    public function writeRepositoryFile(array $repository, ?string $outputDir = null, bool $force = false): string
    {
        $this->logger->info('Starting repository file write process', [
            'repository_name' => $repository['name'] ?? 'unknown',
            'filename'        => $repository['filename'] ?? 'unknown',
            'output_dir'      => $outputDir,
            'force'           => $force,
        ]);

        try {
            $outputDir ??= 'src/Repository';

            // If path is absolute, use as is, otherwise combine with projectDir
            if (str_starts_with($outputDir, '/')) {
                $fullOutputDir = $outputDir;
            } else {
                $fullOutputDir = $this->projectDir . '/' . ltrim($outputDir, '/');
            }

            $this->logger->debug('Resolved repository output directory', [
                'full_output_dir' => $fullOutputDir,
            ]);

            // Create directory if it doesn't exist
            $this->ensureDirectoryExists($fullOutputDir);

            $filePath = $fullOutputDir . '/' . $repository['filename'];

            // Check if file already exists
            if (file_exists($filePath) && ! $force) {
                $this->logger->warning('Repository file already exists and force flag not set', [
                    'file_path' => $filePath,
                ]);

                throw new FileWriteException(
                    "Repository file '{$repository['filename']}' already exists. Use --force option to overwrite.",
                );
            }

            // Use repository code if provided, otherwise generate it
            $repositoryCode = $repository['code'] ?? $this->generateRepositoryCode($repository);

            $this->logger->debug('Writing repository file to disk', [
                'file_path'      => $filePath,
                'content_length' => strlen($repositoryCode),
            ]);

            // Write file
            $bytesWritten = file_put_contents($filePath, $repositoryCode);

            if ($bytesWritten === false) {
                $this->logger->error('Failed to write repository file', [
                    'file_path' => $filePath,
                ]);

                throw new FileWriteException(
                    "Failed to write repository file '{$filePath}'",
                );
            }

            $this->logger->info('Repository file written successfully', [
                'file_path'     => $filePath,
                'bytes_written' => $bytesWritten,
            ]);

            return $filePath;
        } catch (Exception $e) {
            $this->logger->error('Repository file write failed', [
                'repository_name' => $repository['name'] ?? 'unknown',
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            if ($e instanceof FileWriteException) {
                throw $e;
            }

            throw new FileWriteException(
                'Repository file write failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Validates that a directory can be used for writing files.
     *
     * This method performs comprehensive validation of the target directory
     * including existence checks, directory type validation, and write
     * permission verification. It helps prevent file writing errors by
     * validating the output location before attempting file operations.
     *
     * @param string $directory Directory path to validate (relative to project root)
     *
     * @throws FileWriteException When directory validation fails
     *
     * @return bool True if directory is valid and writable
     */
    public function validateOutputDirectory(string $directory): bool
    {
        $fullPath = $this->projectDir . '/' . ltrim($directory, '/');

        if (file_exists($fullPath) && ! is_dir($fullPath)) {
            throw new FileWriteException(
                "Path '{$directory}' exists but is not a directory",
            );
        }

        if (file_exists($fullPath) && ! is_writable($fullPath)) {
            throw new FileWriteException(
                "Directory '{$directory}' is not writable",
            );
        }

        return true;
    }

    /**
     * Creates a directory if it doesn't exist and validates write permissions.
     *
     * This method ensures that the target directory exists and is writable
     * before attempting file operations. It creates the directory structure
     * recursively if needed and validates proper permissions for file writing.
     *
     * @param string $directory Full directory path to create/validate
     *
     * @throws FileWriteException When directory creation fails or permissions are insufficient
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! file_exists($directory)) {
            if (! mkdir($directory, 0o755, true)) {
                throw new FileWriteException(
                    "Failed to create directory '{$directory}'",
                );
            }
        }

        if (! is_writable($directory)) {
            throw new FileWriteException(
                "Directory '{$directory}' is not writable",
            );
        }
    }

    /**
     * Generates repository code.
     */
    private function generateRepositoryCode(array $repository): string
    {
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace %s;

            use %s;
            use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
            use Doctrine\Persistence\ManagerRegistry;

            /**
             * Repository for entity %s.
             *
             * @extends ServiceEntityRepository<%s>
             */
            class %s extends ServiceEntityRepository
            {
                public function __construct(ManagerRegistry $registry)
                {
                    parent::__construct($registry, %s::class);
                }

                /**
                 * Finds an entity by its ID.
                 *
                 * @param mixed $id
                 * @param int|null $lockMode
                 * @param int|null $lockVersion
                 * @return %s|null
                 */
                public function find($id, $lockMode = null, $lockVersion = null): ?%s
                {
                    return parent::find($id, $lockMode, $lockVersion);
                }

                /**
                 * Finds all entities.
                 *
                 * @return %s[]
                 */
                public function findAll(): array
                {
                    return parent::findAll();
                }

                /**
                 * Finds entities by criteria.
                 *
                 * @param array $criteria
                 * @param array|null $orderBy
                 * @param int|null $limit
                 * @param int|null $offset
                 * @return %s[]
                 */
                public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
                {
                    return parent::findBy($criteria, $orderBy, $limit, $offset);
                }

                /**
                 * Finds one entity by criteria.
                 *
                 * @param array $criteria
                 * @param array|null $orderBy
                 * @return %s|null
                 */
                public function findOneBy(array $criteria, ?array $orderBy = null): ?%s
                {
                    return parent::findOneBy($criteria, $orderBy);
                }
            }
            PHP;

        $entityName = basename(str_replace('\\', '/', $repository['entity_class']));

        return sprintf(
            $template,
            $repository['namespace'],           // repository namespace
            $repository['entity_class'],        // entity use statement
            $entityName,                        // entity name in comment
            $entityName,                        // entity name in @extends
            $repository['name'],                // repository class name
            $entityName,                        // entity name in constructor
            $entityName,                        // find() return type
            $entityName,                        // find() return type
            $entityName,                        // findAll() return type
            $entityName,                        // findBy() return type
            $entityName,                        // findOneBy() return type
            $entityName,                         // findOneBy() return type
        );
    }
}
