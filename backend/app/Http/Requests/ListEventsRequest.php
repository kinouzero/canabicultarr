<?php

namespace App\Http\Requests;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Models\EventEntry;
use App\Rules\ZonedTimestamp;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\EventJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', EventEntry::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules(EventJournal::SORT_COLUMNS),
            'search' => ['nullable', 'string', 'max:160'], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'],
            'event_type_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::enum(EventCategory::class)], 'severity' => ['nullable', Rule::enum(EventSeverity::class)],
            'date_from' => ['nullable', new ZonedTimestamp], 'date_to' => ['nullable', new ZonedTimestamp],
            'site_id' => ['nullable', 'uuid'], 'room_id' => ['nullable', 'uuid'], 'zone_id' => ['nullable', 'uuid'],
            'asset_id' => ['nullable', 'uuid'], 'sensor_id' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', 'search.*' => 'events.validation.searchInvalid', 'page.*' => 'events.validation.pageInvalid',
            'event_type_id.*' => 'eventTypes.validation.type', 'category.*' => 'events.validation.categoryInvalid', 'severity.*' => 'events.validation.severityInvalid',
            'site_id.*' => 'events.validation.subjectInvalid', 'room_id.*' => 'events.validation.subjectInvalid',
            'zone_id.*' => 'events.validation.subjectInvalid', 'asset_id.*' => 'events.validation.subjectInvalid', 'sensor_id.*' => 'events.validation.subjectInvalid'];
    }
}
