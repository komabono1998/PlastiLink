<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Setting;

final class HomeController extends Controller
{
    public function index(Request $request): string
    {
        // Programme targets come from system_settings (not hard-coded in the view).
        $targets = null;
        try {
            $s = Setting::all();
            $targets = [
                'pickers'    => $s['target.pickers'] ?? null,
                'hubs'       => $s['target.hubs'] ?? null,
                'recyclers'  => $s['target.recyclers'] ?? null,
                'kg'         => $s['target.kg_12_months'] ?? null,
                'pilot'      => $s['target.pilot_pickers'] ?? null,
            ];
        } catch (\Throwable) {
            $targets = null; // database unavailable: page still renders
        }

        return $this->view('home/index', [
            'title'   => 'Home',
            'targets' => $targets,
        ]);
    }
}
