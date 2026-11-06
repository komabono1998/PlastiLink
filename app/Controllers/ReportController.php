<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Services\AuthService;
use App\Services\Exports\CsvExporter;
use App\Services\Exports\PdfExporter;
use App\Services\Exports\XlsxExporter;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function index(Request $request): string
    {
        return $this->page($request, 'reports/index', 'Reports', ['reports' => ReportService::allowedFor(AuthService::user())]);
    }

    public function show(Request $request, string $key): string
    {
        $user = AuthService::user();
        $cls = ReportService::find($key, $user);
        if (!$cls) {
            return $this->notFound($request);
        }
        $run = ReportService::run($cls, $request->query, $user);
        $total = ReportService::rowCount($run['result']);
        $result = ReportService::limitRows($run['result'], ReportService::HTML_MAX_ROWS, 'this screen (download the report for everything)');
        $keep = array_intersect_key($request->query, array_flip(['from', 'to', 'hub_id', 'neighbourhood_id', 'group', 'inactive_days']));
        return $this->page($request, 'reports/show', $cls::title() . ' report', [
            'cls' => $cls, 'key' => $key, 'f' => $run['filters'], 'result' => $result, 'meta' => $run['meta'], 'total' => $total,
            'qs' => http_build_query($keep), 'canExport' => can('reports.export'),
        ]);
    }

    public function export(Request $request, string $key, string $format): string
    {
        $user = AuthService::user();
        $cls = ReportService::find($key, $user);
        if (!$cls) {
            return $this->notFound($request);
        }
        $run = ReportService::run($cls, $request->query, $user);
        $rows = ReportService::rowCount($run['result']);
        $result = ReportService::limitRows($run['result'], ReportService::EXPORT_MAX_ROWS, 'this file');

        [$body, $mime] = match ($format) {
            'csv'  => [CsvExporter::build($result, $run['meta']), CsvExporter::MIME],
            'xlsx' => [XlsxExporter::build($result, $run['meta']), XlsxExporter::MIME],
            default => [PdfExporter::build($result, $run['meta']), PdfExporter::MIME],
        };

        // Exports can contain personal data: record who took what.
        AuditLog::write('report.exported', (int) $user['id'], 'report', null, [
            'report' => $key, 'format' => $format, 'rows' => $rows, 'from' => $run['filters']['from'], 'to' => $run['filters']['to'],
            'hub' => $run['filters']['hub_id'], 'neighbourhood' => $run['filters']['neighbourhood_id'],
        ]);

        $name = 'plastilink-' . $key . '-' . $run['filters']['from'] . '_to_' . $run['filters']['to'] . '.' . $format;
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        return $body;
    }
}
