<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMeasurementsRequest;
use App\Http\Resources\MeasurementResource;
use App\Http\Resources\SensorResource;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Services\CollectionPagination;
use App\Services\LatestMeasurements;
use App\Services\MeasurementHealth;
use App\Services\SensorAdministration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class MeasurementController extends Controller
{
    public function index(ListMeasurementsRequest $request, Sensor $sensor)
    {
        $data = $request->validated();
        $to = empty($data['date_to']) ? CarbonImmutable::now() : CarbonImmutable::parse($data['date_to'])->utc();
        $from = empty($data['date_from']) ? $to->subDay() : CarbonImmutable::parse($data['date_from'])->utc();
        if ($from->greaterThan($to) || $from->diffInSeconds($to) > 31 * 86400) {
            throw ValidationException::withMessages(['date_from' => ['measurements.validation.rangeInvalid']]);
        }
        $sensor->load(SensorAdministration::relations());
        $now = CarbonImmutable::now('UTC');
        $latest = app(LatestMeasurements::class)->forSensors(collect([$sensor->id]), $now)->get($sensor->id);
        $status = MeasurementHealth::status($sensor, $latest, $now);
        $records = Measurement::where('sensor_id', $sensor->id)->whereBetween('observed_at', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])->orderByDesc('observed_at')->paginate(CollectionPagination::size($data, 50));

        return MeasurementResource::collection($records)->additional(['summary' => ['sensor' => new SensorResource($sensor), 'latest' => $latest ? new MeasurementResource($latest) : null, 'status' => $status]]);
    }
}
