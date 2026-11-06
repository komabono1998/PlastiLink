<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Picker;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\PickerService;

/** Staff-facing picker screens (the picker's own dashboard is DashboardController::picker). */
final class PickerController extends Controller
{
    public function index(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $f = [
            'q'                => trim((string) ($request->query['q'] ?? '')),
            'status'           => in_array($request->query['status'] ?? '', ['pending', 'active', 'suspended'], true) ? $request->query['status'] : '',
            'neighbourhood_id' => (int) ($request->query['neighbourhood_id'] ?? 0) ?: '',
            'hub_id'           => (int) ($request->query['hub_id'] ?? 0) ?: '',
        ];
        $pg = Paginator::make(Picker::count($f, $scope), $request->query['page'] ?? 1);
        return $this->page($request, 'pickers/index', 'Waste pickers', [
            'pickers' => Picker::paginate($f, $scope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f,
            'neighbourhoods' => Neighbourhood::options(), 'hubs' => Hub::options($scope),
        ]);
    }

    /** JSON search used by the "record a collection" form: active pickers this user may see. No full phone numbers. */
    public function search(Request $request): array
    {
        $q = trim((string) ($request->query['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ['results' => []];
        }
        $scope = HubScope::forUser(AuthService::user());
        return ['results' => array_map(static fn(array $p) => [
            'id' => (int) $p['id'], 'code' => $p['picker_code'], 'name' => $p['full_name'],
            'neighbourhood' => $p['neighbourhood'], 'hub' => $p['hub_name'], 'phone' => \App\Helpers\Phone::mask($p['phone_e164']),
        ], Picker::search($q, $scope, 8))];
    }

    public function create(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        return $this->page($request, 'pickers/register', 'Register a waste picker', [
            'neighbourhoods' => Neighbourhood::options(), 'hubs' => Hub::options($scope),
            'consentText' => PickerService::CONSENT_TEXT, 'hubRequired' => $scope !== null,
        ]);
    }

    public function store(Request $request): string
    {
        $r = PickerService::registerByStaff($request->body, AuthService::user());
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/pickers/register');
        }
        Session::putOnce('temp_picker_' . $r['id'], $r['temp']);
        Session::flash('success', 'Waste picker registered and approved.');
        if (!empty($r['warning'])) {
            Session::flash('warning', 'Another picker with the same name already exists in this neighbourhood. Check this is not a duplicate.');
        }
        return redirect('/pickers/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $p = PickerService::findAccessible((int) $id, AuthService::user());
        if (!$p) {
            return $this->notFound($request);
        }
        return $this->page($request, 'pickers/show', $p['full_name'], [
            'p' => $p, 'temp' => Session::pullOnce('temp_picker_' . $p['id']),
            'canSeeNationalId' => AuthService::hasRole('super_admin', 'hub_manager'),
        ]);
    }

    public function decide(Request $request, string $id): string
    {
        $r = PickerService::decide((int) $id, (string) $request->input('decision', ''), $request->input('reason'), AuthService::user());
        if ($r['error']) {
            Session::flash('danger', $r['error']);
        } else {
            if (isset($r['temp'])) {
                Session::putOnce('temp_picker_' . $id, $r['temp']);
            }
            Session::flash('success', (string) $r['message']);
        }
        $back = safe_path((string) $request->input('back', ''), '/pickers/' . (int) $id);
        return redirect($back);
    }
}
