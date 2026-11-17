<?php

namespace App\Http\Controllers;

use App\Services\ApplicationInfo;

class ApplicationInfoController extends Controller
{
    public function __invoke(ApplicationInfo $info): array
    {
        return ['data' => $info->get()];
    }
}
