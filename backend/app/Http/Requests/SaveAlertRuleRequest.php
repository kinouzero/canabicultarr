<?php

namespace App\Http\Requests;

use App\Models\AlertRule;
use App\Rules\AlertBound;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('rule') ? $this->user()->can('update', $this->route('rule')) : $this->user()->can('create', AlertRule::class);
    }

    public function rules(): array
    {
        $rule = $this->route('rule');
        $presence = $rule ? 'sometimes' : 'required';
        $kind = $rule?->kind ?? $this->input('kind');

        return ['name' => [$presence, 'required', 'string', 'max:120'], 'sensor_id' => $rule ? ['sometimes', Rule::in([$rule->sensor_id])] : ['required', 'uuid'],
            'kind' => $rule ? ['sometimes', Rule::in([$rule->kind])] : ['required', Rule::in(['threshold', 'missing'])],
            'severity' => [$presence, 'required', Rule::in(['info', 'warning', 'critical'])],
            'minimum' => $kind === 'threshold' ? ['sometimes', 'nullable', new AlertBound] : ['prohibited'], 'maximum' => $kind === 'threshold' ? ['sometimes', 'nullable', new AlertBound] : ['prohibited'],
            'max_age_seconds' => $kind === 'threshold' ? [$presence, 'required', 'integer', 'min:120', 'max:86400'] : ['prohibited'],
            'delay_seconds' => $kind === 'missing' ? [$presence, 'required', 'integer', 'min:120', 'max:86400'] : ['prohibited'],
            'is_enabled' => ['sometimes', 'boolean'], 'version' => $rule ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['exclude']];
    }

    public function messages(): array
    {
        return ['name.*' => 'alerts.validation.nameInvalid', 'sensor_id.*' => 'alerts.validation.sensorInvalid', 'kind.*' => 'alerts.validation.kindInvalid', 'severity.*' => 'alerts.validation.severityInvalid', 'minimum.*' => 'alerts.validation.boundInvalid', 'maximum.*' => 'alerts.validation.boundInvalid', 'max_age_seconds.*' => 'alerts.validation.intervalInvalid', 'delay_seconds.*' => 'alerts.validation.intervalInvalid', 'is_enabled.*' => 'alerts.validation.stateInvalid', 'version.*' => 'alerts.validation.versionInvalid'];
    }
}
