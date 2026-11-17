<?php

namespace App\Http\Requests;

use App\Rules\StockQuantity;
use App\Services\LifecycleDefinition;
use App\Services\PlantInventory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLifecycleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('lifecycle.manage');
    }

    public function rules(): array
    {
        $rules = [
            'automatic_relocation' => ['sometimes', 'required', 'boolean'],
            'watering_alert_days' => ['sometimes', 'required', 'integer', 'between:1,3650'],
            'watering_moisture_threshold' => ['sometimes', 'required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'version' => ['required', 'integer', 'min:1'], 'durations' => ['required', 'array', 'max:33'],
            'durations.*' => ['required', 'integer', 'between:1,3650'], 'durations.flush' => ['required', 'integer', 'between:0,3650'],
            'stages' => ['sometimes', 'required', 'array', 'min:3', 'max:32'],
            'stages.*' => ['required', 'array:id,name,icon,watering,maintenance,flush,actions,fertilizer_periods,water_liters,target_location'],
            'stages.*.target_location' => ['sometimes', 'nullable', 'array:type,id'],
            'stages.*.target_location.type' => ['required_with:stages.*.target_location', Rule::in(['site', 'room', 'zone'])],
            'stages.*.target_location.id' => ['required_with:stages.*.target_location', 'uuid'],
            'stages.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,23}$/D', 'distinct'],
            'stages.*.name' => ['present', 'nullable', 'string', 'max:80'],
            'stages.*.icon' => ['required', Rule::in(LifecycleDefinition::ICONS)],
            'stages.*.watering' => ['required', 'boolean'], 'stages.*.maintenance' => ['required', 'boolean'], 'stages.*.flush' => ['required', 'boolean'],
            'stages.*.water_liters' => ['sometimes', 'nullable', new StockQuantity, 'numeric', 'min:0.001', 'max:1000000'],
            'stages.*.fertilizer_periods' => ['sometimes', 'array', 'list', 'max:20'],
            'stages.*.fertilizer_periods.*' => ['required', 'array:id,name,unit,start,end,products', 'required_array_keys:id,name,unit,start,end,products'],
            'stages.*.fertilizer_periods.*.id' => ['required', 'uuid'],
            'stages.*.fertilizer_periods.*.name' => ['present', 'nullable', 'string', 'max:80'],
            'stages.*.fertilizer_periods.*.unit' => ['required', Rule::in(['days', 'weeks', 'percent'])],
            'stages.*.fertilizer_periods.*.start' => ['required', 'integer', 'between:0,3649'],
            'stages.*.fertilizer_periods.*.end' => ['present', 'nullable', 'integer', 'between:1,3650'],
            'stages.*.fertilizer_periods.*.products' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'stages.*.fertilizer_periods.*.products.*' => ['required', 'array:inventory_item_id,dose_per_liter', 'required_array_keys:inventory_item_id,dose_per_liter'],
            'stages.*.fertilizer_periods.*.products.*.inventory_item_id' => ['required', 'uuid'],
            'stages.*.fertilizer_periods.*.products.*.dose_per_liter' => PlantInventory::doseRules(),
            'stages.*.actions' => ['present', 'array', 'max:12'],
            'stages.*.actions.*' => ['required', 'array:id,name,icon,target,requires_harvest_weight,substrates,container_inventory_item_id'],
            'stages.*.actions.*.container_inventory_item_id' => ['sometimes', 'nullable', 'uuid'],
            'stages.*.actions.*.substrates' => ['sometimes', 'array', 'max:20'],
            'stages.*.actions.*.substrates.*' => ['required', 'array:inventory_item_id,quantity'],
            'stages.*.actions.*.substrates.*.inventory_item_id' => ['required', 'uuid'],
            'stages.*.actions.*.substrates.*.quantity' => PlantInventory::doseRules(),
            'stages.*.actions.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/D'],
            'stages.*.actions.*.name' => ['present', 'nullable', 'string', 'max:80'],
            'stages.*.actions.*.icon' => ['required', Rule::in(LifecycleDefinition::ICONS)],
            'stages.*.actions.*.target' => ['required', 'string', 'max:24'],
            'stages.*.actions.*.requires_harvest_weight' => ['required', 'boolean'],
        ];

        return $rules;
    }

    public function messages(): array
    {
        return ['*' => 'catalog.validation.invalid'];
    }
}
