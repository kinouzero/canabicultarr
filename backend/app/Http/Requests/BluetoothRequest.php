<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use App\Services\IntegrationChannelFilters;
use App\Services\LocationAccess;
use Illuminate\Foundation\Http\FormRequest;

class BluetoothRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('integrations.manage')) {
            return false;
        }
        app(LocationAccess::class)->assertGlobal($this->user());

        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => CollectionPagination::rules(), ...IntegrationChannelFilters::rules(120)];
        }
        if ($this->routeIs('bluetooth.discover')) {
            return ['request_id' => ['required', 'uuid']];
        }
        if ($this->routeIs('bluetooth.readings')) {
            return ['request_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'readings' => ['prohibited'], 'observed_at' => ['prohibited']];
        }
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1', 'max:2147483647']];
        }
        if ($this->isMethod('PATCH')) {
            return ['version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'is_enabled' => ['required', 'boolean']];
        }

        return ['request_id' => ['required', 'uuid'], 'discovery_id' => ['required', 'uuid'], 'candidate_id' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['*' => 'bluetooth.errors.invalidInput', 'readings.*.*' => 'bluetooth.errors.invalidInput', 'kinds.*.*' => 'bluetooth.errors.invalidInput'];
    }
}
