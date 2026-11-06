<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Dates;
use App\Helpers\Paginator;
use App\Models\Collection;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\Price;
use App\Services\AuthService;
use App\Services\CollectionService;
use App\Services\HubScope;

final class CollectionController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        [$hubScope, $pickerUser] = CollectionService::scopeFor($user);

        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        $fromR = $from !== '' ? Dates::dayRangeUtc($from) : null;
        $toR = $to !== '' ? Dates::dayRangeUtc($to) : null;
        $f = [
            'q'      => trim((string) ($request->query['q'] ?? '')),
            'status' => in_array($request->query['status'] ?? '', ['pending', 'approved', 'rejected', 'reversed'], true) ? $request->query['status'] : '',
            'hub_id' => (int) ($request->query['hub_id'] ?? 0) ?: '',
            'from'   => $fromR[0] ?? null,
            'to'     => $toR[1] ?? null,
        ];
        $pg = Paginator::make(Collection::count($f, $hubScope, $pickerUser), $request->query['page'] ?? 1);
        return $this->page($request, 'collections/index', $pickerUser !== null ? 'My collections' : 'Collections', [
            'rows' => Collection::paginate($f, $hubScope, $pickerUser, $pg['offset'], $pg['per']), 'pg' => $pg,
            'f' => $f + ['from_raw' => $fromR ? $from : '', 'to_raw' => $toR ? $to : ''],
            'hubs' => Hub::options($hubScope), 'isPicker' => $pickerUser !== null,
            'summary' => Collection::summary($hubScope, $pickerUser),
        ]);
    }

    public function create(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $hubs = Hub::options($scope);
        $cats = PlasticCategory::options();
        $prices = [];
        foreach ($hubs as $hid => $_) {
            foreach ($cats as $cid => $__) {
                $p = Price::current((int) $cid, (int) $hid);
                $prices[$hid][$cid] = $p ? (int) $p['price_per_kg_ugx'] : null;
            }
        }
        return $this->page($request, 'collections/new', 'Record a collection', [
            'hubs' => $hubs, 'cats' => $cats, 'prices' => $prices, 'token' => bin2hex(random_bytes(16)),
        ]);
    }

    public function store(Request $request): string
    {
        $r = CollectionService::record($request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This collection was already recorded, so no second record was made.');
            return redirect('/collections/' . $r['id']);
        }
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                Session::flash('danger', $r['errors']['_']);
                return redirect('/collections/new');
            }
            return $this->invalid($r['errors'], $request->body + [], '/collections/new');
        }
        Session::flash('success', 'Collection recorded and weighed. It now waits for approval.');
        return redirect('/collections/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $c = CollectionService::findAccessible((int) $id, $user);
        if (!$c) {
            return $this->notFound($request);
        }
        $ownRecord = (int) $c['recorded_by'] === (int) $user['id'];
        return $this->page($request, 'collections/show', $c['reference'] ?? 'Collection', [
            'c' => $c, 'weighings' => Collection::weighings((int) $id), 'ownRecord' => $ownRecord,
        ]);
    }

    public function reweigh(Request $request, string $id): string
    {
        $r = CollectionService::reweigh((int) $id, $request->body, AuthService::user());
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                Session::flash('danger', $r['errors']['_']);
                return redirect('/collections/' . (int) $id);
            }
            return $this->invalid($r['errors'], $request->body, '/collections/' . (int) $id);
        }
        Session::flash('success', 'New weighing saved. The earlier weighing stays in the history.');
        return redirect('/collections/' . (int) $id);
    }

    public function approve(Request $request, string $id): string
    {
        return $this->decide(CollectionService::approve((int) $id, AuthService::user()), (int) $id);
    }

    public function reject(Request $request, string $id): string
    {
        return $this->decide(CollectionService::reject((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    public function reverse(Request $request, string $id): string
    {
        return $this->decide(CollectionService::reverse((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    private function decide(array $r, int $id): string
    {
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/collections/' . $id);
    }
}
