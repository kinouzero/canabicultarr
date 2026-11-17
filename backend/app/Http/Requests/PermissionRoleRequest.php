<?php

namespace App\Http\Requests;

use App\Enums\DelegatedPermission;
use App\Models\PermissionRole;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\PermissionRoleAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('permissionRole') ? $this->user()->can('update', $this->route('permissionRole')) : $this->user()->can($this->isMethod('GET') ? 'viewAny' : 'create', PermissionRole::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['sort' => CollectionSorting::rules(PermissionRoleAdministration::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])]];
        }
        $rules = ['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'], 'permissions' => ['present', 'array', 'list', 'max:'.count(DelegatedPermission::cases())], 'permissions.*' => ['required', 'string', 'distinct:strict', Rule::enum(DelegatedPermission::class)], 'is_archived' => ['sometimes', 'boolean']];
        if ($this->isMethod('PATCH')) {
            $rules['version'] = ['required', 'integer', 'min:1', 'max:2147483647'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'name.*' => 'accessProfiles.validation.nameInvalid', 'description.*' => 'accessProfiles.validation.descriptionInvalid', 'permissions.*' => 'users.validation.permissionsInvalid', 'permissions.*.*' => 'users.validation.permissionsInvalid', 'is_archived.*' => 'accessProfiles.validation.stateInvalid', 'version.*' => 'accessProfiles.validation.versionInvalid', 'search.*' => 'accessProfiles.validation.searchInvalid', 'page.*' => 'accessProfiles.validation.pageInvalid', 'status.*' => 'accessProfiles.validation.stateInvalid'];
    }
}
