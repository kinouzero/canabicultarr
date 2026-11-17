<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\SourceDiagnostics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceDiagnosticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(SourceDiagnostics::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'kind' => ['nullable', Rule::in(['home_assistant', 'homebridge', 'http', 'mqtt'])],
            'state' => ['nullable', Rule::in(['enabled', 'paused', 'sensor_disabled', 'device_retired', 'credential_invalid', 'source_unavailable'])],
            'freshness' => ['nullable', Rule::in(['fresh', 'stale', 'missing', 'unknown'])]];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', '*' => 'sourceDiagnostics.validation.filtersInvalid'];
    }
}
