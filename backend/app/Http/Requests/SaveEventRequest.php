<?php

namespace App\Http\Requests;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Models\EventEntry;
use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('event') ? $this->user()->can('update', $this->route('event')) : $this->user()->can('create', EventEntry::class);
    }

    public function rules(): array
    {
        $updating = $this->route('event') !== null;
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'required', 'string', 'max:160'],
            'body' => [$presence, 'required', 'string', 'max:10000'],
            'category' => [$presence, 'required', Rule::enum(EventCategory::class)],
            'event_type_id' => ['sometimes', 'nullable', 'uuid'],
            'severity' => [$presence, 'required', Rule::enum(EventSeverity::class)],
            'occurred_at' => [$presence, 'required', new ZonedTimestamp],
            'version' => $updating ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['exclude'],
            'subject' => ['sometimes', 'nullable', 'array:type,id', 'required_array_keys:type,id'],
            'subject.type' => ['required_with:subject', Rule::in(['site', 'room', 'zone', 'asset', 'sensor'])],
            'subject.id' => ['required_with:subject', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return ['title.*' => 'events.validation.titleInvalid', 'body.*' => 'events.validation.bodyInvalid',
            'event_type_id.*' => 'eventTypes.validation.type', 'category.*' => 'events.validation.categoryInvalid', 'severity.*' => 'events.validation.severityInvalid',
            'occurred_at.*' => 'events.validation.timeInvalid', 'version.*' => 'events.validation.versionInvalid',
            'subject.*' => 'events.validation.subjectInvalid', 'subject.type.*' => 'events.validation.subjectInvalid', 'subject.id.*' => 'events.validation.subjectInvalid'];
    }
}
