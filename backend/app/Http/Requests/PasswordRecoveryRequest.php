<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class PasswordRecoveryRequest extends FormRequest
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
        $rules = ['email' => ['required', 'string', 'email', 'max:254']];
        if ($this->routeIs('password.recovery.reset')) {
            $rules['token'] = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D'];
            $rules['password'] = ['required', 'string', 'min:12', 'max:128', 'confirmed', 'regex:/\S/u'];
        } else {
            $rules['locale'] = ['required', 'in:en,fr'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['email.*' => 'auth.validation.emailInvalid', 'token.*' => 'passwordRecovery.invalidLink', 'locale.*' => 'passwordRecovery.invalidLocale', 'password.required' => 'auth.validation.passwordRequired', 'password.string' => 'auth.validation.passwordRequired', 'password.regex' => 'auth.validation.passwordRequired', 'password.min' => 'auth.validation.passwordLength', 'password.max' => 'auth.validation.passwordLength', 'password.confirmed' => 'auth.validation.passwordConfirmation'];
    }
}
