<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use App\Services\IntegrationChannelFilters;
use Illuminate\Foundation\Http\FormRequest;

class MqttSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [...IntegrationChannelFilters::rules(), 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']];
        }
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1', 'max:2147483647']];
        }
        if ($this->isMethod('PATCH')) {
            return ['version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'is_enabled' => ['required', 'boolean']];
        }

        return ['sensor_id' => ['required', 'uuid'], 'topic' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x20\x7f#+$]+$/uD']];
    }

    public function messages(): array
    {
        return ['*' => 'mqtt.errors.invalidInput'];
    }
}
