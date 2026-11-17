<?php

namespace App\Http\Requests;

use App\Services\InstanceSettings;
use App\Support\ThemePalette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInstanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('instance-settings.manage');
    }

    public function rules(): array
    {
        $paletteKeys = implode(',', ThemePalette::KEYS);

        return [
            'oidc_provider_name' => [Rule::prohibitedIf(InstanceSettings::providerNameLocked()), 'sometimes', 'required', 'string', 'max:80', 'not_regex:/[\p{C}]/u'],
            'oidc_provider_icon' => [Rule::prohibitedIf(InstanceSettings::providerIconLocked()), 'sometimes', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,99}$/D'],
            'site_title' => ['sometimes', 'required', 'string', 'max:80', 'not_regex:/[\p{C}]/u'],
            'site_subtitle' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[\p{C}]/u'],
            'date_format' => ['required', 'string', Rule::in(['d/m/Y', 'd.m.Y', 'd-m-Y', 'm/d/Y', 'Y-m-d', 'localized-short', 'localized', 'localized-long', 'localized-full'])],
            'time_format' => ['required', 'string', Rule::in(['24', '12'])],
            'genetics_colors' => ['sometimes', 'array:sativa,indica,hybrid', 'size:3'],
            'genetics_colors.sativa' => ['required_with:genetics_colors', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'genetics_colors.indica' => ['required_with:genetics_colors', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'genetics_colors.hybrid' => ['required_with:genetics_colors', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['sometimes', 'array:preset,light,dark', 'size:3'],
            'theme.preset' => ['required_with:theme', Rule::in(['botanical', 'ocean', 'terracotta', 'slate', 'custom'])],
            'theme.light' => ['required_with:theme', 'array:'.$paletteKeys, 'size:'.count(ThemePalette::KEYS)],
            'theme.dark' => ['required_with:theme', 'array:'.$paletteKeys, 'size:'.count(ThemePalette::KEYS)],
            'theme.light.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.dark.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['*' => 'instanceSettings.invalid'];
    }
}
