<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\CollectionPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Document::class);
    }

    public function rules(): array
    {
        return ['folder_id' => ['sometimes', 'nullable', 'uuid'], 'sort' => ['nullable', Rule::in(['name', 'newest', 'oldest', 'size'])], 'search' => ['nullable', 'string', 'max:160'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'state' => ['nullable', Rule::in(['active', 'archived', 'all'])], 'format' => ['nullable', Rule::in(['pdf', 'image', 'text'])],
            'site_id' => ['nullable', 'uuid'], 'room_id' => ['nullable', 'uuid'], 'zone_id' => ['nullable', 'uuid'], 'asset_id' => ['nullable', 'uuid'], 'sensor_id' => ['nullable', 'uuid'], 'event_entry_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['*' => 'documents.validation.filterInvalid'];
    }
}
