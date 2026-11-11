<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * The model answered, but not with the analysis structure the application
 * expects: nothing derived from it is safe to use.
 */
class InvalidAnalysisException extends RuntimeException
{
    public static function notJson(string $reason): self
    {
        return new self("The model did not return valid JSON: {$reason}");
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function unexpectedShape(array $errors): self
    {
        return new self('The analysis does not have the expected structure: '.implode(' ', $errors));
    }
}
