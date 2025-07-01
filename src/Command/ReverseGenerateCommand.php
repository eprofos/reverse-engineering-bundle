<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Command;

use Eprofos\ReverseEngineeringBundle\Service\ReverseEngineeringService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Command to generate Doctrine entities from an existing database.
 *
 * This command provides a comprehensive interface for reverse engineering
 * database schemas into Doctrine entity classes. It supports various options
 * for customizing the generation process including table filtering, namespace
 * configuration, output formatting, and dry-run capabilities.
 */
#[AsCommand(
    name: 'eprofos:reverse:generate',
    description: 'Generates Doctrine entities from an existing database',
)]
class ReverseGenerateCommand extends Command
{
    /**
     * ReverseGenerateCommand constructor.
     *
     * @param ReverseEngineeringService $reverseEngineeringService Main service for reverse engineering
     * @param LoggerInterface           $logger                    Logger instance for command execution tracking
     */
    public function __construct(
        private readonly ReverseEngineeringService $reverseEngineeringService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();

        $this->logger->info('ReverseGenerateCommand initialized');
    }

    /**
     * Configures the command with options and help text.
     *
     * This method sets up all command-line options and arguments that users
     * can provide when executing the reverse engineering command. It defines
     * the command interface and provides comprehensive help documentation.
     *
     * Available options include:
     * - Table filtering (include/exclude specific tables)
     * - Namespace and output directory customization
     * - Force overwrite and dry-run modes
     */
    protected function configure(): void
    {
        $this->logger->debug('Configuring eprofos:reverse:generate command options');

        $this
            ->addOption(
                'tables',
                't',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specific tables to process (processes all tables if not specified)',
            )
            ->addOption(
                'exclude',
                'x',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Tables to exclude from processing (useful for system tables)',
            )
            ->addOption(
                'namespace',
                'ns',
                InputOption::VALUE_OPTIONAL,
                'Custom namespace for generated entities (overrides bundle configuration)',
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Custom output directory for entity files (overrides bundle configuration)',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force overwriting of existing files without confirmation',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Preview mode: show what would be generated without creating files',
            )
            ->setHelp(
                'This command analyzes an existing database schema and automatically ' .
                'generates the corresponding Doctrine entity classes with proper ' .
                'annotations, relationships, and type mappings. It supports MySQL, ' .
                'PostgreSQL, and SQLite databases with advanced features like ' .
                'ENUM handling, spatial types, and lifecycle callbacks.',
            );

        $this->logger->info('Command configuration completed successfully');
    }

