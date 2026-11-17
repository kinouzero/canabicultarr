<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use App\Services\IntegrationChannelFilters;
use Illuminate\Foundation\Http\FormRequest;

class HttpSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [...IntegrationChannelFilters::rules(), 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1']];
        }
        if ($this->routeIs('http-sources.store')) {
            return ['sensor_id' => ['required', 'uuid'], 'expires_days' => ['required', 'integer', 'min:1', 'max:365']];
        }
        $rules = ['version' => ['required', 'integer', 'min:1', 'max:2147483647']];
        if ($this->routeIs('http-sources.rotate')) {
            $rules['expires_days'] = ['required', 'integer', 'min:1', 'max:365'];
        }
        if ($this->isMethod('PATCH')) {
            $rules['is_enabled'] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['sensor_id.*' => 'httpIngestion.validation.sensorInvalid', 'expires_days.*' => 'httpIngestion.validation.expiryInvalid', 'version.*' => 'httpIngestion.validation.versionInvalid', 'is_enabled.*' => 'httpIngestion.validation.stateInvalid', 'page.*' => 'httpIngestion.validation.pageInvalid'];
    }
}
