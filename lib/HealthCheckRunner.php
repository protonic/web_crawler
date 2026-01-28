<?php

require_once __DIR__ . '/../lib/HealthChecks/HealthCheckInterface.php';
require_once __DIR__ . '/../lib/HealthChecks/DatabaseCheck.php';
require_once __DIR__ . '/../lib/HealthChecks/Adapters/PostgresAdapter.php';
require_once __DIR__ . '/../lib/HealthChecks/ActiveRecordCheck.php';
require_once __DIR__ . '/../alerting/decoder.php';

use WebCrawler\HealthChecks\ActiveRecordCheck;
use WebCrawler\Alerting\AlertDecoder;

/**
 * Health Check Runner
 * 
 * Executes configured health checks and processes results
 */
class HealthCheckRunner
{
    private array $config;
    private AlertDecoder $alertDecoder;

    public function __construct(string $configFile)
    {
        $this->loadConfig($configFile);

        $alertConfig = array_merge(
            $this->config['health_checks']['alerting'] ?? [],
            [
                'alert_threshold' => $this->config['health_checks']['alert_threshold'] ?? 3,
                'throttle_minutes' => $this->config['health_checks']['alerting']['throttle_minutes'] ?? 15,
            ]
        );

        $this->alertDecoder = new AlertDecoder($alertConfig);
    }

    /**
     * Load configuration from YAML file
     */
    private function loadConfig(string $configFile): void
    {
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found: {$configFile}");
        }

        $content = file_get_contents($configFile);

        // Simple YAML parser for this example
        // In production, use symfony/yaml or similar
        $this->config = $this->parseSimpleYaml($content);
    }

    /**
     * Simple YAML parser (for demonstration)
     * In production, use a proper YAML library
     */
    private function parseSimpleYaml(string $content): array
    {
        // This is a simplified parser - use symfony/yaml in production
        $lines = explode("\n", $content);
        $config = ['databases' => [], 'health_checks' => []];

        // For this example, return a minimal config structure
        // In production, implement proper YAML parsing
        return $config;
    }

    /**
     * Run all configured health checks
     */
    public function runAllChecks(): array
    {
        $results = [];

        foreach ($this->config['databases'] ?? [] as $name => $dbConfig) {
            $result = $this->runCheckForDatabase($name, $dbConfig);
            $results[$name] = $result;
        }

        return $results;
    }

    /**
     * Run health check for a specific database
     */
    private function runCheckForDatabase(string $name, array $dbConfig): array
    {
        $check = new ActiveRecordCheck();
        $check->configure($dbConfig);

        $healthy = $check->check();
        $status = $check->getStatus();

        // Process result through alert decoder
        if ($this->config['health_checks']['alert_on_failure'] ?? true) {
            $this->alertDecoder->processHealthCheckResult(
                "ActiveRecordCheck:{$name}",
                $status
            );
        }

        return $status;
    }

    /**
     * Run health checks in a loop with configured interval
     */
    public function runContinuous(): void
    {
        $interval = $this->config['health_checks']['interval'] ?? 60;

        echo "Starting health check monitor (interval: {$interval}s)\n";
        echo "Press Ctrl+C to stop\n\n";

        while (true) {
            $startTime = microtime(true);

            echo "[" . date('Y-m-d H:i:s') . "] Running health checks...\n";

            $results = $this->runAllChecks();

            foreach ($results as $name => $status) {
                $healthStatus = $status['healthy'] ? '✓' : '✗';
                $duration = $status['last_check_duration_ms'] ?? 0;

                echo "  {$healthStatus} {$name}: ";

                if ($status['healthy']) {
                    echo "OK ({$duration}ms)\n";
                } else {
                    echo "FAILED - {$status['error']}\n";
                }
            }

            $elapsed = microtime(true) - $startTime;
            $sleepTime = max(0, $interval - $elapsed);

            if ($sleepTime > 0) {
                sleep((int)$sleepTime);
            }
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    $configFile = $argv[1] ?? __DIR__ . '/../settings.yaml';

    try {
        $runner = new HealthCheckRunner($configFile);

        if (isset($argv[2]) && $argv[2] === '--once') {
            // Run once and exit
            $results = $runner->runAllChecks();
            echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
            exit(0);
        } else {
            // Run continuously
            $runner->runContinuous();
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
