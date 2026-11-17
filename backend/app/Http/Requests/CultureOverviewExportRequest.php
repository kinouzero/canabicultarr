<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class CultureOverviewExportRequest extends ReportExportRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && $this->user()->can('dashboard.view');
    }

    public function rules(): array
    {
        return [
            'site_id' => ['nullable', 'uuid'], 'days' => ['required', 'integer', Rule::in([7, 30])],
            'sections' => ['required', 'array', 'min:1', 'max:6'],
            'sections.*' => ['required', 'distinct', Rule::in(['summary', 'stages', 'strains', 'activity', 'locations', 'upcoming'])],
            'format' => ['required', Rule::in(['csv', 'pdf'])], 'locale' => ['required', Rule::in(['en', 'fr'])],
            'timezone' => ['required', 'string', 'max:64', 'timezone'], 'request_key' => ['required', 'uuid'],
        ];
    }
}
