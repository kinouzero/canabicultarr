<?php

namespace App\Http\Requests;

use App\Services\RemoteSyncSchedule;
use App\Services\WebhookDestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConnectionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('instance-settings.manage');
    }

    public function rules(): array
    {
        $fields = config('connection_settings.'.$this->route('group'), []);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'reset' => ['sometimes', 'boolean'],
            'values' => ['sometimes', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $name => $field) {
            $rules['values.'.$name] = $field['locked'] ? ['prohibited'] : ['sometimes', 'nullable', ...match ($field['type']) {
                'boolean' => ['boolean'],
                'interval' => ['integer', 'min:'.RemoteSyncSchedule::MIN_INTERVAL, 'max:'.RemoteSyncSchedule::MAX_INTERVAL, 'multiple_of:'.RemoteSyncSchedule::MIN_INTERVAL],
                'port' => ['integer', 'min:1', 'max:65535'],
                'select' => [Rule::in($field['options'])],
                'url' => ['string', 'max:2048', 'url:http,https', function ($attribute, $value, $fail) {
                    $parts = parse_url($value);
                    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                        $fail('connectionSettings.invalid');
                    }
                }],
                'email' => ['string', 'email:rfc', 'max:254'],
                'secret' => ['string', 'max:8192', 'not_regex:/[\x00\r\n]/'],
                default => ['string', 'max:255', 'not_regex:/[\p{C}]/u'],
            }];
        }

        if (isset($fields['ALERT_WEBHOOK_ALLOWED_INTERNAL']) && ! $fields['ALERT_WEBHOOK_ALLOWED_INTERNAL']['locked']) {
            $rules['values.ALERT_WEBHOOK_ALLOWED_INTERNAL'] = ['sometimes', 'nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! app(WebhookDestination::class)->validInternalList($value)) {
                    $fail('connectionSettings.invalid');
                }
            }];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['values.*' => 'connectionSettings.invalid', 'values' => 'connectionSettings.invalid',
            'version.*' => 'connectionSettings.invalid', 'reset.*' => 'connectionSettings.invalid'];
    }
}
