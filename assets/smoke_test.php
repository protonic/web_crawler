<?php

/**
 * Smoke tests for ActiveRecordCheck
 * 
 * Basic tests to verify the health check system works correctly
 */

require_once __DIR__ . '/../lib/HealthChecks/HealthCheckInterface.php';
require_once __DIR__ . '/../lib/HealthChecks/DatabaseCheck.php';
require_once __DIR__ . '/../lib/HealthChecks/Adapters/PostgresAdapter.php';
require_once __DIR__ . '/../lib/HealthChecks/ActiveRecordCheck.php';

use WebCrawler\HealthChecks\ActiveRecordCheck;

echo "Running ActiveRecordCheck smoke tests...\n\n";

// Test 1: PostgreSQL connection with invalid credentials
echo "Test 1: PostgreSQL with invalid credentials (should fail gracefully)\n";
$check1 = new ActiveRecordCheck();
$check1->configure([
    'type' => 'postgresql',
    'host' => 'localhost',
    'port' => 5432,
    'database' => 'nonexistent_db',
    'username' => 'invalid_user',
    'password' => 'invalid_password',
    'timeout' => 2,
    'retry_attempts' => 1,
]);

$result1 = $check1->check();
echo "  Result: " . ($result1 ? "PASS" : "FAIL (expected)") . "\n";
echo "  Error: " . ($check1->getLastError() ?? 'None') . "\n";
echo "  Status: " . json_encode($check1->getStatus(), JSON_PRETTY_PRINT) . "\n\n";

// Test 2: SQLite connection (should work without server)
echo "Test 2: SQLite in-memory database (should succeed)\n";
$check2 = new ActiveRecordCheck();
$check2->configure([
    'type' => 'sqlite',
    'database' => ':memory:',
    'timeout' => 1,
]);

$result2 = $check2->check();
echo "  Result: " . ($result2 ? "PASS" : "FAIL") . "\n";
echo "  Error: " . ($check2->getLastError() ?? 'None') . "\n";
echo "  Duration: " . $check2->getStatus()['last_check_duration_ms'] . "ms\n\n";

// Test 3: Invalid database type
echo "Test 3: Invalid database type (should throw exception)\n";
$check3 = new ActiveRecordCheck();
$check3->configure([
    'type' => 'oracle', // Unsupported
    'host' => 'localhost',
]);

try {
    $result3 = $check3->check();
    echo "  Result: FAIL (should have thrown exception)\n\n";
} catch (\InvalidArgumentException $e) {
    echo "  Result: PASS (exception caught)\n";
    echo "  Exception: " . $e->getMessage() . "\n\n";
}

// Test 4: PostgreSQL adapter DSN building
echo "Test 4: PostgreSQL adapter DSN building\n";
$adapter = new \WebCrawler\HealthChecks\Adapters\PostgresAdapter([
    'host' => 'db.example.com',
    'port' => 5433,
    'database' => 'production_db',
    'sslmode' => 'require',
]);

$dsn = $adapter->buildDsn();
echo "  DSN: {$dsn}\n";
echo "  Expected components: host, port=5433, dbname, sslmode\n";
echo "  Result: " . (
    strpos($dsn, 'host=db.example.com') !== false &&
    strpos($dsn, 'port=5433') !== false &&
    strpos($dsn, 'dbname=production_db') !== false &&
    strpos($dsn, 'sslmode=require') !== false
    ? "PASS" : "FAIL"
) . "\n\n";

// Test 5: Configuration merging
echo "Test 5: Configuration with defaults\n";
$check5 = new ActiveRecordCheck();
$check5->configure([
    'type' => 'sqlite',
    'database' => ':memory:',
    // Should use default timeout, retry_attempts, etc.
]);

$check5->check();
$status5 = $check5->getStatus();
echo "  Config keys present: " . implode(', ', array_keys($status5['config'] ?? [])) . "\n";
echo "  Result: " . (isset($status5['config']['timeout']) ? "PASS" : "FAIL") . "\n\n";

echo "Smoke tests completed!\n";
echo "\n";
echo "To test with real PostgreSQL:\n";
echo "  1. Set up PostgreSQL with credentials\n";
echo "  2. Update settings.yaml with connection details\n";
echo "  3. Run: php lib/HealthCheckRunner.php settings.yaml --once\n";
