<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;

class SaveLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // All three hierarchy levels share the same administrative write policy.
        return $this->user()->can('create', Site::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => $this->is('api/v1/sites/*/rooms', 'api/v1/sites/*/rooms/*') ? ['sometimes', 'required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D'] : ['prohibited'],
            'photo_document_id' => ['sometimes', 'nullable', 'uuid'],
            'watering_alert_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['code.*' => 'locations.validation.codeInvalid', 'photo_document_id.*' => 'locations.errors.photoUnavailable', 'watering_alert_days.*' => 'locations.validation.wateringDaysInvalid', 'name.*' => 'locations.validation.nameInvalid', 'description.*' => 'locations.validation.descriptionInvalid'];
    }
}
