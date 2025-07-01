# ReverseEngineeringBundle

[![Latest Version](https://img.shields.io/badge/version-0.1.0-blue.svg)](https://github.com/eprofos/reverse-engineering-bundle/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net/)
[![Symfony Version](https://img.shields.io/badge/symfony-%5E7.0-green.svg)](https://symfony.com/)
[![Tests](https://img.shields.io/badge/tests-144%2B-brightgreen.svg)](./tests)
[![Coverage](https://img.shields.io/badge/coverage-%3E95%25-brightgreen.svg)](./coverage)

**Advanced Symfony Bundle for Database Reverse Engineering** - Automatically generate Doctrine entities from existing databases with advanced features and comprehensive testing.

**Developed** to simplify legacy application migration and modernization with enterprise-grade reliability.

## 🚀 Key Features

- **Multi-Database Support**: MySQL, PostgreSQL, SQLite with comprehensive type mapping
- **Automatic Entity Generation**: PHP 8+ attributes with intelligent property mapping
- **Advanced Type Mapping**: Smart conversion of database types to PHP/Doctrine types
- **🆕 PHP 8.1 Backed Enum Support**: Automatic generation of type-safe enum classes from MySQL ENUM columns
- **Relationship Detection**: Automatic ManyToOne relationship generation
- **Repository Generation**: Doctrine repositories with customizable templates
- **Intuitive CLI Interface**: Rich command-line interface with extensive options and validation
- **Dry-Run Mode**: Preview changes before applying them with detailed output
- **Custom Namespaces**: Flexible namespace configuration for organized entity structure
- **Performance Optimized**: Efficient processing of large databases with batch operations

## 📋 Requirements

- **PHP**: 8.1 or higher with required extensions
- **Symfony**: 7.0 or higher with full framework support
- **Doctrine DBAL**: 3.0 or higher for database abstraction
- **Doctrine ORM**: 2.15 or higher for entity management
- **PHP Extensions**: PDO with appropriate drivers for your database system
- **Memory**: Minimum 128MB for processing medium-sized databases

## 📦 Installation

### Install via Composer

```bash
# Install the bundle
composer require eprofos/reverse-engineering-bundle
```

### Register the Bundle

The bundle should be automatically registered in `config/bundles.php`. If not, add it manually:

```php
<?php
// config/bundles.php
return [
    // ... other bundles
    Eprofos\ReverseEngineeringBundle\ReverseEngineeringBundle::class => ['all' => true],
];
```

## 🔧 Database Support

### Supported Database Systems

| Database   | Version | Driver     | ENUM/SET | Relations | Status |
|------------|---------|------------|----------|-----------|--------|
| MySQL      | 5.7+    | pdo_mysql  | ✅ Tested | ✅ ManyToOne | ✅ Complete |
| PostgreSQL | 12+     | pdo_pgsql  | ❌ Todo   | ❌ Not tested   | ❌ Todo |
| SQLite     | 3.25+   | pdo_sqlite | ❌ Not tested   | ❌ Not tested   | ❌ Not tested |

### Relationship Support Progress

- ✅ **ManyToOne**: Fully implemented and tested
- ❌ **OneToMany**: Todo - planned for next release
- ❌ **OneToOne**: Todo - planned for future release
- ❌ **ManyToMany**: Todo - planned for future release

### Type Mappers

- ✅ **MySQLTypeMapper**: Complete implementation
- ❌ **PostgreSQLTypeMapper**: Todo - in development
- ❌ **SQLiteTypeMapper**: Todo - planned for future release

## ⚙️ Configuration

Create your configuration file at `config/packages/reverse_engineering.yaml`:

### Basic Configuration

```yaml
reverse_engineering:
    database:
        # Database connection (required)
        driver: pdo_mysql          # pdo_mysql, pdo_pgsql, pdo_sqlite
        host: localhost
        port: 3306
        dbname: your_database_name
        user: your_username
        password: your_password
        charset: utf8mb4
    
    generation:
        # Entity generation settings
        namespace: App\Entity       # Namespace for generated entities
        output_dir: src/Entity      # Output directory for entities
        generate_repository: true   # Generate repository classes
        use_annotations: false      # Use PHP 8 attributes (recommended)
        
        # Enum generation settings (MySQL only)
        enum_namespace: App\Enum    # Namespace for generated enum classes
        enum_output_dir: src/Enum   # Output directory for enum classes
        
        # Table filtering
        tables: []                  # Specific tables to process (empty = all)
        exclude_tables:             # Tables to exclude
            - doctrine_migration_versions
            - messenger_messages
```

### PostgreSQL Configuration

```yaml
reverse_engineering:
    database:
        driver: pdo_pgsql
        host: localhost
        port: 5432
        dbname: your_database_name
        user: your_username
        password: your_password
        charset: utf8
```

### SQLite Configuration

```yaml
reverse_engineering:
    database:
        driver: pdo_sqlite
        path: '%kernel.project_dir%/var/data.db'
```

### Advanced Configuration

```yaml
reverse_engineering:
    database:
        driver: pdo_mysql
        host: '%env(DB_HOST)%'
        port: '%env(int:DB_PORT)%'
        dbname: '%env(DB_NAME)%'
        user: '%env(DB_USER)%'
        password: '%env(DB_PASSWORD)%'
        charset: utf8mb4
        options:
            1002: "SET SESSION sql_mode=''"  # PDO::MYSQL_ATTR_INIT_COMMAND
    
    generation:
        namespace: App\Entity
        output_dir: src/Entity
        generate_repository: true
        use_annotations: false
        
        # Enum configuration
        enum_namespace: App\Enum
        enum_output_dir: src/Enum
        
        # Table filtering
        tables: []
        exclude_tables: 
            - doctrine_migration_versions
            - messenger_messages
            - cache_items
            - sessions
```

## 🎯 Usage

### Basic Command

```bash
# Generate all entities with default settings
php bin/console eprofos:reverse:generate
```

### Step-by-Step Usage

#### 1. Preview Changes (Dry Run)

```bash
# Preview what will be generated without creating files
php bin/console eprofos:reverse:generate --dry-run --verbose
```

#### 2. Generate Specific Tables

```bash
# Generate entities for specific tables
php bin/console eprofos:reverse:generate --tables=users --tables=products
```

#### 3. Exclude System Tables

```bash
# Exclude system and cache tables
php bin/console eprofos:reverse:generate --exclude=migrations --exclude=cache
```

#### 4. Custom Namespace and Directory

```bash
# Generate with custom namespace and output directory
php bin/console eprofos:reverse:generate \
    --namespace="App\Entity\Custom" \
    --output-dir="src/Entity/Custom"
```

#### 5. Force Overwrite Existing Files

```bash
# Force overwrite existing entity files
php bin/console eprofos:reverse:generate --force
```

### Advanced Usage Examples

#### Modular Entity Generation

```bash
# User module
php bin/console eprofos:reverse:generate \
    --tables=users \
    --tables=user_profiles \
    --namespace="App\Entity\User" \
    --output-dir="src/Entity/User"

# Product module
php bin/console eprofos:reverse:generate \
    --tables=products \
    --tables=categories \
    --namespace="App\Entity\Product" \
    --output-dir="src/Entity/Product"
```

### Command Options Reference

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--tables` | `-t` | Specific tables to process | All tables |
| `--exclude` | `-e` | Tables to exclude | None |
| `--namespace` | `-n` | Entity namespace | From config |
| `--output-dir` | `-o` | Output directory | From config |
| `--force` | `-f` | Force overwrite existing files | false |
| `--dry-run` | `-d` | Preview mode (no files created) | false |
| `--verbose` | `-v` | Verbose output | false |

## 📋 Generated Entity Examples

### MySQL Database Example

**Database Schema:**
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE,
    parent_id INT,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    category_id INT NOT NULL,
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
```

### Generated User Entity

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\UserStatusEnum;
use DateTimeInterface;

/**
 * User entity generated automatically from database table 'users'
 */
#[ORM\Entity(repositoryClass: App\Repository\UserRepository::class)]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $email;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', enumType: UserStatusEnum::class, nullable: false)]
    private UserStatusEnum $status = UserStatusEnum::PENDING;

    #[ORM\Column(type: 'datetime', nullable: false)]
    private DateTimeInterface $createdAt;

    // Getters and setters...
    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getStatus(): UserStatusEnum
    {
        return $this->status;
    }

    public function setStatus(UserStatusEnum $status): static
    {
        $this->status = $status;
        return $this;
    }

    // ... other getters and setters
}
```

### Generated Enum Class

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * UserStatusEnum generated automatically from MySQL ENUM column
 */
enum UserStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
}
```

### Generated Product Entity with ManyToOne Relationship

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\ProductStatusEnum;
use DateTimeInterface;

/**
 * Product entity generated automatically from database table 'products'
 */
#[ORM\Entity(repositoryClass: App\Repository\ProductRepository::class)]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: false)]
    private string $price;

    #[ORM\Column(type: 'string', enumType: ProductStatusEnum::class, nullable: false)]
    private ProductStatusEnum $status = ProductStatusEnum::DRAFT;

    #[ORM\Column(type: 'datetime', nullable: false)]
    private DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false)]
    private Category $category;

    // Getters and setters...
    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;
        return $this;
    }

    // ... other getters and setters
}
```

## 🆕 PHP 8.1 Backed Enum Support

The bundle automatically generates PHP 8.1 backed enum classes from MySQL ENUM columns, providing type safety and better IDE support.

### Type-Safe Usage Example

```php
// Type-safe with IDE autocompletion
$user = new User();
$user->setStatus(UserStatusEnum::ACTIVE);

// Compile-time error prevention
$user->setStatus('invalid'); // PHP Fatal Error

// Easy value checking
if ($user->getStatus() === UserStatusEnum::ACTIVE) {
    // Handle active user
}

// All enum values available
foreach (UserStatusEnum::cases() as $status) {
    echo $status->value . PHP_EOL;
}
```

### Enum Configuration

Configure enum generation in your configuration file:

```yaml
reverse_engineering:
    generation:
        # Namespace for generated enum classes
        enum_namespace: App\Enum
        
        # Output directory for enum classes
        enum_output_dir: src/Enum
```

## 🔧 Supported Data Types

### MySQL Data Types (Fully Tested ✅)

| MySQL Type | PHP Type | Doctrine Type | Notes |
|------------|----------|---------------|-------|
| `INT`, `INTEGER`, `BIGINT`, `SMALLINT`, `TINYINT` | `int` | `integer` | Auto-increment detection |
| `FLOAT`, `DOUBLE`, `REAL` | `float` | `float` | Precision preserved |
| `DECIMAL`, `NUMERIC` | `string` | `decimal` | Precision and scale preserved |
| `BOOLEAN`, `BOOL` | `bool` | `boolean` | Default values supported |
| `DATE`, `DATETIME`, `TIMESTAMP`, `TIME` | `DateTimeInterface` | `datetime` | Timezone aware |
| `VARCHAR`, `CHAR`, `TEXT`, `LONGTEXT` | `string` | `string` | Length constraints |
| `JSON` | `array` | `json` | Native JSON support |
| `BLOB`, `LONGBLOB` | `string` | `blob` | Binary data |
| `ENUM` | `PHP 8.1 Enum` | `string` | **🆕 Auto-generated backed enum classes** |
| `SET` | `string` | `string` | Values documented in comments |
| `YEAR` | `int` | `integer` | Year validation |

### PostgreSQL Data Types (Todo ❌)

| PostgreSQL Type | PHP Type | Doctrine Type | Status |
|-----------------|----------|---------------|--------|
| `INTEGER`, `BIGINT`, `SMALLINT` | `int` | `integer` | ❌ Todo |
| `REAL`, `DOUBLE PRECISION` | `float` | `float` | ❌ Todo |
| `NUMERIC`, `DECIMAL` | `string` | `decimal` | ❌ Todo |
| `BOOLEAN` | `bool` | `boolean` | ❌ Todo |
| `DATE`, `TIMESTAMP`, `TIME` | `DateTimeInterface` | `datetime` | ❌ Todo |
| `VARCHAR`, `CHAR`, `TEXT` | `string` | `string` | ❌ Todo |
| `JSON`, `JSONB` | `array` | `json` | ❌ Todo |
| `UUID` | `string` | `guid` | ❌ Todo |
| `ARRAY` | `array` | `simple_array` | ❌ Todo |

### SQLite Data Types (Todo ❌)

| SQLite Type | PHP Type | Doctrine Type | Status |
|-------------|----------|---------------|--------|
| `INTEGER` | `int` | `integer` | ❌ Todo |
| `REAL` | `float` | `float` | ❌ Todo |
| `TEXT` | `string` | `string` | ❌ Todo |
| `BLOB` | `string` | `blob` | ❌ Todo |

## 🔗 Relationship Support

### ManyToOne Relationships (✅ Implemented)

Automatically detected from foreign key constraints in MySQL:

```php
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
private User $user;
```

### OneToMany Relationships (❌ Todo)

*Feature in development - will be available in version 0.2.0*

### OneToOne Relationships (❌ Todo)

*Feature planned for future release*

### ManyToMany Relationships (❌ Todo) 

*Feature in development - will be available in version 0.2.0*

### Self-Referencing Relationships (✅ Implemented)

Supported for hierarchical data structures:

```php
#[ORM\ManyToOne(targetEntity: Category::class)]
#[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
private ?Category $parent = null;
```

## 🚀 Roadmap and Todo List

### Version 0.2.0 (Next Release)

- [ ] **OneToMany Relations**: Automatic inverse relationship detection and generation
- [ ] **ManyToMany Support**: Junction table detection and entity generation
- [ ] **Command for Entity Updates**: Update existing entities without recreation

### Version 0.3.0 (Future)

- [ ] **PostgreSQL Support**: Complete PostgreSQL type mapper and relationship detection
- [ ] **SQLite Support**: Complete SQLite type mapper and relationship detection

### Version 0.4.0 (Future)

- [ ] **OneToOne Relationships**: Support for one-to-one relationships
- [ ] **Enhanced ENUM/SET Support**: Better handling for PostgreSQL and custom types
- [ ] **Custom Type Mapping**: User-defined type mappings for special cases
- [ ] **Detailed Documentation**: Complete API documentation and guides

### Long-term Goals

- [ ] **Doctrine Migrations Generation**: Automatic migration file creation
- [ ] **Advanced Relationship Detection**: Complex relationship pattern recognition
- [ ] **Performance Optimizations**: Enhanced performance for very large databases
- [ ] **API Platform Integration**: Automatic API Platform resource configuration generation

## 📝 Best Practices

### 1. Backup Existing Entities

```bash
# Always backup before using --force
cp -r src/Entity src/Entity.backup.$(date +%Y%m%d_%H%M%S)
php bin/console eprofos:reverse:generate --force
```

### 2. Use Dry-Run for Preview

```bash
# Preview changes before applying
php bin/console eprofos:reverse:generate --dry-run --verbose
```

### 3. Exclude System Tables

Configure exclusions in your configuration file:

```yaml
reverse_engineering:
    generation:
        exclude_tables:
            - doctrine_migration_versions
            - messenger_messages
            - cache_items
            - sessions
```

### 4. Organize with Namespaces

```bash
# Use specific namespaces for organization
php bin/console eprofos:reverse:generate \
    --namespace="App\Entity\User" \
    --output-dir="src/Entity/User" \
    --tables=users --tables=user_profiles
```

### 5. Validate Generated Entities

```bash
# Validate syntax and Doctrine mapping
find src/Entity -name "*.php" -exec php -l {} \;
php bin/console doctrine:schema:validate
```

## 🚨 Error Handling

The bundle provides comprehensive error handling with specific exceptions:

### Database Connection Issues

```bash
# Test database connection
php bin/console eprofos:reverse:generate --dry-run --tables=non_existent_table
```

### Permission Problems

```bash
# Check file permissions
ls -la src/Entity/
chmod 755 src/Entity/
```

### Memory Limitations

```bash
# Increase memory limit for large databases
php -d memory_limit=512M bin/console eprofos:reverse:generate
```

### Debug Mode

Use verbose output for detailed information:

```bash
# Verbose output with detailed logging
php bin/console eprofos:reverse:generate -v

# Extra verbose for debugging
php bin/console eprofos:reverse:generate -vv

# Debug level output
php bin/console eprofos:reverse:generate -vvv
```
