<?php

namespace App\Http\Controllers;

use App\Services\OwnScheduleService;
use Illuminate\Http\Request;

class OwnScheduleController extends Controller
{
    public function __invoke(Request $request, OwnScheduleService $service)
    {
        return view('account.schedule', ['schedule' => $service->forStaff($request->attributes->get('staff'), $request->attributes->get('staff_domain'))]);
    }
}
