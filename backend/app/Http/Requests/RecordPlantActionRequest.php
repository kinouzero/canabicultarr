<?php

namespace App\Http\Requests;

use App\Rules\ZonedTimestamp;
use App\Services\PlantInventory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPlantActionRequest extends FormRequest
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
        return ['container' => ['sometimes', 'nullable', 'array:inventory_item_id,balance_id,previous'],
            'container.inventory_item_id' => ['required_with:container', 'uuid'],
            'container.balance_id' => ['required_with:container', 'uuid'],
            'container.previous' => [Rule::excludeIf(fn () => $this->input('container') === null), 'present_with:container', 'nullable', Rule::in(['recover', 'discard'])],
            'substrates' => ['sometimes', 'array', 'max:20'],
            'substrates.*' => ['required', 'array:inventory_item_id,balance_id,quantity,unit'],
            'substrates.*.inventory_item_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'substrates.*.balance_id' => ['required', 'uuid'],
            'substrates.*.unit' => ['required', Rule::in(['piece', 'g', 'kg', 'ml', 'l', 'm'])],
            'substrates.*.quantity' => PlantInventory::doseRules(),
            'harvest_weight_grams' => ['sometimes', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'], 'document_ids' => ['sometimes', 'array', 'max:10'], 'document_ids.*' => ['required', 'uuid', 'distinct'], 'version' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'action' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/D'], 'occurred_at' => ['sometimes', 'nullable', new ZonedTimestamp('plants.validation.invalid')], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return ['harvest_weight_grams.*' => 'plants.validation.harvestWeight', '*' => 'plants.validation.invalid'];
    }
}
