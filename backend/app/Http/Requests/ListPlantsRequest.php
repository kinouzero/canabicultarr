<?php

namespace App\Http\Requests;

use App\Models\Plant;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\LifecycleDefinition;
use App\Services\PlantTracking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Plant::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(PlantTracking::SORT_COLUMNS), 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'between:1,100000'], 'search' => ['nullable', 'string', 'max:160'], 'status' => ['sometimes', Rule::in(['active', 'planned', 'archived', 'all'])], 'phase' => ['nullable', Rule::in(LifecycleDefinition::ids())], 'location_type' => ['required_with:location_id', Rule::in(['site', 'room', 'zone'])], 'location_id' => ['required_with:location_type', 'uuid'], 'site_id' => ['nullable', 'uuid'], 'strain_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', '*' => 'plants.validation.invalid'];
    }
}
