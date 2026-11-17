<?php

namespace App\Http\Requests;

use App\Services\CollectionPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('notifications.settings.update') ? 'notifications.manage' : 'notifications.view');
    }

    public function rules(): array
    {
        if ($this->routeIs('notifications.settings.update')) {
            return [
                'version' => ['required', 'integer', 'min:0'], 'email_enabled' => ['required', 'boolean'], 'push_enabled' => ['required', 'boolean'], 'webhook_enabled' => ['required', 'boolean'], 'include_recovery' => ['required', 'boolean'], 'minimum_severity' => ['required', Rule::in(['info', 'warning', 'critical'])], 'locale' => ['required', Rule::in(['en', 'fr'])],
            ];
        }
        if ($this->routeIs('notifications.preferences.update')) {
            return ['version' => ['required', 'integer', 'min:0'], 'email_enabled' => ['required', 'boolean'], 'push_enabled' => ['required', 'boolean'], 'locale' => ['required', Rule::in(['en', 'fr'])]];
        }
        if ($this->routeIs('notifications.subscriptions.store')) {
            return ['endpoint' => ['required', 'string', 'max:2048'], 'keys' => ['required', 'array:p256dh,auth'], 'keys.p256dh' => ['required', 'string', 'max:100'], 'keys.auth' => ['required', 'string', 'max:100']];
        }

        if ($this->routeIs('notifications.subscriptions.remove-browser')) {
            return ['endpoint' => ['required', 'string', 'max:2048']];
        }

        if ($this->routeIs('notifications.inbox.read')) {
            return ['read_through' => ['required', 'date', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/D']];
        }

        if ($this->routeIs('notifications.deliveries')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => CollectionPagination::rules()];
        }

        return ['page' => ['sometimes', 'integer', 'min:1'], 'summary' => ['sometimes', 'boolean']];
    }

    public function messages(): array
    {
        return ['*' => 'notifications.errors.inputInvalid'];
    }
}
