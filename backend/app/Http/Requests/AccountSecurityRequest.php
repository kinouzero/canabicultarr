<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountSecurityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('account.security');
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }
        $rules = ['current_password' => ['required', 'string', 'max:128']];
        if ($this->routeIs('account.password')) {
            $rules['password'] = ['required', 'string', 'min:12', 'max:128', 'confirmed', 'regex:/\S/u'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['current_password.*' => 'accountSecurity.errors.currentPasswordRequired', 'password.required' => 'auth.validation.passwordRequired', 'password.string' => 'auth.validation.passwordRequired', 'password.regex' => 'auth.validation.passwordRequired', 'password.min' => 'auth.validation.passwordLength', 'password.max' => 'auth.validation.passwordLength', 'password.confirmed' => 'auth.validation.passwordConfirmation'];
    }
}
