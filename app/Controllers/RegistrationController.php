<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Services\PickerService;
use App\Services\RecyclerService;

/** Public self-registration. Everything created here starts as "pending" until staff approve it. */
final class RegistrationController extends Controller
{
    public function showPicker(Request $request): string
    {
        return $this->view('auth/register-picker', [
            'title' => 'Register as a waste picker', 'neighbourhoods' => Neighbourhood::options(),
            'hubs' => Hub::options(), 'consentText' => PickerService::CONSENT_TEXT,
        ]);
    }

    public function picker(Request $request): string
    {
        $r = PickerService::registerPublic($request->body);
        if ($r['errors']) {
            return $this->fail($r['errors'], $request, '/register');
        }
        Session::flash('success', 'Thank you! Your registration was received. A hub manager will review it, and you can sign in once it is approved.');
        return redirect('/login');
    }

    public function showRecycler(Request $request): string
    {
        return $this->view('auth/register-recycler', ['title' => 'Register your recycling company', 'consentText' => PickerService::CONSENT_TEXT]);
    }

    public function recycler(Request $request): string
    {
        $r = RecyclerService::registerPublic($request->body);
        if ($r['errors']) {
            return $this->fail($r['errors'], $request, '/register/recycler');
        }
        Session::flash('success', 'Thank you! Your company registration was received. The PlastiLink team will review it, and you can sign in once it is approved.');
        return redirect('/login');
    }

    private function fail(array $errors, Request $request, string $path): string
    {
        if (isset($errors['_'])) {
            Session::flash('danger', $errors['_']);
            return redirect($path);
        }
        return $this->invalid($errors, $request->body, $path);
    }
}
