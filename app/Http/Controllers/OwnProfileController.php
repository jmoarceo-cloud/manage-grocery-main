<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnProfileRequest;
use App\Services\OwnProfileService;
use App\Support\ProfilePhone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OwnProfileController extends Controller
{
    public function edit(Request $request, OwnProfileService $service)
    {
        $staff = $request->attributes->get('staff');
        $domain = $request->attributes->get('staff_domain');
        $employee = $service->read($staff, $domain);
        [$country, $number] = ProfilePhone::parse($employee->contact_number ?? null);

        return view('account.profile', ['employee' => $employee, 'revision' => $employee ? OwnProfileService::revision($staff, $domain, $employee) : null, 'phoneCountry' => $country, 'phoneNumber' => $number]);
    }

    public function update(OwnProfileRequest $request, OwnProfileService $service)
    {
        try {
            $service->change($request->attributes->get('staff'), $request->attributes->get('staff_domain'), $request->validated());
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                $request->session()->forget('_old_input');

                return redirect()->route('profile.edit')->withErrors($exception->errors());
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['message' => 'Your personal information has been saved.']) : redirect()->route('profile.edit')->with('status', 'Your personal information has been saved.');
    }
}
