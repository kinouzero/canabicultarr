<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SetupAdministratorRequest extends FormRequest
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
        return [
            'locale' => ['sometimes', 'in:en,fr'],
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'locale.*' => 'auth.errors.requestFailed',
            'token.*' => 'auth.validation.tokenRequired',
            'name.*' => 'auth.validation.nameInvalid',
            'email.*' => 'auth.validation.emailInvalid',
            'password.required' => 'auth.validation.passwordRequired',
            'password.string' => 'auth.validation.passwordRequired',
            'password.min' => 'auth.validation.passwordLength',
            'password.max' => 'auth.validation.passwordLength',
            'password.confirmed' => 'auth.validation.passwordConfirmation',
        ];
    }
}
