<?php

namespace App\Http\Requests;

use App\Rules\StockQuantity;
use App\Rules\ZonedTimestamp;
use App\Services\PlantInventory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPlantWateringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('plant'));
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('document_ids'))) {
            $this->merge(['document_ids' => array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $this->input('document_ids'))]);
        }
        if (is_string($this->input('request_id'))) {
            $this->merge(['request_id' => strtolower($this->input('request_id'))]);
        }
    }

    public function rules(): array
    {
        return ['document_ids' => ['sometimes', 'array', 'max:10'], 'document_ids.*' => ['required', 'uuid', 'distinct'], 'version' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'water_liters' => ['sometimes', 'nullable', new StockQuantity, 'numeric', 'min:0.001', 'max:1000000'],
            'fertilizers' => ['sometimes', 'array', 'max:20'],
            'fertilizers.*' => ['required', 'array:inventory_item_id,balance_id,dose_per_liter,unit'],
            'fertilizers.*.inventory_item_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'fertilizers.*.balance_id' => ['required', 'uuid'],
            'fertilizers.*.unit' => ['required', Rule::in(['piece', 'g', 'kg', 'ml', 'l', 'm'])],
            'fertilizers.*.dose_per_liter' => PlantInventory::doseRules(),
            'with_products' => ['required', 'boolean'], 'occurred_at' => ['sometimes', 'nullable', new ZonedTimestamp('plants.validation.invalid')], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return ['*' => 'plants.validation.invalid'];
    }
}
