<?php

namespace App\Http\Requests;

use App\Enums\MeasurementNature;
use App\Models\MeasurementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveMeasurementTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('measurementType') ? $this->user()->can('update', $this->route('measurementType')) : $this->user()->can('create', MeasurementType::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::lower(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $presence = $this->route('measurementType') ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'required', 'string', 'max:120'],
            'code' => [$presence, 'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'nature' => ['sometimes', 'required', Rule::enum(MeasurementNature::class)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:24'],
            'decimal_places' => ['sometimes', 'required', 'integer', 'between:0,6'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['nature.*' => 'measurementTypes.validation.natureInvalid', 'name.*' => 'measurementTypes.validation.nameInvalid', 'code.*' => 'measurementTypes.validation.codeInvalid',
            'unit.*' => 'measurementTypes.validation.unitInvalid', 'decimal_places.*' => 'measurementTypes.validation.decimalsInvalid',
            'description.*' => 'measurementTypes.validation.descriptionInvalid'];
    }
}
