<?php

namespace App\Http\Requests;

use App\Models\Sensor;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\MeasurementOverview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MeasurementOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Sensor::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(MeasurementOverview::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'between:1,100000'],
            'site_id' => ['nullable', 'uuid'], 'measurement_type_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['fresh', 'stale', 'missing', 'unknown', 'disabled'])],
            'pinned_only' => ['sometimes', 'boolean'], 'sensor_ids' => ['sometimes', 'array', 'max:100'], 'sensor_ids.*' => ['uuid', 'distinct']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', '*' => 'measurements.validation.inputInvalid'];
    }
}
