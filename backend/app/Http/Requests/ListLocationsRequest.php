<?php

namespace App\Http\Requests;

use App\Models\Site;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\LocationAdministration;
use Illuminate\Foundation\Http\FormRequest;

class ListLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Site::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(LocationAdministration::SORT_COLUMNS), 'search' => ['nullable', 'string', 'max:120'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'locations.validation.searchInvalid', 'page.*' => 'locations.validation.pageInvalid'];
    }
}
