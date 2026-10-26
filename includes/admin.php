<?php

function DisplayAuthConfig($username)
{
    $status = new \OpenAP\Messages\StatusMessage;
    $auth = new \OpenAP\Auth\HTTPAuth;
    $config = $auth->getAuthConfig();
    $username = $config['admin_user'];
    $password = $config['admin_pass'];
    $modalRequest = isset($_POST['openap_admin_modal']) && $_POST['openap_admin_modal'] === '1';
    $success = false;
    $message = '';

    if (isset($_POST['UpdateAdminPassword'])) {
        $oldPassword = (string) ($_POST['oldpass'] ?? '');
        $newPassword = (string) ($_POST['newpass'] ?? '');
        $repeatedPassword = (string) ($_POST['newpassagain'] ?? '');
        if (password_verify($oldPassword, $password)) {
            $new_username = trim((string) ($_POST['username'] ?? ''));
            if ($newPassword !== $repeatedPassword) {
                $message = 'New passwords do not match';
                $status->addMessage('New passwords do not match', 'danger');
            } elseif ($new_username == '') {
                $message = 'Username must not be empty';
                $status->addMessage('Username must not be empty', 'danger');
            } else {
                if (!file_exists(OPENAP_ADMIN_DETAILS)) {
                    $tmpauth = fopen(OPENAP_ADMIN_DETAILS, 'w');
                    fclose($tmpauth);
                }

                if ($auth_file = fopen(OPENAP_ADMIN_DETAILS, 'w')) {
                    fwrite($auth_file, $new_username.PHP_EOL);
                    fwrite($auth_file, password_hash($newPassword, PASSWORD_BCRYPT).PHP_EOL);
                    fclose($auth_file);
                    $username = $new_username;
                    $_SESSION['user_id'] = $username;
                    $message = 'Admin password updated';
                    $status->addMessage('Admin password updated');
                    $success = true;
                } else {
                    $message = 'Failed to update admin password';
                    $status->addMessage('Failed to update admin password', 'danger');
                }
            }
        } else {
            $message = 'Old password does not match';
            $status->addMessage('Old password does not match', 'danger');
        }

        if ($modalRequest) {
            ob_start();
            $status->showMessages();
            $messageHtml = ob_get_clean();
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => $success,
                'username' => $username,
                'message' => $message,
                'messageHtml' => $messageHtml
            ]);
            exit;
        }
    } elseif (isset($_POST['logout'])) {
        $auth->logout();
    }

    echo renderTemplate(
        "admin", compact(
            "status",
            "username"
        )
    );
}
