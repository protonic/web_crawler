<?php

namespace WebCrawler\HealthChecks;

use WebCrawler\HealthChecks\Adapters\PostgresAdapter;

/**
 * ActiveRecord-style database health check
 * 
 * Validates database connectivity and health for ActiveRecord-style
 * database connections, with special support for PostgreSQL edge cases
 * that can cause failures in certain conditions.
 * 
 * Addresses common PostgreSQL issues:
 * - Connection pooling limits (max_connections)
 * - Idle transaction timeouts
 * - SSL certificate validation failures
 * - Prepared statement cache issues
 * - Replication lag on standby servers
 */
class ActiveRecordCheck extends DatabaseCheck
{
    private ?PostgresAdapter $adapter = null;
    private array $healthDetails = [];

    /**
     * Get the name of this health check
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'ActiveRecordCheck';
    }

    /**
     * Create a PDO connection based on database type
     * 
     * @return \PDO
     * @throws \PDOException
     */
    protected function createConnection(): \PDO
    {
        $dbType = $this->config['type'] ?? 'postgresql';

        switch ($dbType) {
            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return $this->createPostgresConnection();

            case 'mysql':
                return $this->createMysqlConnection();

            case 'sqlite':
                return $this->createSqliteConnection();

            default:
                throw new \InvalidArgumentException("Unsupported database type: {$dbType}");
        }
    }

    /**
     * Create PostgreSQL connection with proper error handling
     * 
     * @return \PDO
     * @throws \PDOException
     */
    private function createPostgresConnection(): \PDO
    {
        $this->adapter = new PostgresAdapter($this->config);

        try {
            $dsn = $this->adapter->buildDsn();
            $username = $this->config['username'] ?? null;
            $password = $this->config['password'] ?? null;
            $options = $this->adapter->getPdoOptions();

            $connection = new \PDO($dsn, $username, $password, $options);

            // Execute post-connection setup
            $this->adapter->postConnectionSetup($connection);

            return $connection;
        } catch (\PDOException $e) {
            // Enhance error message for common PostgreSQL issues
            $message = $this->enhancePostgresErrorMessage($e);
            throw new \PDOException($message, (int)$e->getCode(), $e);
        }
    }

    /**
     * Create MySQL connection
     * 
     * @return \PDO
     * @throws \PDOException
     */
    private function createMysqlConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 3306,
            $this->config['database'] ?? ''
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $this->config['timeout'] ?? 5,
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ];

        return new \PDO(
            $dsn,
            $this->config['username'] ?? null,
            $this->config['password'] ?? null,
            $options
        );
    }

    /**
     * Create SQLite connection
     * 
     * @return \PDO
     * @throws \PDOException
     */
    private function createSqliteConnection(): \PDO
    {
        $path = $this->config['database'] ?? ':memory:';
        $dsn = "sqlite:{$path}";

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $this->config['timeout'] ?? 5,
        ];

        return new \PDO($dsn, null, null, $options);
    }

    /**
     * Check database-specific health metrics
     * 
     * @return bool
     */
    protected function checkDatabaseSpecificHealth(): bool
    {
        $dbType = $this->config['type'] ?? 'postgresql';

        // PostgreSQL-specific checks
        if (in_array($dbType, ['postgresql', 'postgres', 'pgsql']) && $this->adapter) {
            $healthResults = $this->adapter->checkHealth($this->connection);
            $this->healthDetails = $healthResults;

            if (!$healthResults['healthy']) {
                $this->lastError = 'PostgreSQL health check failed: ' .
                    ($healthResults['error'] ?? 'See detailed checks for issues');
                return false;
            }

            // Log warnings even if overall check passes
            if (!empty($healthResults['warnings'])) {
                error_log(sprintf(
                    '[%s] PostgreSQL warnings: %s',
                    $this->getName(),
                    implode(', ', $healthResults['warnings'])
                ));
            }
        }

        return true;
    }

    /**
     * Get detailed status including database-specific health metrics
     * 
     * @return array
     */
    public function getStatus(): array
    {
        $status = parent::getStatus();

        if (!empty($this->healthDetails)) {
            $status['database_health'] = $this->healthDetails;
        }

        return $status;
    }

    /**
     * Enhance PostgreSQL error messages with helpful context
     * 
     * @param \PDOException $e
     * @return string
     */
    private function enhancePostgresErrorMessage(\PDOException $e): string
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Common PostgreSQL error codes and helpful messages
        $knownErrors = [
            '08006' => 'Connection failure - check if PostgreSQL is running and accepting connections',
            '08001' => 'Unable to connect - verify host, port, and network connectivity',
            '08004' => 'Connection rejected - check pg_hba.conf authentication settings',
            '28000' => 'Invalid authorization - verify username and password',
            '28P01' => 'Invalid password - check credentials',
            '3D000' => 'Database does not exist - verify database name',
            '53300' => 'Too many connections - max_connections limit reached',
            '57P03' => 'Server is shutting down',
            '57P01' => 'Admin shutdown in progress',
            '08P01' => 'Protocol violation - possible version mismatch',
        ];

        if (isset($knownErrors[$code])) {
            return sprintf('%s [Hint: %s]', $message, $knownErrors[$code]);
        }

        // Check for SSL-related errors
        if (stripos($message, 'ssl') !== false) {
            return sprintf(
                '%s [Hint: SSL connection issue - check sslmode setting and certificate validity]',
                $message
            );
        }

        // Check for timeout errors
        if (stripos($message, 'timeout') !== false) {
            return sprintf(
                '%s [Hint: Connection timeout - check network latency and server load]',
                $message
            );
        }

        return $message;
    }
}
