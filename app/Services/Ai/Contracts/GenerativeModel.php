<?php

namespace App\Services\Ai\Contracts;

use App\Exceptions\Ai\GenerativeModelException;
use App\Services\Ai\GeneratedJson;

/**
 * A text model able to answer with JSON constrained to a schema. Agents depend on
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
}
