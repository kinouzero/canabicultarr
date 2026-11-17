<?php

namespace App\Http\Requests;

use App\Enums\DelegatedPermission;
use Illuminate\Foundation\Http\FormRequest;

class SaveStorageLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasOperationalPermission(DelegatedPermission::Inventory);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:240'], 'description' => ['nullable', 'string', 'max:2000'], 'is_archived' => ['sometimes', 'boolean']];
    }

    public function messages(): array
    {
        return ['name.*' => 'inventory.validation.nameInvalid', 'description.*' => 'inventory.validation.descriptionInvalid'];
    }
}
