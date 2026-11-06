<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Recycler;
use App\Services\AuthService;
use App\Services\RecyclerService;

final class RecyclerController extends Controller
{
    public function index(Request $request): string
    {
        $f = [
            'q'      => trim((string) ($request->query['q'] ?? '')),
            'status' => in_array($request->query['status'] ?? '', ['pending', 'approved', 'rejected', 'suspended'], true) ? $request->query['status'] : '',
        ];
        $pg = Paginator::make(Recycler::count($f), $request->query['page'] ?? 1);
        return $this->page($request, 'recyclers/index', 'Recyclers', [
            'recyclers' => Recycler::paginate($f, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f,
        ]);
    }

    public function create(Request $request): string
    {
        return $this->page($request, 'recyclers/form', 'Register a recycler', []);
    }

    public function store(Request $request): string
    {
        $r = RecyclerService::registerByStaff($request->body, AuthService::user());
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/recyclers/create');
        }
        Session::putOnce('temp_recycler_' . $r['id'], $r['temp']);
        Session::flash('success', 'Recycler registered and approved.');
        return redirect('/recyclers/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $r = Recycler::find((int) $id);
        if (!$r) {
            return $this->notFound($request);
        }
        return $this->page($request, 'recyclers/show', $r['business_name'], [
            'r' => $r, 'temp' => Session::pullOnce('temp_recycler_' . $r['id']),
        ]);
    }

    public function decide(Request $request, string $id): string
    {
        $r = RecyclerService::decide((int) $id, (string) $request->input('decision', ''), $request->input('reason'), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect(safe_path((string) $request->input('back', ''), '/recyclers/' . (int) $id));
    }
}
