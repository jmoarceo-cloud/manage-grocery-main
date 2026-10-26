<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PublicProductCodeController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->query('b');
        $validator = Validator::make(['b' => $input], ['b' => ['nullable', 'string', 'max:64', 'regex:/\A[0-9A-Za-z._-]{3,64}\z/']]);
        if ($validator->fails()) {
            return $this->page(is_string($input) ? mb_substr($input, 0, 64) : '', 'Enter 3 to 64 letters, numbers, dots, underscores or hyphens.', 422);
        }
        $code = $validator->validated()['b'] ?? '';
        if ($code === '') {
            return $this->page();
        }

        // Preserve the unique indexed active lookup and exact-match precedence.
        $query = DB::connection('pos')->table('products')->where('is_active', 1);
        $id = (clone $query)->where('barcode', $code)->value('id');
        if (! $id && preg_match('/\A0([0-9]{13})\z/', $code, $match)) {
            $id = (clone $query)->where('barcode', $match[1])->value('id');
        }
        if ($id) {
            return redirect()->route('product', ['id' => (int) $id])->withHeaders(['Cache-Control' => 'no-store']);
        }

        return $this->page($code, 'No active product matches that code. Check the package or search the catalog by name.');
    }

    private function page(string $code = '', ?string $notice = null, int $status = 200)
    {
        return response()->view('public.code-lookup', compact('code', 'notice'), $status, ['Cache-Control' => 'no-store']);
    }
}
