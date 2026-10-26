<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
function DisplayLogin()
{
    $auth = new \OpenAP\Auth\HTTPAuth;
    $status = null;
    $loginFailed = false;
    $sessionExpired = ($_GET['reason'] ?? '') === 'session_expired';
    $redirectUrl = openapNormalizeLoginRedirect($_GET['action'] ?? '/');
    if (OPENAP_AUTH_ENABLED) {
        if (isset($_POST['login-auth'])) {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $redirectUrl = openapNormalizeLoginRedirect($_POST['redirect-url'] ?? '/');
            if ($username === '') {
                $status = "Username is required.";
            } elseif ($password === '') {
                $status = "Password is required.";
            } elseif ($auth->login($username, $password)) {
                $config = $auth->getAuthConfig();
                header('Location: ' . $redirectUrl);
                die();
            } else {
                $status = "Username or password is incorrect.";
                $loginFailed = true;
            }
        }
    }
    echo renderTemplate("login", compact("status", "sessionExpired", "redirectUrl", "loginFailed"));
}

function openapNormalizeLoginRedirect(?string $redirectUrl): string
{
    $redirectUrl = $redirectUrl ?: '/';
    $path = parse_url($redirectUrl, PHP_URL_PATH) ?: '/';

    if ($path === '/auth_conf' || $path === 'auth_conf') {
        return '/';
    }

    if (strpos($redirectUrl, '/') !== 0 || strpos($redirectUrl, '//') === 0) {
        return '/';
    }

    return $redirectUrl;
}
