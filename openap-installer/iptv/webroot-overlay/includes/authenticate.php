<?php

if (OPENAP_AUTH_ENABLED) {
    $auth = new \OpenAP\Auth\HTTPAuth;

    if (!$auth->isLogged()) {
        $auth->authenticate();
    }
}
