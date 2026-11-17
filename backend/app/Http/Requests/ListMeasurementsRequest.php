<?php

namespace App\Http\Requests;

use App\Rules\ZonedTimestamp;
use App\Services\CollectionPagination;
use Illuminate\Foundation\Http\FormRequest;

class ListMeasurementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('sensor'));
    }

    public function rules(): array
    {
        return ['per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'date_from' => ['nullable', new ZonedTimestamp('measurements.validation.timeInvalid')], 'date_to' => ['nullable', new ZonedTimestamp('measurements.validation.timeInvalid')]];
    }

    public function messages(): array
    {
        return ['*' => 'measurements.validation.inputInvalid'];
    }
}
