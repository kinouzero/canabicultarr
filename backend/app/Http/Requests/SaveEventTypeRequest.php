<?php

namespace App\Http\Requests;

use App\Services\EventTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEventTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('instance-settings.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80'], 'icon' => ['required', Rule::in(EventTypes::ICONS)],
            'contexts' => ['required', 'array', 'list', 'min:1', 'max:6'], 'contexts.*' => ['required', 'distinct', Rule::in(EventTypes::CONTEXTS)],
            'is_archived' => ['sometimes', 'boolean'], 'version' => $this->route('eventType') ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['exclude']];
    }

    public function messages(): array
    {
        return ['name.*' => 'eventTypes.validation.name', 'icon.*' => 'eventTypes.validation.icon', 'contexts.*' => 'eventTypes.validation.contexts', 'contexts.*.*' => 'eventTypes.validation.contexts', 'is_archived.*' => 'eventTypes.validation.state', 'version.*' => 'eventTypes.validation.version'];
    }
}
