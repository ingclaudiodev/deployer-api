<?php

namespace App\Services\Ai;

/**
 * Plain-text answer of a GenerativeModel plus what the call consumed.
 */
final readonly class GeneratedText
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $thinkingTokens = 0,
    ) {}
}
