<?php

namespace App\Http\Requests;

use App\Models\AlertEpisode;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\MonitoringAlerts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AlertEpisode::class);
    }

    public function rules(): array
    {
        return ['sort' => CollectionSorting::rules($this->is('api/v1/alert-rules') ? ['name' => 'name', 'created' => 'created_at'] : MonitoringAlerts::SORT_COLUMNS), 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'min:1'], 'state' => ['nullable', Rule::in(['active', 'resolved', 'all'])], 'acknowledged' => ['nullable', 'boolean'], 'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])], 'sensor_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:120']];
    }

    public function messages(): array
    {
        return ['sort.*' => 'collection.invalidSort', '*' => 'alerts.validation.filterInvalid'];
    }
}
