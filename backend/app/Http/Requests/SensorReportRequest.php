<?php

namespace App\Http\Requests;

use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SensorReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('sensor'));
    }

    public function rules(): array
    {
        return ['date_from' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')], 'date_to' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')], 'as_of' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')], 'group_by' => ['nullable', Rule::in(['hour', 'day'])]];
    }

    public function messages(): array
    {
        return ['*' => 'reports.validation.inputInvalid'];
    }
}
