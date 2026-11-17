<?php

namespace App\Http\Requests;

use App\Models\Strain;
use App\Services\CollectionPagination;
use App\Services\StrainCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStrainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Strain::class);
    }

    public function rules(): array
    {
        return ['sort' => ['sometimes', Rule::in(StrainCatalog::SORTS)], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'between:1,100000']];
    }

    public function messages(): array
    {
        return ['*' => 'catalog.validation.invalid'];
    }
}
