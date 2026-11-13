<?php

namespace App\Http\Resources;

use App\Models\RepositoryConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected account as the client shows it. Tokens are never serialized.
 *
 * @mixin RepositoryConnection
 */
class RepositoryConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'proveedor' => $this->provider->value,
            'cuenta' => $this->account_login,
            'conectadoEn' => $this->updated_at?->toIso8601String(),
        ];
    }
}
