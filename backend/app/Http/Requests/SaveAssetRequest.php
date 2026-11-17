<?php

namespace App\Http\Requests;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\DeviceControlClient;
use App\Services\PumpControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('asset')
            ? $this->user()->can('update', $this->route('asset'))
            : $this->user()->can('create', Asset::class);
    }

    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'category' => ['sometimes', 'required', Rule::enum(AssetCategory::class)],
            'pump_control' => ['sometimes', 'nullable', 'array:provider,entity,mode,seconds', 'required_array_keys:provider,entity'],
            'pump_control.mode' => ['required_with:pump_control', Rule::in(['auto', 'timer', 'manual'])],
            'pump_control.seconds' => ['nullable', 'required_if:pump_control.mode,timer', 'integer', 'min:1', 'max:86400'],
            'pump_control.provider' => ['required_with:pump_control', Rule::in(['home_assistant', 'homebridge'])],
            'pump_control.entity' => ['required_with:pump_control', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (! PumpControl::validTarget($this->input('pump_control.provider'), $value)) {
                    $fail('assets.pump.invalid');
                }
            }],
            'power_control' => ['sometimes', 'nullable', 'array:provider,entity,mode,on_at,off_at,timezone', 'required_array_keys:provider,entity,mode'],
            'power_control.provider' => ['required_with:power_control', Rule::in(['home_assistant', 'homebridge'])],
            'power_control.entity' => ['required_with:power_control', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (! DeviceControlClient::validTarget($this->input('power_control.provider'), $value)) {
                    $fail('assets.power.invalid');
                }
            }],
            'power_control.mode' => ['required_with:power_control', Rule::in(['manual', 'auto'])],
            'power_control.on_at' => ['nullable', 'required_if:power_control.mode,auto', 'date_format:H:i'],
            'power_control.off_at' => ['nullable', 'required_if:power_control.mode,auto', 'date_format:H:i', 'different:power_control.on_at'],
            'power_control.timezone' => ['nullable', 'required_if:power_control.mode,auto', 'timezone:all'],
            'name' => [$asset ? 'sometimes' : 'required', 'required', 'string', 'max:120'],
            'status' => ['sometimes', 'required', Rule::enum(AssetStatus::class)],
            'asset_tag' => ['sometimes', 'nullable', 'string', 'max:80'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'add_location' => [$asset ? 'sometimes' : 'prohibited', 'prohibits:locations', 'array:type,id', 'required_array_keys:type,id'],
            'add_location.type' => ['required_with:add_location', Rule::in(['site', 'room', 'zone'])],
            'add_location.id' => ['required_with:add_location', 'uuid'],
            'locations' => ['sometimes', 'array', 'max:100'],
            'locations.*' => ['required', 'array:type,id', 'required_array_keys:type,id'],
            'locations.*.type' => ['required', Rule::in(['site', 'room', 'zone'])],
            'locations.*.id' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'category' => ['sometimes', 'required', Rule::enum(AssetCategory::class)],
            'power_control' => 'assets.power.invalid', 'power_control.*' => 'assets.power.invalid', 'power_control.*.*' => 'assets.power.invalid',
            'name.*' => 'assets.validation.nameInvalid', 'category.*' => 'assets.validation.categoryInvalid',
            'status.*' => 'assets.validation.statusInvalid', 'asset_tag.*' => 'assets.validation.tagInvalid',
            'manufacturer.*' => 'assets.validation.metadataInvalid', 'model.*' => 'assets.validation.metadataInvalid',
            'serial_number.*' => 'assets.validation.metadataInvalid', 'description.*' => 'assets.validation.descriptionInvalid',
            'locations' => 'assets.validation.locationInvalid', 'locations.*' => 'assets.validation.locationInvalid', 'locations.*.*' => 'assets.validation.locationInvalid',
        ];
    }
}
