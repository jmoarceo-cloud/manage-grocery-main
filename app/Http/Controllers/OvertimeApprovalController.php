<?php

namespace App\Http\Controllers;

use App\Http\Requests\OvertimeDecisionRequest;
use App\Services\OvertimeApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OvertimeApprovalController extends Controller
{
    public function index(Request $request, OvertimeApprovalService $service)
    {
        $actor = $request->attributes->get('staff');
        $domain = $request->attributes->get('staff_domain');
        $service->authorize($actor, $domain);
        $validator = Validator::make($request->query(), ['status' => ['nullable', 'in:pending,approved,rejected'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        if ($validator->fails() && ! $request->expectsJson()) {
            $request->session()->forget('_old_input');

            return redirect()->route('overtime-approvals.index')->withErrors($validator);
        }
        $status = $validator->validate()['status'] ?? 'pending';
        $status = $status ?: 'pending';

        return view('workforce.overtime-approvals', ['requests' => $service->listing($actor, $domain, $status), 'status' => $status]);
    }

    public function decide(OvertimeDecisionRequest $request, int $id, OvertimeApprovalService $service)
    {
        try {
            $service->decide($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $id, $request->validated());
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                $request->session()->forget('_old_input');

                return redirect()->route('overtime-approvals.index')->withErrors($exception->errors());
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['message' => 'Overtime decision recorded.']) : redirect()->route('overtime-approvals.index')->with('status', 'Overtime decision recorded.');
    }
}
