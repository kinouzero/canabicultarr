<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\UserAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(UserAdministration::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'administrators' => ['nullable', 'boolean'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'permission_role_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'users.validation.searchInvalid', 'page.*' => 'users.validation.pageInvalid', 'status.*' => 'users.validation.activeInvalid', 'permission_role_id.*' => 'accessProfiles.errors.unavailable'];
    }
}
