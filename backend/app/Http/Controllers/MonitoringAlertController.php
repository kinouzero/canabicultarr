<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAlertsRequest;
use App\Http\Requests\SaveAlertRuleRequest;
use App\Http\Resources\AlertEpisodeResource;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Services\AlertEvaluator;
use App\Services\MonitoringAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MonitoringAlertController extends Controller
{
    public function index(ListAlertsRequest $request, MonitoringAlerts $alerts)
    {
        return AlertEpisodeResource::collection($alerts->episodes($request->validated()));
    }

    public function rules(ListAlertsRequest $request, MonitoringAlerts $alerts)
    {
        return AlertRuleResource::collection($alerts->rules($request->validated()));
    }

    public function store(SaveAlertRuleRequest $request, MonitoringAlerts $alerts)
    {
        return (new AlertRuleResource($alerts->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(AlertRule $rule)
    {
        Gate::authorize('view', $rule);

        return new AlertRuleResource($rule->load(MonitoringAlerts::relations()));
    }

    public function update(SaveAlertRuleRequest $request, AlertRule $rule, MonitoringAlerts $alerts)
    {
        return new AlertRuleResource($alerts->save($request->user(), $request->validated(), $rule));
    }

    public function acknowledge(Request $request, AlertEpisode $episode, MonitoringAlerts $alerts)
    {
        return new AlertEpisodeResource($alerts->acknowledge($request->user(), $episode));
    }

    public function evaluate(Request $request, AlertRule $rule, AlertEvaluator $evaluator)
    {
        Gate::authorize('update', $rule);

        return new AlertRuleResource($evaluator->evaluate($rule, $request->user()));
    }
}
