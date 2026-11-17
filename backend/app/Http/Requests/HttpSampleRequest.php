<?php

namespace App\Http\Requests;

use App\Enums\MeasurementQuality;
use App\Rules\ZonedTimestamp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HttpSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quality' => ['sometimes', 'required', Rule::enum(MeasurementQuality::class)], 'value' => ['required', 'string', 'max:80'], 'unit' => ['present', 'nullable', 'string', 'max:24'], 'observed_at' => ['required', new ZonedTimestamp('httpIngestion.validation.timeInvalid')]];
    }

    public function messages(): array
    {
        return ['quality.*' => 'measurements.errors.invalidQuality', 'value.*' => 'httpIngestion.validation.valueInvalid', 'unit.*' => 'httpIngestion.validation.unitInvalid', 'observed_at.*' => 'httpIngestion.validation.timeInvalid'];
    }
}
