<?php

namespace App\Http\Requests;

use App\Models\MeasurementType;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\MeasurementTypeAdministration;
use Illuminate\Foundation\Http\FormRequest;

class ListMeasurementTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', MeasurementType::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(MeasurementTypeAdministration::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'sensors.validation.searchInvalid', 'page.*' => 'sensors.validation.pageInvalid',
            'device_id.*' => 'sensors.validation.deviceInvalid', 'measurement_type_id.*' => 'sensors.validation.typeInvalid', 'is_enabled.*' => 'sensors.validation.enabledInvalid'];
    }
}
