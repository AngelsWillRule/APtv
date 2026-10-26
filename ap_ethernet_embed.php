<?php
/**
 * Lightweight AP Ethernet wizard endpoint for the dashboard modal.
 */

require_once 'includes/bootstrap.php';
require_once 'includes/config.php';
require_once 'includes/defaults.php';
require_once 'includes/autoload.php';
require_once 'includes/session.php';
require_once 'includes/CSRF.php';
require_once 'includes/locale.php';
require_once 'includes/functions.php';
require_once 'includes/authenticate.php';
require_once 'includes/repeater.php';

DisplayApWizard(['embedded' => true]);
