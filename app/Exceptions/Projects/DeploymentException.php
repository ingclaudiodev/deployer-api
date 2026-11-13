<?php

namespace App\Exceptions\Projects;

use RuntimeException;
use Throwable;

/**
 * A deployment could not be started or followed. Wraps the provider error so
 * callers never depend on how the runner reports it.
 */
class DeploymentException extends RuntimeException
{
    public static function notConfigured(string $key): self
    {
        return new self("Deployments are not configured: set {$key}.");
    }

    public static function requestFailed(Throwable $previous): self
    {
        return new self("The deployment runner request failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function unexpectedAnswer(int $status, string $body = ''): self
    {
        return new self(trim("The deployment runner answered with HTTP {$status}. ".mb_substr($body, 0, 500)));
    }

    public static function notApprovable(string $reason): self
    {
        return new self("The project cannot be approved: {$reason}");
    }
}
