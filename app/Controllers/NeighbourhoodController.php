<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Neighbourhood;
use App\Services\AuthService;

final class NeighbourhoodController extends Controller
{
    public function index(Request $request): string
    {
        return $this->page($request, 'admin/neighbourhoods', 'Neighbourhoods', ['items' => Neighbourhood::all()]);
    }

    public function store(Request $request): string
    {
        $name = trim((string) $request->input('name', ''));
        $errors = Validator::validate(['name' => $name], ['name' => ['Neighbourhood name', 'required|max:100']]);
        if (!$errors && Neighbourhood::nameTaken($name)) {
            $errors['name'] = 'This neighbourhood already exists.';
        }
        if ($errors) {
            return $this->invalid($errors, ['name' => $name], '/neighbourhoods');
        }
        $id = Neighbourhood::create($name);
        AuditLog::write('neighbourhood.created', (int) AuthService::user()['id'], 'neighbourhood', $id);
        Session::flash('success', 'Neighbourhood added.');
        return redirect('/neighbourhoods');
    }

    public function update(Request $request, string $id): string
    {
        $n = Neighbourhood::find((int) $id);
        if (!$n) {
            return $this->notFound($request);
        }
        $actor = (int) AuthService::user()['id'];
        if ($request->input('action') === 'toggle') {
            $active = !(bool) $n['is_active'];
            Neighbourhood::setActive($n['id'], $active);
            AuditLog::write($active ? 'neighbourhood.activated' : 'neighbourhood.deactivated', $actor, 'neighbourhood', (int) $n['id']);
            Session::flash('success', $active ? 'Neighbourhood activated.' : 'Neighbourhood deactivated. Existing records keep it.');
            return redirect('/neighbourhoods');
        }
        if ($request->input('action') === 'location') {
            [$lat, $lng, $err] = \App\Helpers\Geo::parse((string) $request->input('latitude', ''), (string) $request->input('longitude', ''));
            if ($err !== null) {
                Session::flash('danger', $n['name'] . ': ' . $err);
                return redirect('/neighbourhoods');
            }
            Neighbourhood::setLocation((int) $id, $lat, $lng);
            AuditLog::write('neighbourhood.location_set', $actor, 'neighbourhood', (int) $id, ['latitude' => $lat, 'longitude' => $lng]);
            Session::flash('success', $lat === null ? $n['name'] . ': location cleared.' : $n['name'] . ': location saved.');
            return redirect('/neighbourhoods');
        }
        $name = trim((string) $request->input('name', ''));
        $errors = Validator::validate(['name' => $name], ['name' => ['Neighbourhood name', 'required|max:100']]);
        if (!$errors && Neighbourhood::nameTaken($name, (int) $id)) {
            $errors['name'] = 'This neighbourhood already exists.';
        }
        if ($errors) {
            Session::flash('danger', $errors['name']);
            return redirect('/neighbourhoods');
        }
        Neighbourhood::rename((int) $id, $name);
        AuditLog::write('neighbourhood.renamed', $actor, 'neighbourhood', (int) $id);
        Session::flash('success', 'Neighbourhood renamed.');
        return redirect('/neighbourhoods');
    }
}
