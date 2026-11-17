<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'password' => ['required', 'string', 'max:128']];
    }

    public function messages(): array
    {
        return ['email.*' => 'auth.validation.emailInvalid', 'password.*' => 'auth.validation.passwordRequired'];
    }
}
