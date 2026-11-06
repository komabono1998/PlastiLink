<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Dates;
use App\Services\AuthService;
use App\Services\MonitoringService;
use App\Services\ReportService;
use App\Services\Reports\GeographicReport;

final class MonitoringController extends Controller
{
    private function nationalView(): bool
    {
        return in_array(AuthService::user()['role_slug'], ['super_admin', 'project_manager'], true);
    }

    public function map(Request $request): string
    {
        $user = AuthService::user();
        $f = ReportService::filters($request->query, GeographicReport::class, $user);
        $data = MonitoringService::mapData($f);
        $keep = array_intersect_key($request->query, array_flip(['from', 'to', 'hub_id']));
        return $this->page($request, 'monitoring/map', 'Map and hotspots', [
            'f' => $f, 'geo' => $data, 'points' => MonitoringService::mapPoints($data),
            'meta' => ReportService::meta($f, GeographicReport::class), 'qs' => http_build_query($keep), 'canExport' => can('reports.export'),
        ]);
    }

    public function programme(Request $request): string
    {
        if (!$this->nationalView()) {
            return $this->notFound($request);
        }
        $asked = is_string($request->query['months'] ?? null) ? (int) $request->query['months'] : 12;
        $months = in_array($asked, [6, 12, 24], true) ? $asked : 12;
        $today = Dates::todayYmd();
        $from = (new \DateTimeImmutable($today))->modify('first day of this month')->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
        $trend = MonitoringService::monthly($from, $today);
        return $this->page($request, 'monitoring/programme', 'Programme progress', [
            'targets' => MonitoringService::targetProgress(), 'values' => MonitoringService::targetValues(), 'labels' => MonitoringService::TARGETS,
            'trend' => $trend, 'months' => $months, 'canEdit' => can('targets.manage'), 'canExport' => can('reports.export'),
        ]);
    }

    public function targets(Request $request): string
    {
        $user = AuthService::user();
        $errors = [];
        $changed = 0;
        foreach (array_keys(MonitoringService::TARGETS) as $key) {
            $field = str_replace('.', '_', $key);
            if (!array_key_exists($field, $request->body)) {
                continue;
            }
            $r = MonitoringService::setTarget($key, (string) $request->body[$field], $user);
            if ($r['error'] !== null) {
                $errors[] = $r['error'];
            } elseif (!empty($r['changed'])) {
                $changed++;
            }
        }
        if ($errors) {
            Session::flash('danger', implode(' ', $errors));
        } else {
            Session::flash('success', $changed > 0 ? "Saved. $changed target" . ($changed === 1 ? '' : 's') . ' changed.' : 'No changes.');
        }
        return redirect('/monitoring/programme');
    }
}
