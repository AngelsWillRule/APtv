<?php header("Content-Type: text/css;charset=utf-8"); ?>
<?php
    require_once '../../../includes/functions.php';
    $color = getColorOpt();
?>
/*
Theme Name: OpenAP default
Author: @billz
Author URI: https://github.com/billz
Description: OpenAP default theme, derived from the upstream RaspAP theme
License: GNU General Public License v3.0
*/

/* Light Mode */
:root {
  --openap-theme-color: <?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>;
  --openap-theme-lighter: <?php echo htmlspecialchars(lightenColor($color, 20), ENT_QUOTES, 'UTF-8'); ?>;
  --openap-theme-darker: <?php echo htmlspecialchars(darkenColor($color, 20), ENT_QUOTES, 'UTF-8'); ?>;
}

/* Typography */
a {
  --bs-link-color: var(--openap-theme-color);
  --bs-link-hover-color: var(--openap-theme-color);
}

/* Elements */

/* Icons */
i.fa.fa-bars {
  color: #d1d3e2;
}
i.fa.fa-bars:hover{
  color: #6e707e;
}

/* Buttons */
.btn-primary {
  --bs-btn-bg: var(--openap-theme-color);
  --bs-btn-border-color: var(--openap-theme-color);
  --bs-btn-hover-bg: var(--openap-theme-darker);
  --bs-btn-hover-border-color: var(--openap-theme-darker);
  --bs-btn-active-bg: var(--openap-theme-darker);
  --bs-btn-active-border-color: var(--openap-theme-darker);
  --bs-btn-disabled-bg: var(--openap-theme-lighter);
  --bs-btn-disabled-border-color: var(--openap-theme-lighter);
}

.btn-outline-primary {
  --bs-btn-color: var(--openap-theme-color);
  --bs-btn-border-color: var(--openap-theme-color);
  --bs-btn-hover-bg: var(--openap-theme-color);
  --bs-btn-hover-border-color: var(--openap-theme-color);
  --bs-btn-active-bg: var(--openap-theme-color);
  --bs-btn-active-border-color: var(--openap-theme-color);
  --bs-btn-disabled-color: var(--openap-theme-color);
  --bs-btn-disabled-border-color: var(--openap-theme-color);
}

html:not([data-bs-theme="dark"]) .btn-outline-warning {
  --bs-btn-color: #333;
}

.btn-light {
  --bs-btn-color: var(--openap-theme-darker);
  --bs-btn-hover-color: var(--openap-theme-darker);
  --bs-btn-active-color: var(--openap-theme-darker);
  --bs-btn-disabled-color: var(--openap-theme-darker);
}

.btn-link {
  --bs-link-color: var(--openap-theme-color);
  --bs-link-hover-color: var(--openap-theme-darker);
}

/* Forms */
.form-check-input:checked {
  background-color: var(--openap-theme-color);
  border-color: var(--openap-theme-color);
}

/* Layout */
.sb-sidenav .sb-sidenav-menu .nav-link:hover,
.sb-sidenav .sb-nav-link-icon.active .nav-link,
.sb-sidenav .sb-nav-link-icon.active i.sb-nav-link-icon {
  color: var(--openap-theme-color);
}

.sidebar-brand-text {
  color: var(--openap-theme-color);
}
.sidebar-brand-text:focus,
.sidebar-brand-text:hover {
  color: var(--openap-theme-darker);
}

.sidebar {
  background-color: #f8f9fc;
}

#navbar-system-mode.active {
  color: var(--openap-theme-color);
}

.card .card-header,
.modal-header {
  border-color: var(--openap-theme-color);
  color: #fff;
  background-color: var(--openap-theme-color);
}
.card-body {
  color: #495057;
}
.card-footer, .modal-footer {
  background-color: #f2f1f0;
}

/* --- Page Specific --- */
/* Login */
.login-brand {
  color: var(--openap-theme-color);
}

