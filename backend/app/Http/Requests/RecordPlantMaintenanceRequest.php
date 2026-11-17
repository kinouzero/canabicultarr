<?php

namespace App\Http\Requests;

use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;

class RecordPlantMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('plant'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => trim(preg_replace('/\s+/u', ' ', $this->input('type')))]);
        }
        if (is_array($this->input('document_ids'))) {
            $this->merge(['document_ids' => array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $this->input('document_ids'))]);
        }
        if (is_string($this->input('request_id'))) {
            $this->merge(['request_id' => strtolower($this->input('request_id'))]);
        }
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'max:80'],
            'occurred_at' => ['sometimes', 'nullable', new ZonedTimestamp('plants.validation.invalid')],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'document_ids' => ['sometimes', 'array', 'max:10'], 'document_ids.*' => ['required', 'uuid', 'distinct']];
    }

    public function messages(): array
    {
        return ['*' => 'plants.validation.invalid'];
    }
}
