<?php

namespace App\Http\Requests\Panel;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The challenge id is not an input here: it lives in the session opened by the password step.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        /** @var int $length */
        $length = config('auth.two_factor.code_length');

        return [
            'code' => ['required', 'string', 'digits:'.$length],
            'remember_device' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * People type codes as "123 456" or paste them with spaces; only the digits matter.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/\s+/', '', $this->input('code'))]);
        }
    }
}
