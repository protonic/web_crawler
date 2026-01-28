# Example: Basic PostgreSQL Health Check

This example demonstrates how to use ActiveRecordCheck to monitor a PostgreSQL database.

## Setup

1. Create a test database:

```bash
createdb test_healthcheck
```

2. Configure the check:

```php
<?php
require_once __DIR__ . '/../lib/HealthChecks/ActiveRecordCheck.php';

use WebCrawler\HealthChecks\ActiveRecordCheck;

$check = new ActiveRecordCheck();
$check->configure([
    'type' => 'postgresql',
    'host' => 'localhost',
    'port' => 5432,
    'database' => 'test_healthcheck',
    'username' => 'postgres',
    'password' => 'your_password',
    'timeout' => 5,
    'retry_attempts' => 3,
    'retry_delay' => 1,
    'sslmode' => 'prefer',
    'check_replication' => false,
]);

// Run the check
$healthy = $check->check();

if ($healthy) {
    echo "✓ Database is healthy\n\n";

    $status = $check->getStatus();
    echo "Connection details:\n";
    echo "  - Duration: {$status['last_check_duration_ms']}ms\n";
    echo "  - Database: {$status['config']['database']}@{$status['config']['host']}\n";

    if (isset($status['database_health'])) {
        echo "\nPostgreSQL Health Metrics:\n";

        $health = $status['database_health'];

        if (isset($health['checks']['connection_limit'])) {
            $conn = $health['checks']['connection_limit'];
            echo "  - Connections: {$conn['current_connections']}/{$conn['max_connections']} ";
            echo "({$conn['usage_percent']}%)\n";
        }

        if (isset($health['checks']['idle_connections'])) {
            $idle = $health['checks']['idle_connections'];
            echo "  - Idle transactions: {$idle['idle_in_transaction']}\n";
        }

        if (isset($health['checks']['locks'])) {
            $locks = $health['checks']['locks'];
            echo "  - Blocked locks: {$locks['blocked_locks']}\n";
        }
    }
} else {
    echo "✗ Database check failed\n\n";
    echo "Error: " . $check->getLastError() . "\n";

    $status = $check->getStatus();
    echo "\nFull status:\n";
    print_r($status);
}
```

## Output Examples

### Healthy Database

```
✓ Database is healthy

Connection details:
  - Duration: 15.42ms
  - Database: test_healthcheck@localhost

PostgreSQL Health Metrics:
  - Connections: 5/100 (5.0%)
  - Idle transactions: 0
  - Blocked locks: 0
```

### Failed Connection

```
✗ Database check failed

Error: Database connection failed: SQLSTATE[08006] [7] connection to server at "localhost" (127.0.0.1), port 5432 failed: Connection refused [Hint: Check if PostgreSQL is running and accepting connections]
```

### Connection Limit Warning

```
✓ Database is healthy

Connection details:
  - Duration: 23.67ms
  - Database: production_db@db.example.com

PostgreSQL Health Metrics:
  - Connections: 92/100 (92.0%)  ⚠️
  - Idle transactions: 2
  - Blocked locks: 0

Warning: Found 2 long-running idle transactions
```

## Advanced: Monitoring Replication

For PostgreSQL standby servers:

```php
$check->configure([
    'type' => 'postgresql',
    'host' => 'replica.example.com',
    'port' => 5432,
    'database' => 'production_db',
    'username' => 'readonly',
    'password' => 'secret',
    'check_replication' => true,
    'max_replication_lag' => 30, // Alert if lag > 30s
]);

$healthy = $check->check();
$status = $check->getStatus();

if (isset($status['database_health']['checks']['replication'])) {
    $repl = $status['database_health']['checks']['replication'];
    echo "Replication lag: {$repl['lag_seconds']}s\n";
}
```