/* Dashboard */
.connection-item,
.connection-item > i,
.connections-left > .connection-item > span  {
  color: var(--openap-text-light);
}

.connection-item > a.active > span,
.connection-item > a.active > i {
  color: var(--openap-theme-color) !important;
}

.ip-info-toggle {
  background: #e3e3e3;
}
.ip-info-toggle button {
  color: var(--openap-theme-color);
}
.ip-info-toggle button.active {
  background: var(--openap-theme-color);
  color: white;
}

.band.active {
  border-color: var(--openap-theme-color);
  color: var(--openap-theme-color);
}

.device-label {
  color: var(--openap-theme-color);
}

.status-item {
  color: var(--openap-text-light);
}
.status-item.active > span {
  color: var(--openap-theme-color) !important;
}
.status-item.active > div > i {
  color: var(--openap-theme-color) !important;
}

.client-type i {
  color: var(--openap-theme-color);
  border: 2px solid var(--openap-theme-color);
}

.client-type i.badge-icon {
  background: var(--openap-theme-color);
  color: var(--openap-offwhite);
}

.client-count {
  background: var(--openap-theme-color);
  color: var(--openap-offwhite);
}

/* WiFi Client */
.signal-icon .signal-bar {
  background: var(--openap-theme-color);
}

/*
Theme Name: Lights Out
Author: @billz
Author URI: https://github.com/billz
Description: Bootstrap dark mode for the OpenAP default theme
License: GNU General Public License v3.0
*/
/* Dark Mode */
html[data-bs-theme="dark"],
html[data-bs-theme="dark"] body,
html[data-bs-theme="dark"] footer,
html[data-bs-theme="dark"] .sb-sidenav,
html[data-bs-theme="dark"] .sb-topnav,
html[data-bs-theme="dark"] .card-body,
html[data-bs-theme="dark"] .card-footer,
html[data-bs-theme="dark"] .modal:not(#modal-admin-login) .modal-body,
html[data-bs-theme="dark"] .modal:not(#modal-admin-login) .modal-footer {
  background-color: var(--bs-dark) !important;
  color: var(--bs-light) !important;
}

html[data-bs-theme="dark"] .card,
html[data-bs-theme="dark"] .card-footer,
html[data-bs-theme="dark"] .modal:not(#modal-admin-login) .modal-body,
html[data-bs-theme="dark"] .modal:not(#modal-admin-login) .modal-footer {
  border-color: var(--bs-secondary);
}

html[data-bs-theme="dark"] .sb-topnav #sidebarToggle,
html[data-bs-theme="dark"] .sb-sidenav .sb-sidenav-menu .nav-link,
html[data-bs-theme="dark"] .card-body,
html[data-bs-theme="dark"] .table > * > * > * {
  color: var(--bs-light) !important;
}
html[data-bs-theme="dark"] .sb-sidenav .sb-sidenav-menu .nav-link:hover,
html[data-bs-theme="dark"] .sb-sidenav .sb-nav-link-icon.active .nav-link {
  color: var(--openap-theme-color) !important;
}

html[data-bs-theme="dark"] .sb-status,
html[data-bs-theme="dark"] .card-footer,
html[data-bs-theme="dark"] .info-item-xs {
  color: var(--bs-secondary) !important;
}

html[data-bs-theme="dark"] .nav-tabs {
  --bs-nav-tabs-link-active-color: var(--bs-light);
  --bs-nav-tabs-link-active-bg: var(--bs-dark);
  --bs-nav-tabs-link-active-border-color: var(--bs-secondary) var(--bs-secondary) var(--bs-dark);
  --bs-nav-tabs-border-color: var(--bs-secondary);
  --bs-nav-tabs-link-hover-border-color: var(--bs-secondary);
}

html[data-bs-theme="dark"] .ip-info-toggle {
  background: transparent;
  border: 2px solid var(--openap-text-light);
}
