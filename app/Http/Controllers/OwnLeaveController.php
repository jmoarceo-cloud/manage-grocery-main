<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnLeaveRequest;
use App\Services\OwnLeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnLeaveController extends Controller
{
    public function index(Request $request, OwnLeaveService $service)
    {
        $validator = Validator::make($request->query(), ['year' => ['nullable', 'integer', 'min:1000', 'max:9999'], 'status' => ['nullable', 'in:pending,approved,rejected'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        if ($validator->fails() && ! $request->expectsJson()) {
            $request->session()->forget('_old_input');

            return redirect()->route('leave.index')->withErrors($validator);
        }
        $filters = $validator->validate();
        $year = (int) ($filters['year'] ?? now('Asia/Manila')->year);
        $status = $filters['status'] ?? null;

        return view('account.leave', ['overview' => $service->overview($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $year, $status), 'year' => $year, 'status' => $status]);
    }

    public function store(OwnLeaveRequest $request, OwnLeaveService $service)
    {
        try {
            $result = $service->submit($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $request->validated());
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                $request->session()->forget('_old_input');

                return redirect()->route('leave.index')->withErrors($exception->errors());
            }
            throw $exception;
        }
        $message = $result['replayed'] ? 'This pending leave request was already submitted.' : 'Your leave request was submitted for approval.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'request_id' => $result['id'], 'status' => 'pending', 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201) : redirect()->route('leave.index', ['year' => substr($request->validated('date_from'), 0, 4)])->with('status', $message);
    }
}
