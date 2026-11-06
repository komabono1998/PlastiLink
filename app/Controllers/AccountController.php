<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AccountService;
use App\Services\AuthService;

final class AccountController extends Controller
{
    public function showPassword(Request $request): string
    {
        $user = AuthService::user();
        return $this->page($request, 'auth/password', 'Change password', [
            'forced' => (int) $user['must_change_password'] === 1,
        ]);
    }

    public function changePassword(Request $request): string
    {
        $user = AuthService::user();
        $errors = AccountService::changePassword(
            $user,
            (string) $request->input('current_password', ''),
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', '')
        );
        if ($errors) {
            return $this->invalid($errors, $request->body, '/account/password');
        }
        Session::flash('success', 'Your password has been changed.');
        return redirect(AuthService::homeFor($user['role_slug']));
    }
}
