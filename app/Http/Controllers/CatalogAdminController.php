<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogEntryRequest;
use App\Services\DomainAudit;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogAdminController extends Controller
{
    private function definition(string $entity): array
    {
        $definition = config('catalog.'.$entity);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    public function index(Request $request, string $entity)
    {
        $definition = $this->definition($entity);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'string', 'max:40'], 'direction' => ['nullable', 'in:asc,desc']]);
        $query = DB::connection('pos')->table($definition['table']);
        if ($data['q'] ?? null) {
            $query->where(function ($query) use ($definition, $data) {
                foreach ($definition['search'] as $column) {
                    $query->orWhere($column, 'like', '%'.$data['q'].'%');
                }
            });
        }$sort = in_array($data['sort'] ?? '', array_keys($definition['columns']), true) ? $data['sort'] : array_key_first($definition['columns']);

        return view('manage.index', ['entity' => $entity, 'definition' => $definition, 'rows' => $query->select(['id', ...array_keys($definition['columns'])])->orderBy($sort, $data['direction'] ?? 'asc')->orderBy('id')->paginate(20)->withQueryString()]);
    }

    public function form(string $entity, ?int $id = null)
    {
        $definition = $this->definition($entity);
        $record = $id ? DB::connection('pos')->table($definition['table'])->where('id', $id)->first() : null;
        if ($id) {
            abort_unless($record, 404);
        }

        return view('manage.form', ['entity' => $entity, 'definition' => $definition, 'record' => $record, 'categories' => DB::connection('pos')->table('categories')->orderBy('category_name')->get(), 'suppliers' => DB::connection('pos')->table('suppliers')->where('status', 'active')->orderBy('company_name')->get()]);
    }

    public function save(CatalogEntryRequest $request, string $entity, ?int $id = null)
    {
        $definition = $this->definition($entity);
        $data = $request->validated();
        if ($entity === 'products' && ($id === null || array_key_exists('category', $data))) {
            $data['category'] ??= '';
        }
        $actor = StaffContext::posId($request);
        $db = DB::connection('pos');
        $db->transaction(function () use ($db, $definition, $entity, $data, $id, $actor) {
            if ($entity === 'products' && ! empty($data['category'])) {
                abort_unless($db->table('categories')->where('category_name', $data['category'])->lockForUpdate()->first(), 422);
            }
            if ($id) {
                $previous = $db->table($definition['table'])->where('id', $id)->lockForUpdate()->first();
                abort_unless($previous, 404);
                $db->table($definition['table'])->where('id', $id)->update($data);
                if ($entity === 'categories' && $previous->category_name !== $data['category_name']) {
                    $db->table('products')->where('category', $previous->category_name)->update(['category' => $data['category_name']]);
                }
            } else {
                if ($entity === 'products') {
                    $data['stock'] = 0;
                }if ($entity === 'suppliers') {
                    $data['created_by'] = $actor;
                }$id = $db->table($definition['table'])->insertGetId($data);
            }
            DomainAudit::record($definition['table'], $id, 'saved', array_keys($data), $actor);
        });

        return redirect()->route('manage.index', $entity)->with('status', 'Saved successfully.');
    }

    public function deactivate(Request $request, string $entity, int $id)
    {
        $definition = $this->definition($entity);
        $actor = StaffContext::posId($request);
        $db = DB::connection('pos');
        $db->transaction(function () use ($db, $definition, $entity, $id, $actor) {
            $record = $db->table($definition['table'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($entity === 'categories') {
                if ($db->table('products')->where('category', $record->category_name)->exists()) {
                    throw ValidationException::withMessages(['category' => 'This category is still used by a product.']);
                }$db->table('categories')->where('id', $id)->delete();
            } else {
                $db->table($definition['table'])->where('id', $id)->update([$entity === 'products' ? 'is_active' : 'status' => $entity === 'products' ? 0 : 'inactive']);
            }DomainAudit::record($definition['table'], $id, 'deactivated', ['status'], $actor);
        });

        return back()->with('status', 'Updated successfully.');
    }
}
