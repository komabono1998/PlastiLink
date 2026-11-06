<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\PasswordResetService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): string
    {
        $html = $this->view('auth/login', ['title' => 'Sign in']);
        Session::clearOld();
        return $html;
    }

    public function login(Request $request): string
    {
        $identifier = (string) $request->input('identifier', '');
        $password = (string) $request->input('password', '');

        if (trim($identifier) === '' || $password === '') {
            Session::flashInput(['identifier' => $identifier]);
            Session::flash('danger', 'Enter your phone number or email, and your password.');
            return redirect('/login');
        }

        $result = AuthService::attempt($identifier, $password);
        if (!$result['ok']) {
            Session::flashInput(['identifier' => $identifier]);
            Session::flash('danger', (string) $result['error']);
            return redirect('/login');
        }

        $user = AuthService::user();
        $intended = safe_path($_SESSION['intended'] ?? null, '');
        unset($_SESSION['intended']);
        Session::clearOld();
        return redirect($intended !== '' && $intended !== '/login' ? $intended : AuthService::homeFor($user['role_slug']));
    }

    public function logout(Request $request): string
    {
        AuthService::logout();
        Session::flash('success', 'You have been signed out.');
        return redirect('/login');
    }

    public function showForgot(Request $request): string
    {
        return $this->view('auth/forgot', ['title' => 'Forgot password']);
    }

    public function forgot(Request $request): string
    {
        (new PasswordResetService())->request((string) $request->input('identifier', ''));
        // Same answer whether or not the account exists.
        Session::flash('success', 'If that account exists, we have sent a reset link. It is valid for 60 minutes.');
        return redirect('/login');
    }

    public function showReset(Request $request): string
    {
        $token = (string) ($request->query['token'] ?? '');
        $valid = (new PasswordResetService())->isTokenValid($token);
        if (!$valid) {
            Session::flash('danger', 'This reset link is invalid or has expired. Please request a new one.');
            return redirect('/forgot-password');
        }
        return $this->view('auth/reset', ['title' => 'Set a new password', 'token' => $token]);
    }

    public function reset(Request $request): string
    {
        $token = (string) $request->input('token', '');
        $errors = (new PasswordResetService())->reset(
            $token,
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', '')
        );
        if ($errors) {
            foreach ($errors as $e) {
                Session::flash('danger', $e);
            }
            $stillValid = (new PasswordResetService())->isTokenValid($token);
            return redirect($stillValid ? '/reset-password?token=' . rawurlencode($token) : '/forgot-password');
        }
        Session::flash('success', 'Your password has been changed. Please sign in.');
        return redirect('/login');
    }
}
