<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffContext
{
    public static function posId(Request $request): int
    {
        $user = $request->attributes->get('staff');
        abort_unless($user, 401);
        if ($request->attributes->get('staff_domain') === 'pos') {
            return (int) $user->id;
        }
        abort_unless($user->role === 'admin', 403);
        $query = DB::connection('pos')->table('users')->where('role', 'admin')->where('is_active', 1);
        if ($user->employee_id) {
            $query->where('employee_id', $user->employee_id);
        } else {
            $query->whereRaw('BINARY username = ?', [$user->username]);
        }
        $id = $query->value('id');
        abort_unless($id, 403, 'No linked POS administrator is available.');

        return (int) $id;
    }
}
