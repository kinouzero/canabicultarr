<?php

namespace App\Http\Requests;

use App\Enums\DelegatedPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'is_active' => ['sometimes', 'boolean'],
            'inventory_location_access' => ['sometimes', 'nullable', 'array', 'list', 'max:100'],
            'inventory_location_access.*' => ['required', 'uuid', 'distinct:strict'],
            'location_access' => ['sometimes', 'nullable', 'array', 'list', 'max:100'],
            'location_access.*' => ['required', 'array:type,id'],
            'location_access.*.type' => ['required', Rule::in(['site', 'room', 'zone'])],
            'location_access.*.id' => ['required', 'uuid'],
            'permission_role_ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'permission_role_ids.*' => ['required', 'uuid', 'distinct:strict'],
            'permission_denials' => ['sometimes', 'array', 'list', 'max:'.count(DelegatedPermission::cases())],
            'permission_denials.*' => ['required', 'string', 'distinct:strict', Rule::enum(DelegatedPermission::class)],
            'permission_grants' => ['sometimes', 'array', 'list', 'max:'.count(DelegatedPermission::cases())],
            'permission_grants.*' => ['required', 'string', 'distinct:strict', Rule::enum(DelegatedPermission::class)],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_denials.*' => 'users.validation.permissionsInvalid',
            'permission_denials.*.*' => 'users.validation.permissionsInvalid',
            'inventory_location_access' => 'storageLocations.invalid',
            'inventory_location_access.*' => 'storageLocations.invalid',
            'location_access' => 'locationAccess.errors.invalidSelection',
            'location_access.*' => 'locationAccess.errors.invalidSelection',
            'location_access.*.*' => 'locationAccess.errors.invalidSelection',
            'permission_grants.*' => 'users.validation.permissionsInvalid',
            'permission_grants.*.*' => 'users.validation.permissionsInvalid',
            'permission_role_ids' => 'accessProfiles.errors.unavailable',
            'permission_role_ids.*' => 'accessProfiles.errors.unavailable',
            'locale.*' => 'users.validation.localeInvalid',
            'name.*' => 'users.validation.nameInvalid',
            'email.unique' => 'users.validation.emailTaken',
            'email.*' => 'users.validation.emailInvalid',
            'is_active.*' => 'users.validation.activeInvalid',
            'password.required' => 'auth.validation.passwordRequired',
            'password.string' => 'auth.validation.passwordRequired',
            'password.regex' => 'auth.validation.passwordRequired',
            'password.min' => 'auth.validation.passwordLength',
            'password.max' => 'auth.validation.passwordLength',
            'password.confirmed' => 'auth.validation.passwordConfirmation',
        ];
    }
}
