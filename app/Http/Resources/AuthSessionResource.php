<?php

namespace App\Http\Resources;

use App\Auth\IssuedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client receives after any successful authentication: the token and who it is,
 * and `deviceToken` only when it asked to remember the device for the second factor.
 *
 * @mixin IssuedToken
 */
class AuthSessionResource extends JsonResource
{
    /**
     * @return array{accessToken: string, tokenType: string, expiresAt: string|null, user: UserResource, deviceToken?: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'accessToken' => $this->accessToken,
            'tokenType' => $this->tokenType,
            'expiresAt' => $this->expiresAt?->toIso8601String(),
            'user' => new UserResource($this->user),
            'deviceToken' => $this->when($this->deviceToken !== null, $this->deviceToken),
        ];
    }
}
