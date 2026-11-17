<?php

namespace App\Http\Requests;

use App\Enums\InventoryItemType;
use App\Models\InventoryItem;
use App\Rules\StockQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('item') ? $this->user()->can('update', $this->route('item')) : $this->user()->can('create', InventoryItem::class);
    }

    public function rules(): array
    {
        $updating = $this->route('item') !== null;

        return ['name' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:120'], 'sku' => ['sometimes', 'nullable', 'string', 'max:80'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_dose_per_liter' => ['sometimes', 'nullable', new StockQuantity, 'numeric', 'min:0.001', 'max:1000000'],
            'capacity_liters' => ['sometimes', 'nullable', new StockQuantity, 'numeric', 'min:0.001', 'max:1000000'],
            'type' => ['sometimes', 'required', Rule::enum(InventoryItemType::class)],
            'unit' => [$updating ? 'sometimes' : 'required', 'required', Rule::in(['piece', 'g', 'kg', 'ml', 'l', 'm'])], 'decimal_places' => ['sometimes', 'required', 'integer', 'min:0', 'max:3'], 'threshold' => ['sometimes', 'nullable', new StockQuantity],
            'is_archived' => $updating ? ['sometimes', 'boolean'] : ['exclude'], 'version' => $updating ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['exclude']];
    }

    public function messages(): array
    {
        return ['default_dose_per_liter.*' => 'inventory.validation.doseInvalid', 'capacity_liters.*' => 'inventory.validation.capacityInvalid', 'type.*' => 'inventory.validation.typeInvalid', 'name.*' => 'inventory.validation.nameInvalid', 'sku.*' => 'inventory.validation.skuInvalid', 'description.*' => 'inventory.validation.descriptionInvalid', 'decimal_places.*' => 'inventory.validation.decimalsInvalid', 'unit.*' => 'inventory.validation.unitInvalid', 'threshold.*' => 'inventory.validation.quantityInvalid', 'version.*' => 'inventory.validation.versionInvalid', 'is_archived.*' => 'inventory.validation.stateInvalid'];
    }
}
