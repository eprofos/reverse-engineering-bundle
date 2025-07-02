# Docker Test Environment

This Docker Compose setup provides a containerized PHP environment for running tests.

## Prerequisites

- Docker
- Docker Compose

## Usage

### Start the PHP container

```bash
docker-compose up -d
```

### Run tests inside the container

```bash
# Run all tests
docker-compose exec php vendor/bin/phpunit

# Run specific test suites
docker-compose exec php vendor/bin/phpunit --testsuite=Unit
docker-compose exec php vendor/bin/phpunit --testsuite=Integration

# Run with coverage
docker-compose exec php vendor/bin/phpunit --coverage-text

# Run code quality checks
docker-compose exec php vendor/bin/phpstan analyse src --level=8
docker-compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
```

### Interactive shell access

```bash
docker-compose exec php bash
```

### Stop the container

```bash
docker-compose down
```

## What's included

- PHP 8.2 CLI
- Composer
- PDO SQLite (for unit tests)
- Xdebug (for coverage)
- All project dependencies installed automatically

## Notes

- The project directory is mounted at `/app` inside the container
- Composer dependencies are installed automatically when the container starts
- Tests use SQLite in-memory databases for isolation
- Coverage reports require Xdebug which is pre-configured
