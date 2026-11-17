<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MeasurementRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('instance-settings.manage');
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'preview' => ['days' => ['required', 'integer', 'min:1', 'max:36500'], 'mode' => ['required', Rule::in(['manual', 'policy'])], 'version' => ['required', 'integer', 'min:1']],
            'confirm' => ['token' => ['required', 'string', 'size:64', 'alpha_num:ascii'], 'confirmed' => ['required', 'accepted']],
            'disable' => ['version' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }
}
