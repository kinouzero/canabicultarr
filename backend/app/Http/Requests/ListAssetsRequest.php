<?php

namespace App\Http\Requests;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AssetAdministration;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Asset::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(AssetAdministration::SORT_COLUMNS),
            'category' => ['nullable', Rule::enum(AssetCategory::class)],
            'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
        ];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'assets.validation.searchInvalid', 'page.*' => 'assets.validation.pageInvalid',
            'category.*' => 'assets.validation.categoryInvalid', 'status.*' => 'assets.validation.statusInvalid'];
    }
}