    /**
     * Executes the reverse engineering command.
     *
     * This method orchestrates the complete reverse engineering process,
     * including database validation, table discovery, option processing,
     * and entity generation with comprehensive user feedback.
     *
     * @param InputInterface  $input  Command input interface
     * @param OutputInterface $output Command output interface
     *
     * @return int Command exit code (SUCCESS or FAILURE)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $io        = new SymfonyStyle($input, $output);

        $this->logger->info('Command execution started', [
            'command'   => $this->getName(),
            'arguments' => $input->getArguments(),
            'options'   => $input->getOptions(),
        ]);

        $io->title('🔄 Reverse Engineering - Entity Generation');

        try {
            // 1. Validate database connection
            $io->section('🔍 Validating database connection...');
            $this->logger->info('Starting database connection validation');

            if (! $this->reverseEngineeringService->validateDatabaseConnection()) {
                $this->logger->error('Database connection validation failed');
                $io->error('❌ Database connection failed');

                return Command::FAILURE;
            }

            $this->logger->info('Database connection validated successfully');
            $io->success('✅ Database connection validated');

            // 2. List available tables
            $this->logger->info('Retrieving available database tables');
            $availableTables = $this->reverseEngineeringService->getAvailableTables();
            $io->text(sprintf('📊 %d table(s) found in database', count($availableTables)));

            $this->logger->info('Available tables retrieved', [
                'tables_count' => count($availableTables),
                'tables'       => $availableTables,
            ]);

            // 3. Prepare options
            $options = [
                'tables'     => $input->getOption('tables'),
                'exclude'    => $input->getOption('exclude'),
                'namespace'  => $input->getOption('namespace'),
                'output_dir' => $input->getOption('output-dir'),
                'force'      => $input->getOption('force'),
                'dry_run'    => $input->getOption('dry-run'),
            ];

            $this->logger->info('Command options prepared', [
                'options' => $options,
            ]);

            // 4. Validate specified tables
            if (! empty($options['tables'])) {
                $invalidTables = array_diff($options['tables'], $availableTables);

                if (! empty($invalidTables)) {
                    $this->logger->warning('Invalid tables specified', [
                        'invalid_tables' => $invalidTables,
                    ]);

                    $io->warning(sprintf(
                        'The following tables do not exist: %s',
                        implode(', ', $invalidTables),
                    ));
                }
            }

            // 5. Generate entities
            $io->section('⚙️ Generating entities...');
            $this->logger->info('Starting entity generation process');

            $result = $this->reverseEngineeringService->generateEntities($options);

            $this->logger->info('Entity generation completed', [
                'result' => $result,
            ]);

            // 6. Display results and provide user feedback
            $executionTime = microtime(true) - $startTime;

            if ($options['dry_run']) {
                $this->logger->info('Dry run completed, displaying preview', [
                    'entities_count' => count($result['entities']),
                    'execution_time' => round($executionTime, 2),
                ]);

                $io->section('📋 Preview of entities that would be generated:');

                foreach ($result['entities'] as $entity) {
                    $io->text(sprintf(
                        '- %s (table: %s, namespace: %s)',
                        $entity['name'],
                        $entity['table'],
                        $entity['namespace'],
                    ));
                }

                $io->note('Dry-run mode enabled: no files were created');
                $io->text(sprintf('⏱️ Analysis completed in %.2f seconds', $executionTime));
            } else {
                $this->logger->info('Entity generation completed successfully', [
                    'entities_generated' => count($result['entities']),
                    'files_created'      => count($result['files']),
                    'execution_time'     => round($executionTime, 2),
                ]);

                $io->success(sprintf(
                    '✅ %d entity(ies) generated successfully in %.2f seconds!',
                    count($result['entities']),
                    $executionTime,
                ));

                $io->section('📁 Files created:');

                foreach ($result['files'] as $file) {
                    $io->text("- {$file}");
                }

                // Provide additional information about generated entities
                if (count($result['entities']) > 0) {
                    $io->section('📊 Generation Summary:');
                    $io->text(sprintf('• Tables processed: %d', $result['tables_processed']));
                    $io->text(sprintf('• Entities generated: %d', count($result['entities'])));
                    $io->text(sprintf('• Files written: %d', count($result['files'])));
                }
            }

            $this->logger->info('Command execution completed successfully', [
                'total_execution_time' => round($executionTime, 2),
                'exit_code'            => 'SUCCESS',
            ]);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $this->logger->error('Command execution failed', [
                'error_message'  => $e->getMessage(),
                'error_class'    => $e::class,
                'execution_time' => round($executionTime, 2),
                'error_trace'    => $e->getTraceAsString(),
            ]);

            $io->error('❌ Generation failed: ' . $e->getMessage());

            // Provide detailed error information in verbose mode
            if ($output->isVerbose()) {
                $io->section('🐛 Detailed Error Information:');
                $io->text('Error Type: ' . $e::class);
                $io->text('Error Code: ' . $e->getCode());
                $io->text('File: ' . $e->getFile() . ':' . $e->getLine());

                $io->section('🔍 Stack Trace:');
                $io->text($e->getTraceAsString());
            } else {
                $io->note('Use -v option for detailed error information');
            }

            return Command::FAILURE;
        } finally {
            // Log final execution statistics regardless of success or failure
            $finalExecutionTime = microtime(true) - $startTime;
            $this->logger->info('Command execution finished', [
                'total_execution_time' => round($finalExecutionTime, 2),
                'memory_peak_usage'    => memory_get_peak_usage(true),
                'memory_current_usage' => memory_get_usage(true),
            ]);
        }
    }
}
