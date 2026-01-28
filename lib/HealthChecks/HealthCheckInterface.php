<?php

namespace WebCrawler\HealthChecks;

/**
 * Interface for health check implementations
 * 
 * Defines the contract for all health check classes in the system.
 * Each health check must implement these methods to provide consistent
 * monitoring and alerting capabilities.
 */
interface HealthCheckInterface
{
    /**
     * Perform the health check
     * 
     * @return bool True if the check passes, false otherwise
     */
    public function check(): bool;

    /**
     * Get the name of this health check
     * 
     * @return string A unique identifier for this check
     */
    public function getName(): string;

    /**
     * Get the last error message if the check failed
     * 
     * @return string|null Error message or null if no error
     */
    public function getLastError(): ?string;

    /**
     * Get detailed status information
     * 
     * @return array Status details including timing, metadata, etc.
     */
    public function getStatus(): array;

    /**
     * Set configuration options for this check
     * 
     * @param array $config Configuration parameters
     * @return void
     */
    public function configure(array $config): void;
}
