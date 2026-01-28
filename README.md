# web_crawler

Database health monitoring system with ActiveRecord-style connectivity checks

## Overview

web_crawler provides comprehensive database health monitoring with special focus on PostgreSQL edge cases that can cause failures in certain conditions. The system validates database connectivity, monitors connection pools, tracks idle transactions, and alerts on critical issues.

## Features

### Core Functionality

- **ActiveRecordCheck**: Database health validation for PostgreSQL, MySQL, and SQLite
- **PostgreSQL-specific checks**:
  - Connection pool limit monitoring (max_connections)
  - Idle transaction detection
  - Database lock monitoring
  - Replication lag tracking (for standby servers)
  - SSL connection validation
- **Retry logic**: Automatic retry with configurable attempts and delays
- **Timeout handling**: Connection and statement timeout configuration
- **Multi-database support**: Monitor multiple databases simultaneously

### Alerting System

- **Multiple notification channels**: Logs, webhooks, email
- **Alert throttling**: Prevent alert spam with configurable time windows
- **Failure threshold**: Only alert after N consecutive failures
- **Detailed status reporting**: JSON output with timing and error details

## Installation

### Prerequisites

- PHP 8.0 or higher
- PDO extension with PostgreSQL driver (`pdo_pgsql`)
- Optional: MySQL driver (`pdo_mysql`) for MySQL support

### Setup

1. Clone the repository:

```bash
git clone https://github.com/artist-kota-nih/web_crawler.git
cd web_crawler
```

2. Configure database connections in `settings.yaml`:

```yaml
databases:
  primary:
    type: postgresql
    host: localhost
    port: 5432
    database: your_database
    username: your_username
    password: your_password
    timeout: 5
    retry_attempts: 3
```

3. Set environment variables for sensitive data:

```bash
export DB_USERNAME=your_username
export DB_PASSWORD=your_password
```

## Usage

### Running Health Checks

**One-time check:**

```bash
php lib/HealthCheckRunner.php settings.yaml --once
```

**Continuous monitoring:**

```bash
php lib/HealthCheckRunner.php settings.yaml
```

### Programmatic Usage

```php
<?php
require_once 'lib/HealthChecks/ActiveRecordCheck.php';

use WebCrawler\HealthChecks\ActiveRecordCheck;

$check = new ActiveRecordCheck();
$check->configure([
    'type' => 'postgresql',
    'host' => 'localhost',
    'port' => 5432,
    'database' => 'myapp',
    'username' => 'dbuser',
    'password' => 'secret',
    'timeout' => 5,
    'check_replication' => false,
]);

if ($check->check()) {
    echo "Database is healthy\n";
    $status = $check->getStatus();
    print_r($status);
} else {
    echo "Database check failed: " . $check->getLastError() . "\n";
}
```

### Running Tests

```bash
php assets/smoke_test.php
```

## Configuration

### Database Settings

| Parameter        | Type   | Default      | Description                              |
| ---------------- | ------ | ------------ | ---------------------------------------- |
| `type`           | string | `postgresql` | Database type: postgresql, mysql, sqlite |
| `host`           | string | -            | Database host                            |
| `port`           | int    | 5432         | Database port                            |
| `database`       | string | -            | Database name                            |
| `username`       | string | -            | Database username                        |
| `password`       | string | -            | Database password                        |
| `timeout`        | int    | 5            | Connection timeout (seconds)             |
| `retry_attempts` | int    | 3            | Number of retry attempts                 |
| `retry_delay`    | int    | 1            | Delay between retries (seconds)          |

### PostgreSQL-Specific Settings

| Parameter             | Type   | Default  | Description                                                       |
| --------------------- | ------ | -------- | ----------------------------------------------------------------- |
| `sslmode`             | string | `prefer` | SSL mode: disable, allow, prefer, require, verify-ca, verify-full |
| `statement_timeout`   | int    | 30000    | Statement timeout (milliseconds)                                  |
| `application_name`    | string | -        | Application name in pg_stat_activity                              |
| `check_replication`   | bool   | false    | Monitor replication lag                                           |
| `max_replication_lag` | int    | 60       | Max acceptable replication lag (seconds)                          |

### Alerting Configuration

| Parameter          | Type | Default | Description                          |
| ------------------ | ---- | ------- | ------------------------------------ |
| `enabled`          | bool | true    | Enable alerting                      |
| `alert_threshold`  | int  | 3       | Consecutive failures before alerting |
| `throttle_minutes` | int  | 15      | Minimum time between repeat alerts   |

## PostgreSQL Error Handling

ActiveRecordCheck provides enhanced error messages for common PostgreSQL issues:

| Error Code | Issue                | Hint                           |
| ---------- | -------------------- | ------------------------------ |
| 08006      | Connection failure   | Check if PostgreSQL is running |
| 28P01      | Invalid password     | Verify credentials             |
| 53300      | Too many connections | max_connections limit reached  |
| 57P03      | Server shutting down | Wait for server restart        |

## Architecture

```
lib/
├── HealthChecks/
│   ├── HealthCheckInterface.php    # Interface for all checks
│   ├── DatabaseCheck.php            # Abstract base class
│   ├── ActiveRecordCheck.php        # Main implementation
│   └── Adapters/
│       └── PostgresAdapter.php      # PostgreSQL-specific logic
├── HealthCheckRunner.php            # CLI runner
alerting/
└── decoder.php                      # Alert routing and throttling
settings.yaml                        # Configuration
```

## Troubleshooting

### Common Issues

**"Connection refused"**

- Verify PostgreSQL is running: `pg_isready -h localhost`
- Check firewall rules and pg_hba.conf

**"Too many connections"**

- Increase max_connections in postgresql.conf
- Monitor connection usage in health check output

**"SSL connection error"**

- Set `sslmode: disable` for local development
- For production, use `sslmode: require` with valid certificates

**"Replication lag detected"**

- Check network between primary and standby
- Increase `max_replication_lag` threshold if acceptable

## Contributing

Contributions are welcome! Please see GitHub issue [#2](https://github.com/artist-kota-nih/web_crawler/issues/2) for context on the ActiveRecordCheck PostgreSQL implementation.

## License

MIT License - See LICENSE file for details
