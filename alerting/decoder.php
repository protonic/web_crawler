<?php

namespace WebCrawler\Alerting;

/**
 * Alert decoder and dispatcher
 * 
 * Processes health check failures and routes alerts to appropriate
 * notification channels (logs, webhooks, email, etc.)
 */
class AlertDecoder
{
    private array $config;
    private array $failureHistory = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Process a health check result and determine if alert should be sent
     * 
     * @param string $checkName Name of the health check
     * @param array $status Status array from health check
     * @return bool True if alert was sent
     */
    public function processHealthCheckResult(string $checkName, array $status): bool
    {
        $isHealthy = $status['healthy'] ?? true;

        if ($isHealthy) {
            // Clear failure history on success
            $this->clearFailureHistory($checkName);
            return false;
        }

        // Record failure
        $this->recordFailure($checkName, $status);

        // Check if we've hit alert threshold
        $threshold = $this->config['alert_threshold'] ?? 3;
        $failureCount = count($this->failureHistory[$checkName] ?? []);

        if ($failureCount < $threshold) {
            return false; // Not enough consecutive failures yet
        }

        // Check throttling
        if ($this->isThrottled($checkName)) {
            return false; // Too soon since last alert
        }

        // Send alert
        return $this->sendAlert($checkName, $status);
    }

    /**
     * Record a health check failure
     * 
     * @param string $checkName
     * @param array $status
     */
    private function recordFailure(string $checkName, array $status): void
    {
        if (!isset($this->failureHistory[$checkName])) {
            $this->failureHistory[$checkName] = [];
        }

        $this->failureHistory[$checkName][] = [
            'timestamp' => time(),
            'status' => $status,
        ];

        // Keep only recent failures (last 10)
        if (count($this->failureHistory[$checkName]) > 10) {
            array_shift($this->failureHistory[$checkName]);
        }
    }

    /**
     * Clear failure history for a check
     * 
     * @param string $checkName
     */
    private function clearFailureHistory(string $checkName): void
    {
        unset($this->failureHistory[$checkName]);
    }

    /**
     * Check if alerts for this check are throttled
     * 
     * @param string $checkName
     * @return bool
     */
    private function isThrottled(string $checkName): bool
    {
        $throttleMinutes = $this->config['throttle_minutes'] ?? 15;
        $cacheFile = $this->getThrottleCacheFile($checkName);

        if (!file_exists($cacheFile)) {
            return false;
        }

        $lastAlertTime = (int)file_get_contents($cacheFile);
        $timeSinceLastAlert = time() - $lastAlertTime;

        return $timeSinceLastAlert < ($throttleMinutes * 60);
    }

    /**
     * Send alert through configured channels
     * 
     * @param string $checkName
     * @param array $status
     * @return bool
     */
    private function sendAlert(string $checkName, array $status): bool
    {
        $channels = $this->config['channels'] ?? [];
        $alertSent = false;

        $message = $this->formatAlertMessage($checkName, $status);

        foreach ($channels as $channel) {
            try {
                switch ($channel['type'] ?? 'log') {
                    case 'log':
                        $this->sendLogAlert($message, $channel);
                        $alertSent = true;
                        break;

                    case 'webhook':
                        $this->sendWebhookAlert($message, $status, $channel);
                        $alertSent = true;
                        break;

                    case 'email':
                        $this->sendEmailAlert($message, $status, $channel);
                        $alertSent = true;
                        break;
                }
            } catch (\Exception $e) {
                error_log("Failed to send alert via {$channel['type']}: " . $e->getMessage());
            }
        }

        if ($alertSent) {
            // Update throttle cache
            $this->updateThrottleCache($checkName);
        }

        return $alertSent;
    }

    /**
     * Format alert message
     * 
     * @param string $checkName
     * @param array $status
     * @return string
     */
    private function formatAlertMessage(string $checkName, array $status): string
    {
        $failureCount = count($this->failureHistory[$checkName] ?? []);

        $message = sprintf(
            "[ALERT] %s failed (consecutive failures: %d)\n\n",
            $checkName,
            $failureCount
        );

        $message .= sprintf("Error: %s\n", $status['error'] ?? 'Unknown error');
        $message .= sprintf("Duration: %.2fms\n", $status['last_check_duration_ms'] ?? 0);
        $message .= sprintf("Timestamp: %s\n", $status['timestamp'] ?? date('c'));

        if (isset($status['config'])) {
            $message .= sprintf(
                "\nDatabase: %s@%s\n",
                $status['config']['database'] ?? 'unknown',
                $status['config']['host'] ?? 'unknown'
            );
        }

        if (isset($status['database_health'])) {
            $message .= "\nDatabase Health Details:\n";
            $message .= json_encode($status['database_health'], JSON_PRETTY_PRINT);
        }

        return $message;
    }

    /**
     * Send log alert
     * 
     * @param string $message
     * @param array $channel
     */
    private function sendLogAlert(string $message, array $channel): void
    {
        $level = $channel['level'] ?? 'error';

        switch ($level) {
            case 'error':
                error_log($message);
                break;
            case 'warning':
                error_log("[WARNING] " . $message);
                break;
            default:
                error_log("[INFO] " . $message);
        }
    }

    /**
     * Send webhook alert
     * 
     * @param string $message
     * @param array $status
     * @param array $channel
     */
    private function sendWebhookAlert(string $message, array $status, array $channel): void
    {
        $url = $channel['url'] ?? null;

        if (!$url) {
            throw new \InvalidArgumentException('Webhook URL not configured');
        }

        $payload = [
            'alert_type' => 'health_check_failure',
            'message' => $message,
            'status' => $status,
            'timestamp' => time(),
        ];

        $timeout = $channel['timeout'] ?? 10;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: WebCrawler-HealthCheck/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("Webhook returned HTTP {$httpCode}");
        }
    }

    /**
     * Send email alert
     * 
     * @param string $message
     * @param array $status
     * @param array $channel
     */
    private function sendEmailAlert(string $message, array $status, array $channel): void
    {
        $to = $channel['to'] ?? null;
        $from = $channel['from'] ?? 'noreply@webcrawler.local';
        $subject = $channel['subject'] ?? 'Health Check Alert';

        if (!$to) {
            throw new \InvalidArgumentException('Email recipient not configured');
        }

        $headers = [
            'From: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        mail($to, $subject, $message, implode("\r\n", $headers));
    }

    /**
     * Get throttle cache file path
     * 
     * @param string $checkName
     * @return string
     */
    private function getThrottleCacheFile(string $checkName): string
    {
        $cacheDir = sys_get_temp_dir() . '/web_crawler_alerts';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        return $cacheDir . '/' . md5($checkName) . '.throttle';
    }

    /**
     * Update throttle cache
     * 
     * @param string $checkName
     */
    private function updateThrottleCache(string $checkName): void
    {
        $cacheFile = $this->getThrottleCacheFile($checkName);
        file_put_contents($cacheFile, time());
    }
}
