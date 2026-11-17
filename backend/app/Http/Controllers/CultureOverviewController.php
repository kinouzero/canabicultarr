<?php

namespace App\Http\Controllers;

use App\Http\Requests\CultureOverviewExportRequest;
use App\Services\CultureOverview;
use App\Services\MeasurementReportExports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CultureOverviewController extends Controller
{
    public function exports(Request $request, MeasurementReportExports $exports)
    {
        Gate::authorize('dashboard.view');

        return response()->json(['data' => $exports->listing($request->user(), null)])->header('Cache-Control', 'private, no-store');
    }

    public function createExport(CultureOverviewExportRequest $request, MeasurementReportExports $exports)
    {
        return response()->json(['data' => $exports->payload($exports->create($request->user(), null, $request->validated()))], 202)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, CultureOverview $overview)
    {
        $filters = $request->validate(['site_id' => ['nullable', 'uuid'], 'days' => ['sometimes', 'integer', Rule::in([7, 30])]]);

        return ['data' => $overview->read($request->user(), $filters)];
    }
}
