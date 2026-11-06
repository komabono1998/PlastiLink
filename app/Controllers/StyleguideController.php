<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;

/** Living reference of the shared design system (non-production only). */
final class StyleguideController extends Controller
{
    public function index(Request $request): string
    {
        return $this->view('home/styleguide', [
            'title' => 'Design system',
            'nav'   => [
                ['href' => '#colours',    'icon' => 'palette',        'label' => 'Colours'],
                ['href' => '#buttons',    'icon' => 'hand-index',     'label' => 'Buttons'],
                ['href' => '#cards',      'icon' => 'bar-chart',      'label' => 'Stat cards'],
                ['href' => '#forms',      'icon' => 'input-cursor-text', 'label' => 'Forms'],
                ['href' => '#states',     'icon' => 'exclamation-circle', 'label' => 'States'],
                ['href' => '#tables',     'icon' => 'table',          'label' => 'Tables'],
            ],
        ], 'layouts/dashboard');
    }
}
