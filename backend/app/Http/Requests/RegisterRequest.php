<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RegisterRequest extends SetupAdministratorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['token']);
        $rules['email'][] = Rule::unique('users', 'email');
        $rules['password'][] = 'regex:/\S/u';

        return $rules;
    }

    public function messages(): array
    {
        return [...parent::messages(), 'email.unique' => 'auth.validation.emailTaken', 'password.regex' => 'auth.validation.passwordLength'];
    }
}
