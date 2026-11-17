<?php

namespace App\Http\Requests;

use App\Models\Plant;
use Illuminate\Foundation\Http\FormRequest;

class SavePlantMaintenanceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Plant::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80'], 'version' => [$this->route('maintenanceType') ? 'required' : 'sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['*' => 'plants.validation.invalid'];
    }
}
