<?php

namespace App\Http\Requests;

use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && (! $this->route('sensor') || $this->user()->can('view', $this->route('sensor')));
    }

    public function rules(): array
    {
        if ($this->route()->getActionMethod() !== 'store') {
            return [];
        }

        return ['date_from' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')], 'date_to' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')],
            'as_of' => ['nullable', new ZonedTimestamp('reports.validation.timeInvalid')], 'group_by' => ['nullable', Rule::in(['hour', 'day'])],
            'format' => ['required', Rule::in(['csv', 'pdf'])], 'locale' => ['required', Rule::in(['en', 'fr'])], 'timezone' => ['required', 'string', 'max:64', 'timezone'], 'request_key' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['*' => 'reportExports.errors.inputInvalid'];
    }
}
