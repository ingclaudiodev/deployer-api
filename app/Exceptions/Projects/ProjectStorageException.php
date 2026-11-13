<?php

namespace App\Exceptions\Projects;

use RuntimeException;
use Throwable;

/**
 * A file of a project could not be written to or read from its store. Wraps
 * the provider error so callers never depend on the vendor SDK exception types.
 */
class ProjectStorageException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Project storage is not configured: set PROJECT_STORAGE_BUCKET.');
    }

    public static function unsafePath(string $path): self
    {
        return new self("The path [{$path}] is not a relative path inside the project.");
    }

    public static function requestFailed(Throwable $previous): self
    {
        return new self("The project storage request failed: {$previous->getMessage()}", previous: $previous);
    }
}
