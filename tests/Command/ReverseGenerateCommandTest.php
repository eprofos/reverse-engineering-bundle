<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Tests\Command;

use Eprofos\ReverseEngineeringBundle\Command\ReverseGenerateCommand;
use Eprofos\ReverseEngineeringBundle\Exception\ReverseEngineeringException;
use Eprofos\ReverseEngineeringBundle\Service\ReverseEngineeringService;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for ReverseGenerateCommand.
 */
class ReverseGenerateCommandTest extends TestCase
{
    private ReverseGenerateCommand $command;

    private ReverseEngineeringService|MockObject $service;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ReverseEngineeringService::class);
        $this->command = new ReverseGenerateCommand($this->service, new NullLogger());

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandConfigurationIsCorrect(): void
    {
        // Assert
        $this->assertEquals('eprofos:reverse:generate', $this->command->getName());
        $this->assertStringContainsString('Generates Doctrine entities', $this->command->getDescription());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('tables'));
        $this->assertTrue($definition->hasOption('exclude'));
        $this->assertTrue($definition->hasOption('namespace'));
        $this->assertTrue($definition->hasOption('output-dir'));
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasOption('dry-run'));
    }

    public function testExecuteSuccessWithDefaultOptions(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users', 'posts']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->with($this->callback(fn ($options) => $options['tables'] === []
                       && $options['exclude'] === []
                       && $options['namespace'] === null
                       && $options['output_dir'] === null
                       && $options['force'] === false
                       && $options['dry_run'] === false))
            ->willReturn([
                'entities' => [
                    ['name' => 'User', 'table' => 'users', 'namespace' => 'App\\Entity'],
                    ['name' => 'Post', 'table' => 'posts', 'namespace' => 'App\\Entity'],
                ],
                'files'            => ['/path/to/User.php', '/path/to/Post.php'],
                'tables_processed' => 2,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Reverse Engineering', $output);
        $this->assertStringContainsString('Database connection validated', $output);
        $this->assertStringContainsString('2 table(s) found', $output);
        $this->assertStringContainsString('2 entity(ies) generated', $output);
        $this->assertStringContainsString('/path/to/User.php', $output);
        $this->assertStringContainsString('/path/to/Post.php', $output);
    }

    public function testExecuteWithSpecificTables(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users', 'posts', 'comments']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->with($this->callback(fn ($options) => $options['tables'] === ['users', 'posts']))
            ->willReturn([
                'entities' => [
                    ['name' => 'User', 'table' => 'users', 'namespace' => 'App\\Entity'],
                ],
                'files'            => ['/path/to/User.php'],
                'tables_processed' => 1,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--tables' => ['users', 'posts'],
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testExecuteWithExcludeTables(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users', 'posts', 'temp_table']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->with($this->callback(fn ($options) => $options['exclude'] === ['temp_table']))
            ->willReturn([
                'entities'         => [],
                'files'            => [],
                'tables_processed' => 0,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--exclude' => ['temp_table'],
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testExecuteWithDryRun(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->with($this->callback(fn ($options) => $options['dry_run'] === true))
            ->willReturn([
                'entities' => [
                    ['name' => 'User', 'table' => 'users', 'namespace' => 'App\\Entity'],
                ],
                'files'            => [],
                'tables_processed' => 1,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Preview of entities that would be generated', $output);
        $this->assertStringContainsString('Dry-run mode enabled', $output);
        $this->assertStringContainsString('User (table: users, namespace: App\\Entity)', $output);
    }

    public function testExecuteWithCustomOptions(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['products']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->with($this->callback(fn ($options) => $options['namespace'] === 'Custom\\Entity'
                       && $options['output_dir'] === 'custom/entities'
                       && $options['force'] === true))
            ->willReturn([
                'entities'         => [],
                'files'            => [],
                'tables_processed' => 0,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--namespace'  => 'Custom\\Entity',
            '--output-dir' => 'custom/entities',
            '--force'      => true,
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testExecuteFailsWhenDatabaseConnectionFails(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(false);

        $this->service
            ->expects($this->never())
            ->method('getAvailableTables');

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Database connection failed', $output);
    }

    public function testExecuteHandlesInvalidTables(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users', 'posts']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->willReturn([
                'entities'         => [],
                'files'            => [],
                'tables_processed' => 0,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--tables' => ['users', 'invalid_table'],
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('The following tables do not exist: invalid_table', $output);
    }

    public function testExecuteHandlesReverseEngineeringException(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->willThrowException(new ReverseEngineeringException('Generation error'));

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Generation failed: Generation error', $output);
    }

    public function testExecuteHandlesGenericException(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willThrowException(new Exception('Unexpected error'));

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Generation failed: Unexpected error', $output);
    }

    public function testExecuteShowsVerboseErrorTrace(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users']);

        $exception = new ReverseEngineeringException('Detailed error');
        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->willThrowException($exception);

        // Act
        $exitCode = $this->commandTester->execute([], [
            'verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE,
        ]);

        // Assert
        $this->assertEquals(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('🔍 Stack Trace:', $output);
    }

    public function testExecuteWithMultipleInvalidTables(): void
    {
        // Arrange
        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn(['users']);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->willReturn([
                'entities'         => [],
                'files'            => [],
                'tables_processed' => 0,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([
            '--tables' => ['invalid1', 'invalid2', 'users'],
        ]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('The following tables do not exist: invalid1, invalid2', $output);
    }

    public function testExecuteDisplaysCorrectTableCount(): void
    {
        // Arrange
        $availableTables = array_fill(0, 15, 'table');

        $this->service
            ->expects($this->once())
            ->method('validateDatabaseConnection')
            ->willReturn(true);

        $this->service
            ->expects($this->once())
            ->method('getAvailableTables')
            ->willReturn($availableTables);

        $this->service
            ->expects($this->once())
            ->method('generateEntities')
            ->willReturn([
                'entities'         => [],
                'files'            => [],
                'tables_processed' => 0,
            ]);

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('15 table(s) found in database', $output);
    }
}
