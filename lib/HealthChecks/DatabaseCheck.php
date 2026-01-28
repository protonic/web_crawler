<?php

namespace WebCrawler\HealthChecks;

/**
 * Abstract base class for database health checks
 * 
 * Provides common functionality for database connection validation,
 * query execution, and error handling across different database types.
 */
abstract class DatabaseCheck implements HealthCheckInterface
{
    protected ?string $lastError = null;
    protected array $config = [];
    protected float $lastCheckDuration = 0;
    protected ?\PDO $connection = null;

    /**
     * Configure the database check
     * 
     * @param array $config Configuration including host, port, database, credentials
     */
    public function configure(array $config): void
    {
        $this->config = array_merge([
            'timeout' => 5,
            'retry_attempts' => 3,
            'retry_delay' => 1,
            'validation_query' => 'SELECT 1',
        ], $config);
    }

    /**
     * Perform the health check
     * 
     * @return bool True if connection is healthy
     */
    public function check(): bool
    {
        $startTime = microtime(true);
        $this->lastError = null;

        try {
            // Attempt connection with retries
            for ($attempt = 1; $attempt <= $this->config['retry_attempts']; $attempt++) {
                try {
                    $this->connection = $this->createConnection();
                    break;
                } catch (\PDOException $e) {
                    if ($attempt === $this->config['retry_attempts']) {
                        throw $e;
                    }
                    sleep($this->config['retry_delay']);
                }
            }

            // Validate connection with test query
            if (!$this->validateConnection()) {
                $this->lastError = 'Connection validation failed';
                return false;
            }

            // Check specific database health metrics
            if (!$this->checkDatabaseSpecificHealth()) {
                return false;
            }

            $this->lastCheckDuration = microtime(true) - $startTime;
            return true;
        } catch (\PDOException $e) {
            $this->lastError = sprintf(
                'Database connection failed: %s (Code: %s)',
                $e->getMessage(),
                $e->getCode()
            );
            $this->lastCheckDuration = microtime(true) - $startTime;
            return false;
        } catch (\Exception $e) {
            $this->lastError = sprintf(
                'Health check error: %s',
                $e->getMessage()
            );
            $this->lastCheckDuration = microtime(true) - $startTime;
            return false;
        } finally {
            $this->closeConnection();
        }
    }

    /**
     * Get the last error message
     * 
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get detailed status information
     * 
     * @return array
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->getName(),
            'healthy' => $this->lastError === null,
            'last_check_duration_ms' => round($this->lastCheckDuration * 1000, 2),
            'error' => $this->lastError,
            'timestamp' => date('c'),
            'config' => [
                'host' => $this->config['host'] ?? 'unknown',
                'database' => $this->config['database'] ?? 'unknown',
                'timeout' => $this->config['timeout'] ?? null,
            ],
        ];
    }

    /**
     * Validate the database connection with a test query
     * 
     * @return bool
     */
    protected function validateConnection(): bool
    {
        if (!$this->connection) {
            return false;
        }

        try {
            $stmt = $this->connection->query($this->config['validation_query']);
            return $stmt !== false;
        } catch (\PDOException $e) {
            $this->lastError = 'Validation query failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Close the database connection
     */
    protected function closeConnection(): void
    {
        $this->connection = null;
    }

    /**
     * Create a PDO connection to the database
     * 
     * @return \PDO
     * @throws \PDOException
     */
    abstract protected function createConnection(): \PDO;

    /**
     * Check database-specific health metrics
     * 
     * @return bool
     */
    abstract protected function checkDatabaseSpecificHealth(): bool;
}
