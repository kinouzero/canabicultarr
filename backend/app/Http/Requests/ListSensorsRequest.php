<?php

namespace App\Http\Requests;

use App\Models\Sensor;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\SensorAdministration;
use Illuminate\Foundation\Http\FormRequest;

class ListSensorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Sensor::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(SensorAdministration::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'device_id' => ['nullable', 'uuid'], 'measurement_type_id' => ['nullable', 'uuid'], 'is_enabled' => ['nullable', 'boolean']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'sensors.validation.searchInvalid', 'page.*' => 'sensors.validation.pageInvalid',
            'device_id.*' => 'sensors.validation.deviceInvalid', 'measurement_type_id.*' => 'sensors.validation.typeInvalid', 'is_enabled.*' => 'sensors.validation.enabledInvalid'];
    }
}
