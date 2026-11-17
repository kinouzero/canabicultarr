<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use App\Services\IntegrationChannelFilters;
use Illuminate\Foundation\Http\FormRequest;

class HomeAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1']];
        }
        if ($this->isMethod('PATCH')) {
            return ['version' => ['required', 'integer', 'min:1'], 'is_enabled' => ['required', 'boolean']];
        }
        if ($this->routeIs('home-assistant.import')) {
            return ['device_ids' => ['required', 'array', 'min:1', 'max:10'], 'device_ids.*' => ['required', 'string', 'max:255', 'distinct', 'regex:/^(device:[a-zA-Z0-9_-]{1,128}|entity:[a-z0-9_.:-]{1,248})$/D']];
        }

        return [...IntegrationChannelFilters::rules(), 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['*' => 'measurements.validation.inputInvalid', 'device_ids.*.*' => 'measurements.validation.inputInvalid'];
    }
}
