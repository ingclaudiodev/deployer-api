<?php

namespace App\Services\Ai;

/**
 * Raw answer of a GenerativeModel: the JSON text as the model wrote it plus what
 * the call consumed, which is what gets billed (thinking tokens at the output rate).
 */
final readonly class GeneratedJson
{
    public function __construct(
        public string $json,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $thinkingTokens = 0,
    ) {}
}
