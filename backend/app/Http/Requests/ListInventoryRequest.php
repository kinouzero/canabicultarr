<?php

namespace App\Http\Requests;

use App\Enums\InventoryItemType;
use App\Models\InventoryItem;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\Inventory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', InventoryItem::class);
    }

    public function rules(): array
    {
        return ['type' => ['nullable', Rule::enum(InventoryItemType::class)], 'sort' => CollectionSorting::rules(Inventory::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:160'], 'state' => ['nullable', Rule::in(['active', 'archived', 'all'])], 'low_stock' => ['nullable', 'boolean'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', '*' => 'inventory.validation.filterInvalid'];
    }
}
