<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectKind;
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
        ];
    }
}
