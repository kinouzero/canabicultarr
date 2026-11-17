<?php

namespace App\Http\Requests;

use App\Models\Strain;
use App\Services\LifecycleDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStrainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('strain') ? $this->user()->can('update', $this->route('strain')) : $this->user()->can('create', Strain::class);
    }

    public function rules(): array
    {
        $presence = $this->route('strain') ? 'sometimes' : 'required';
        $rules = [
            'name' => [$presence, 'required', 'string', 'max:120'],
            'version' => [$this->route('strain') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'photo_document_id' => ['sometimes', 'required', 'uuid'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:http,https'],
            'classification' => ['sometimes', 'nullable', Rule::in(['indica', 'sativa', 'hybrid'])],
            'theoretical_yield' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'thc' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cbd' => ['sometimes', 'nullable', 'string', 'max:120'],
            'seed_inventory_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('unit', 'piece')->where('type', 'seeds'), Rule::unique('strains', 'seed_inventory_item_id')->ignore($this->route('strain')?->id)],
            'is_archived' => ['sometimes', 'boolean'],
            'duration_overrides' => ['sometimes', 'array:'.implode(',', LifecycleDefinition::durationKeys())],
        ];
        foreach (['genetics', 'lineage', 'aroma', 'reported_effects', 'notes'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }
        foreach (LifecycleDefinition::durationKeys() as $key) {
            $rules['duration_overrides.'.$key] = ['sometimes', 'nullable', 'integer', 'between:'.($key === 'flush' ? 0 : 1).',3650'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['*' => 'catalog.validation.invalid'];
    }
}
