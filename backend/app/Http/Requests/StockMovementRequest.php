<?php

namespace App\Http\Requests;

use App\Rules\StockQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }

    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['receipt', 'issue', 'transfer', 'count'])], 'quantity' => ['required', new StockQuantity], 'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'request_id' => ['required', 'uuid'],
            'source' => [Rule::requiredIf(in_array($this->input('kind'), ['issue', 'transfer'], true)), Rule::prohibitedIf(! in_array($this->input('kind'), ['issue', 'transfer'], true)), 'array:id', 'required_array_keys:id'],
            'destination' => [Rule::requiredIf(in_array($this->input('kind'), ['receipt', 'transfer', 'count'], true)), Rule::prohibitedIf(! in_array($this->input('kind'), ['receipt', 'transfer', 'count'], true)), 'array:id', 'required_array_keys:id'],
            'source.id' => ['required_with:source', 'uuid'],
            'destination.id' => ['required_with:destination', 'uuid']];
    }

    public function messages(): array
    {
        return ['quantity.*' => 'inventory.validation.quantityInvalid', 'reason.*' => 'inventory.validation.reasonInvalid', 'kind.*' => 'inventory.validation.kindInvalid', 'version.*' => 'inventory.validation.versionInvalid', 'request_id.*' => 'inventory.validation.requestInvalid', 'source.*' => 'inventory.validation.locationInvalid', 'source.type.*' => 'inventory.validation.locationInvalid', 'source.id.*' => 'inventory.validation.locationInvalid', 'destination.*' => 'inventory.validation.locationInvalid', 'destination.type.*' => 'inventory.validation.locationInvalid', 'destination.id.*' => 'inventory.validation.locationInvalid'];
    }
}
