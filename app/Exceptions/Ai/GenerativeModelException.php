<?php

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * The model could not be reached or did not produce a usable answer. Wraps the
 * provider error so callers never depend on the vendor SDK exception types.
 */
class GenerativeModelException extends RuntimeException
{
    public static function notConfigured(string $key): self
    {
        return new self("Vertex AI is not configured: set {$key}.");
    }

    public static function requestFailed(Throwable $previous): self
    {
        return new self("The Vertex AI request failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function incompleteAnswer(string $finishReason): self
    {
        return new self("The model stopped before completing its answer ({$finishReason}).");
    }
}
