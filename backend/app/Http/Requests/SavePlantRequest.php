<?php

namespace App\Http\Requests;

use App\Models\Plant;
use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('plant') ? $this->user()->can('update', $this->route('plant')) : $this->user()->can('create', Plant::class);
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['request_id', 'strain_id'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower($this->input($field));
            }
        }
        foreach (['device_ids', 'summary_sensor_ids'] as $field) {
            if (is_array($this->input($field))) {
                $data[$field] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $this->input($field));
            }
        }
        if (is_array($this->input('location')) && is_string($this->input('location.id'))) {
            $data['location'] = [...$this->input('location'), 'id' => strtolower($this->input('location.id'))];
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $editing = $this->route('plant') !== null;

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'version' => [$editing ? 'required' : 'prohibited', 'integer', 'min:1'],
            'request_id' => [$editing ? 'prohibited' : 'required', 'uuid'],
            'strain_id' => [$editing ? 'sometimes' : 'required', 'required', 'uuid'],
            'strain_change_reason' => [$editing ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:2000'],
            'planned' => [$editing ? 'prohibited' : 'sometimes', 'boolean'],
            'started_at' => [Rule::prohibitedIf($editing || $this->boolean('planned')), 'nullable', new ZonedTimestamp('plants.validation.invalid')],
            'location' => [$editing ? 'sometimes' : 'required', 'required', 'array:type,id', 'required_array_keys:type,id'],
            'location.type' => ['required_with:location', Rule::in(['site', 'room', 'zone'])],
            'location.id' => ['required_with:location', 'uuid'],
            'summary_sensor_ids' => ['sometimes', 'nullable', 'array', 'list', 'max:100'],
            'summary_sensor_ids.*' => ['required', 'uuid', 'distinct'],
            'device_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'device_ids.*' => ['required', 'uuid', 'distinct'],
            'photo_document_id' => [$editing ? 'sometimes' : 'prohibited', 'nullable', 'uuid'],
            'phase' => ['prohibited'], 'duration_snapshot' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['*' => 'plants.validation.invalid'];
    }
}
