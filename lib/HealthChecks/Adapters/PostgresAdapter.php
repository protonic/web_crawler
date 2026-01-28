<?php

namespace WebCrawler\HealthChecks\Adapters;

/**
 * PostgreSQL-specific database adapter
 * 
 * Handles PostgreSQL-specific connection parameters, error conditions,
 * and health check requirements that are unique to PostgreSQL databases.
 */
class PostgresAdapter
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build PostgreSQL DSN string
     * 
     * @return string
     */
    public function buildDsn(): string
    {
        $parts = ['pgsql:'];

        if (!empty($this->config['host'])) {
            $parts[] = 'host=' . $this->config['host'];
        }

        if (!empty($this->config['port'])) {
            $parts[] = 'port=' . $this->config['port'];
        } else {
            $parts[] = 'port=5432'; // Default PostgreSQL port
        }

        if (!empty($this->config['database'])) {
            $parts[] = 'dbname=' . $this->config['database'];
        }

        // SSL mode configuration
        if (!empty($this->config['sslmode'])) {
            $parts[] = 'sslmode=' . $this->config['sslmode'];
        }

        return implode(';', $parts);
    }

    /**
     * Get PDO connection options specific to PostgreSQL
     * 
     * @return array
     */
    public function getPdoOptions(): array
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $this->config['timeout'] ?? 5,
            \PDO::ATTR_PERSISTENT => $this->config['persistent'] ?? false,
        ];

        // Set statement timeout to prevent long-running queries
        if (isset($this->config['statement_timeout'])) {
            $options[\PDO::ATTR_EMULATE_PREPARES] = false;
        }

        return $options;
    }

    /**
     * Check PostgreSQL-specific health indicators
     * 
     * @param \PDO $connection
     * @return array Health check results
     */
    public function checkHealth(\PDO $connection): array
    {
        $results = [
            'healthy' => true,
            'checks' => [],
            'warnings' => [],
        ];

        try {
            // Check 1: Connection count vs max_connections
            $connectionCheck = $this->checkConnectionLimit($connection);
            $results['checks']['connection_limit'] = $connectionCheck;
            if (!$connectionCheck['healthy']) {
                $results['healthy'] = false;
            }

            // Check 2: Idle in transaction timeout
            $idleCheck = $this->checkIdleConnections($connection);
            $results['checks']['idle_connections'] = $idleCheck;
            if ($idleCheck['warning']) {
                $results['warnings'][] = $idleCheck['message'];
            }

            // Check 3: Database locks
            $lockCheck = $this->checkDatabaseLocks($connection);
            $results['checks']['locks'] = $lockCheck;
            if (!$lockCheck['healthy']) {
                $results['healthy'] = false;
            }

            // Check 4: Replication lag (if applicable)
            if ($this->config['check_replication'] ?? false) {
                $replicationCheck = $this->checkReplicationLag($connection);
                $results['checks']['replication'] = $replicationCheck;
                if (!$replicationCheck['healthy']) {
                    $results['warnings'][] = $replicationCheck['message'];
                }
            }
        } catch (\PDOException $e) {
            $results['healthy'] = false;
            $results['error'] = 'PostgreSQL health check failed: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Check connection limit health
     * 
     * @param \PDO $connection
     * @return array
     */
    private function checkConnectionLimit(\PDO $connection): array
    {
        try {
            $stmt = $connection->query("
                SELECT 
                    (SELECT setting::int FROM pg_settings WHERE name = 'max_connections') AS max_conn,
                    (SELECT count(*) FROM pg_stat_activity) AS current_conn
            ");

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $maxConn = (int)$row['max_conn'];
            $currentConn = (int)$row['current_conn'];
            $usage = ($currentConn / $maxConn) * 100;

            return [
                'healthy' => $usage < 90, // Warn if usage > 90%
                'max_connections' => $maxConn,
                'current_connections' => $currentConn,
                'usage_percent' => round($usage, 2),
            ];
        } catch (\PDOException $e) {
            return [
                'healthy' => false,
                'error' => 'Could not check connection limit: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check for idle connections
     * 
     * @param \PDO $connection
     * @return array
     */
    private function checkIdleConnections(\PDO $connection): array
    {
        try {
            $stmt = $connection->query("
                SELECT count(*) as idle_count
                FROM pg_stat_activity
                WHERE state = 'idle in transaction'
                AND query_start < NOW() - INTERVAL '5 minutes'
            ");

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $idleCount = (int)$row['idle_count'];

            return [
                'healthy' => true,
                'warning' => $idleCount > 0,
                'idle_in_transaction' => $idleCount,
                'message' => $idleCount > 0
                    ? "Found {$idleCount} long-running idle transactions"
                    : 'No problematic idle transactions',
            ];
        } catch (\PDOException $e) {
            return [
                'healthy' => true,
                'warning' => false,
                'error' => 'Could not check idle connections: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check for database locks
     * 
     * @param \PDO $connection
     * @return array
     */
    private function checkDatabaseLocks(\PDO $connection): array
    {
        try {
            $stmt = $connection->query("
                SELECT count(*) as lock_count
                FROM pg_locks
                WHERE NOT granted
            ");

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $lockCount = (int)$row['lock_count'];

            return [
                'healthy' => $lockCount < 10, // Arbitrary threshold
                'blocked_locks' => $lockCount,
                'message' => $lockCount > 0
                    ? "Found {$lockCount} blocked locks"
                    : 'No blocked locks',
            ];
        } catch (\PDOException $e) {
            return [
                'healthy' => false,
                'error' => 'Could not check locks: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check replication lag
     * 
     * @param \PDO $connection
     * @return array
     */
    private function checkReplicationLag(\PDO $connection): array
    {
        try {
            // Check if this is a standby server
            $stmt = $connection->query("SELECT pg_is_in_recovery()");
            $isStandby = $stmt->fetchColumn();

            if (!$isStandby) {
                return [
                    'healthy' => true,
                    'message' => 'Primary server, replication lag not applicable',
                ];
            }

            // Get replication lag
            $stmt = $connection->query("
                SELECT 
                    EXTRACT(EPOCH FROM (NOW() - pg_last_xact_replay_timestamp())) AS lag_seconds
            ");

            $lagSeconds = $stmt->fetchColumn();
            $maxLag = $this->config['max_replication_lag'] ?? 60; // Default 60 seconds

            return [
                'healthy' => $lagSeconds < $maxLag,
                'lag_seconds' => round($lagSeconds, 2),
                'message' => $lagSeconds >= $maxLag
                    ? "Replication lag is {$lagSeconds}s (threshold: {$maxLag}s)"
                    : 'Replication lag acceptable',
            ];
        } catch (\PDOException $e) {
            return [
                'healthy' => true,
                'warning' => true,
                'error' => 'Could not check replication lag: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute post-connection setup commands
     * 
     * @param \PDO $connection
     */
    public function postConnectionSetup(\PDO $connection): void
    {
        // Set statement timeout if configured
        if (isset($this->config['statement_timeout'])) {
            $timeout = (int)$this->config['statement_timeout'];
            $connection->exec("SET statement_timeout = {$timeout}");
        }

        // Set application name for easier tracking in pg_stat_activity
        if (isset($this->config['application_name'])) {
            $appName = $connection->quote($this->config['application_name']);
            $connection->exec("SET application_name = {$appName}");
        }
    }
}
