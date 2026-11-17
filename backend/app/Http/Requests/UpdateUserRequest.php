<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateUserRequest extends CreateUserRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['email'] = ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($this->route('user'))];
        $rules['is_active'] = ['required', 'boolean'];
        $rules['password'] = ['nullable', 'string', 'min:12', 'max:128', 'confirmed', 'regex:/\S/u'];

        return $rules;
    }
}
