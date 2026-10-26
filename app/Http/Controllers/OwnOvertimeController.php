<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnOvertimeRequest;
use App\Services\OwnOvertimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnOvertimeController extends Controller
{
    public function index(Request $request, OwnOvertimeService $service)
    {
        $validator = Validator::make($request->query(), ['year' => ['nullable', 'integer', 'min:1000', 'max:9999'], 'status' => ['nullable', 'in:pending,approved,rejected'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        if ($validator->fails() && ! $request->expectsJson()) {
            $request->session()->forget('_old_input');

            return redirect()->route('overtime.index')->withErrors($validator);
        }
        $filters = $validator->validate();
        $year = (int) ($filters['year'] ?? now('Asia/Manila')->year);
        $status = $filters['status'] ?? null;

        return view('account.overtime', ['overview' => $service->overview($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $year, $status), 'year' => $year, 'status' => $status]);
    }

    public function store(OwnOvertimeRequest $request, OwnOvertimeService $service)
    {
        try {
            $result = $service->submit($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $request->validated());
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                $request->session()->forget('_old_input');

                return redirect()->route('overtime.index')->withErrors($exception->errors());
            }
            throw $exception;
        }
        $message = $result['replayed'] ? 'This pending overtime request was already submitted.' : 'Your overtime request was submitted for review.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'request_id' => $result['id'], 'status' => 'pending', 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201) : redirect()->route('overtime.index', ['year' => substr($request->validated('ot_date'), 0, 4)])->with('status', $message);
    }
}
