<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectKind;
use App\Enums\RepositoryProvider;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends ProjectRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $kind = is_string($this->input('tipo')) ? ProjectKind::tryFrom($this->input('tipo')) : null;

        return [...$this->identityRules(), ...$this->editableRules('required', $kind)];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $limit = (int) config('projects.max_per_user');

                if ($this->user()?->projects()->count() >= $limit) {
                    $validator->errors()->add('nombre', __('You have reached the limit of :max projects.', ['max' => $limit]));
                }
            },
            // With an account connected at the host, the repository has to be one that account
            // reaches. Without one the name is taken as typed, as before accounts could be connected.
            function (Validator $validator): void {
                $user = $this->user();
                $provider = is_string($this->input('repositorio.proveedor')) ? RepositoryProvider::tryFrom($this->input('repositorio.proveedor')) : null;
                $repository = $this->input('repositorio.nombreCompleto');

                if ($user === null || $provider === null || ! is_string($repository) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $connector = app(RepositoryConnector::class);

                if ($connector->connected($user, $provider) && $connector->repository($user, $provider, $repository) === null) {
                    $validator->errors()->add('repositorio.nombreCompleto', __('Your connected account cannot reach this repository.'));
                }
            },
        ];
    }
}
