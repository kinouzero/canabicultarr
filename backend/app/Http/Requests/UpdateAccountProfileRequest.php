<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateAccountProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('account.profile');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'in:en,fr'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($this->user()->getKey())],
        ];
    }

    public function messages(): array
    {
        return ['name.*' => 'users.validation.nameInvalid', 'email.unique' => 'users.validation.emailTaken', 'email.*' => 'users.validation.emailInvalid'];
    }
}
