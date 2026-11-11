<?php

namespace App\Services\Ai\Contracts;

use App\Exceptions\Ai\GenerativeModelException;
use App\Services\Ai\GeneratedJson;
use App\Services\Ai\GeneratedText;

/**
 * A text model able to hold a conversation and to answer with JSON constrained to a schema. Agents depend on
 * this contract, not on a vendor SDK, so the provider can change (or be faked in
 * tests) without touching them.
 */
interface GenerativeModel
{
    /**
     * @param  array<string, mixed>  $schema  OpenAPI-style schema the answer must follow (types in upper case: OBJECT, STRING...).
     *
     * @throws GenerativeModelException
     */
    public function generateJson(string $systemInstruction, string $prompt, array $schema): GeneratedJson;

    /**
     * Answers the last message of a conversation. The model keeps no memory between
     * calls: the whole exchange so far travels in $messages, oldest first.
     *
     * @param  array<int, array{role: 'user'|'model', text: string}>  $messages
     *
     * @throws GenerativeModelException
     */
    public function converse(string $systemInstruction, array $messages): GeneratedText;
}
