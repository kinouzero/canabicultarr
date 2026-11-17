<?php

namespace App\Http\Controllers;

use App\Services\InstallationOverview;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, InstallationOverview $overview)
    {
        return ['data' => $overview->read($request->user())];
    }
}
