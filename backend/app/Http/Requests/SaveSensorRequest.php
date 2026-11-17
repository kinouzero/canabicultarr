<?php

namespace App\Http\Requests;

use App\Models\Sensor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveSensorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('sensor') ? $this->user()->can('update', $this->route('sensor')) : $this->user()->can('create', Sensor::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('channel_key'))) {
            $this->merge(['channel_key' => Str::lower(trim($this->input('channel_key')))]);
        }
    }

    public function rules(): array
    {
        $sensor = $this->route('sensor');

        return [
            'name' => [$sensor ? 'sometimes' : 'required', 'required', 'string', 'max:120'],
            'device_id' => $sensor ? ['sometimes', 'uuid', Rule::in([$sensor->asset_id])] : ['required', 'uuid'],
            'measurement_type_id' => $sensor ? ['sometimes', 'uuid', Rule::in([$sensor->measurement_type_id])] : ['required', 'uuid'],
            'channel_key' => $sensor ? ['sometimes', Rule::in([$sensor->channel_key])] : ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'expected_interval_seconds' => ['sometimes', 'nullable', 'integer', 'between:1,86400'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['name.*' => 'sensors.validation.nameInvalid', 'device_id.*' => 'sensors.validation.deviceInvalid',
            'measurement_type_id.*' => 'sensors.validation.typeInvalid', 'channel_key.*' => 'sensors.validation.channelInvalid',
            'is_enabled.*' => 'sensors.validation.enabledInvalid', 'expected_interval_seconds.*' => 'sensors.validation.intervalInvalid',
            'description.*' => 'sensors.validation.descriptionInvalid'];
    }
}
