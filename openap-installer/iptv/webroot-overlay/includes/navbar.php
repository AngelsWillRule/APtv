<?php
$openapAdminUsername = $_SESSION['user_id'] ?? '';
$openapMobilePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$openapApEnabled = function_exists('openapComponentEnabled') && openapComponentEnabled('openap');
$openapIptvEnabled = function_exists('openapComponentEnabled') && openapComponentEnabled('iptv');
try {
  $openapAdminAuth = new \OpenAP\Auth\HTTPAuth;
  $openapAdminConfig = $openapAdminAuth->getAuthConfig();
  $openapAdminUsername = $openapAdminConfig['admin_user'] ?? $openapAdminUsername;
} catch (\Throwable $e) {
  $openapAdminUsername = $_SESSION['user_id'] ?? '';
}
?>
<script>
(function () {
  var transition = null;
  var shouldCleanEntryUrl = false;
  try {
    transition = window.sessionStorage.getItem('openap|component-switcher-transition');
  } catch (entryStorageError) {
    transition = null;
  }
  try {
    var entryUrl = new URL(window.location.href);
    if (entryUrl.searchParams.get('openap_component_transition') === 'enter') {
      transition = 'enter';
      try {
        window.sessionStorage.setItem('openap|component-switcher-transition', 'enter');
      } catch (entryStorageWriteError) {
        // The URL marker is sufficient for the early paint guard.
      }
      entryUrl.searchParams.delete('openap_component_transition');
      shouldCleanEntryUrl = true;
    }
    if (entryUrl.searchParams.has('openap_component_reload')) {
      entryUrl.searchParams.delete('openap_component_reload');
      shouldCleanEntryUrl = true;
    }
    if (shouldCleanEntryUrl && window.history && window.history.replaceState) {
      window.history.replaceState(window.history.state, document.title, entryUrl.toString());
    }
  } catch (entryUrlError) {
    // The switcher remains usable when URL or session storage is unavailable.
  }
  if (transition === 'enter') {
    document.documentElement.classList.add('openap-component-entry-pending');
  }
})();
</script>
<nav class="sb-topnav">
  <div class="topbar-left">
    <a class="topbar-brand" href="/">
      <span class="topbar-brand-logo-frame">
        <img class="topbar-brand-logo topbar-brand-logo-aptv" src="app/img/aptv-header-logo-stylized.svg?v=<?php echo filemtime('app/img/aptv-header-logo-stylized.svg'); ?>" alt="APtv">
      </span>
    </a>
  </div>

  <div class="topbar-center">
    <?php if ($openapMobilePath !== '/login' && $openapApEnabled && $openapIptvEnabled): ?>
      <nav class="openap-workspace-switch" aria-label="<?php echo _("Workspace"); ?>">
        <a class="openap-workspace-link<?php echo $openapActiveArea === 'openap' ? ' active' : ''; ?>"
           href="/" title="<?php echo _("AP workspace"); ?>"
           aria-label="<?php echo _("AP workspace"); ?>"<?php echo $openapActiveArea === 'openap' ? ' aria-current="page"' : ''; ?>>
          <i class="fas fa-broadcast-tower" aria-hidden="true"></i><span>AP</span>
        </a>
        <a class="openap-workspace-link<?php echo $openapActiveArea === 'iptv' ? ' active' : ''; ?>"
           href="/aptv" title="<?php echo _("IPTV workspace"); ?>"
           aria-label="<?php echo _("IPTV workspace"); ?>"<?php echo $openapActiveArea === 'iptv' ? ' aria-current="page"' : ''; ?>>
          <i class="fas fa-tv" aria-hidden="true"></i><span>IPTV</span>
        </a>
      </nav>
    <?php endif; ?>
  </div>

  <div class="topbar-right">
    <?php
    // Read operating mode from repeater profile
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $currentMode = $profile['mode']['current'] ?? 'ap_ethernet';

    // Health checks (same logic as api_health.php)
    $hostapdActive = function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active';
    $dnsmasqActive = function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active';
    $firewallActive = function_exists('openapServiceActive')
      && openapServiceActive('openap-firewall.service') === 'active'
      && function_exists('openapNatActive')
      && openapNatActive();
    $lighttpdActive = function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active';

    $apIface = $profile['interfaces']['ap'] ?? (defined('OPENAP_WIFI_AP_INTERFACE') ? OPENAP_WIFI_AP_INTERFACE : 'wlan0');
    // Avoid hostapd_cli in the common page header. A stale hostapd process can
    // remain active after USB passthrough loss, so also require the configured
    // AP interface, MAC and hotspot gateway to be present.
    $apEnabled = $hostapdActive
      && function_exists('openapApInterfaceReady')
      && openapApInterfaceReady();
    $uplinkConnected = true;
    if ($currentMode === 'repeater_wifi') {
      $uplinkHealth = function_exists('openapUplinkHealth') ? openapUplinkHealth() : [];
      $uplinkConnected = !empty($uplinkHealth['ready']);
    }
    if ($currentMode === 'ap_ethernet_bridge') {
      $dnsmasqActive = true;
      $firewallActive = true;
    }
    $healthy = $hostapdActive && $dnsmasqActive && $firewallActive && $lighttpdActive && $apEnabled && $uplinkConnected;
    ?>

    <!-- Common APtv actions -->
    <button class="admin-avatar-link openap-about-trigger" type="button" title="<?php echo _("About APtv"); ?>" data-bs-toggle="modal" data-bs-target="#openapAboutModal" aria-label="<?php echo _("About APtv"); ?>">
      <span class="admin-avatar"><i class="fas fa-info-circle" aria-hidden="true"></i></span>
    </button>
    <button class="admin-avatar-link openap-admin-trigger" type="button" title="<?php echo _("Admin area"); ?>" data-bs-toggle="modal" data-bs-target="#openapAdminModal" aria-label="<?php echo _("Admin area"); ?>">
      <span class="admin-avatar"><i class="fas fa-user" aria-hidden="true"></i></span>
    </button>
  </div>
</nav>

<div class="modal fade openap-admin-modal" id="openapAdminModal" tabindex="-1" aria-labelledby="openapAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-admin-dialog">
    <div class="modal-content openap-admin-modal-content">
      <div class="modal-header openap-ap-ethernet-header">
        <div>
          <div class="openap-ap-ethernet-title" id="openapAdminModalLabel"><?php echo _("Account settings"); ?></div>
          <div class="openap-ap-ethernet-subtitle"><?php echo _("Manage administrator access"); ?></div>
        </div>
        <div class="openap-ap-ethernet-header-actions">
          <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-user-lock"></i></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
        </div>
      </div>

      <form id="openapAdminModalForm" role="form" action="/auth_conf" method="POST" class="needs-validation" novalidate>
        <div class="modal-body">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="openap_admin_modal" value="1">
          <div class="openap-admin-feedback" id="openapAdminFeedback" aria-live="polite"></div>

          <div class="openap-admin-panel">
            <div class="openap-admin-panel-heading">
              <i class="fas fa-user-lock"></i>
              <span><?php echo _("Credentials"); ?></span>
            </div>

            <div class="mb-3">
              <label class="form-label" for="openap-admin-username"><?php echo _("Username"); ?></label>
              <input type="text" class="form-control" id="openap-admin-username" name="username" value="<?php echo htmlspecialchars($openapAdminUsername, ENT_QUOTES); ?>" required>
              <div class="invalid-feedback"><?php echo _("Please provide a valid username."); ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="openap-admin-oldpass"><?php echo _("Current password"); ?></label>
              <div class="input-group has-validation">
                <input type="password" class="form-control" id="openap-admin-oldpass" name="oldpass" autocomplete="current-password" required>
                <div class="input-group-text js-toggle-password" data-bs-target="[name=oldpass]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                <div class="invalid-feedback"><?php echo _("Please enter your old password."); ?></div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="openap-admin-newpass"><?php echo _("New password"); ?></label>
                <div class="input-group has-validation">
                  <input type="password" class="form-control" id="openap-admin-newpass" name="newpass" autocomplete="new-password" required>
                  <div class="input-group-text js-toggle-password" data-bs-target="[name=newpass]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                  <div class="invalid-feedback"><?php echo _("Please enter a new password."); ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="openap-admin-newpassagain"><?php echo _("Repeat password"); ?></label>
                <div class="input-group has-validation">
                  <input type="password" class="form-control" id="openap-admin-newpassagain" name="newpassagain" autocomplete="new-password" required>
                  <div class="input-group-text js-toggle-password" data-bs-target="[name=newpassagain]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                  <div class="invalid-feedback" id="openap-admin-password-match-feedback"><?php echo _("Please re-enter your new password."); ?></div>
                </div>
              </div>
            </div>
          </div>

          <div class="openap-admin-panel openap-admin-components" id="openapComponentPanel">
            <div class="openap-admin-panel-heading">
              <i class="fas fa-cubes"></i>
              <span><?php echo _("Components"); ?></span>
            </div>

            <div class="openap-admin-component-list">
              <div class="openap-admin-component-row">
                <div class="openap-admin-component-info">
                  <span class="openap-admin-component-icon" aria-hidden="true"><i class="fas fa-broadcast-tower"></i></span>
                  <div>
                    <div class="openap-admin-component-title">OpenAP / AP</div>
                    <div class="openap-admin-component-copy"><?php echo _("Wireless access point and network management"); ?></div>
                  </div>
                </div>
                <div class="openap-admin-component-actions">
                  <span class="openap-admin-component-status <?php echo $openapApEnabled ? 'is-installed' : 'is-missing'; ?>">
                    <?php echo $openapApEnabled ? _("Installed") : _("Not installed"); ?>
                  </span>
                  <button type="button" class="btn btn-sm js-openap-component-action <?php echo $openapApEnabled ? 'btn-outline-danger' : 'btn-primary'; ?>"
                          data-component="ap" data-component-action="<?php echo $openapApEnabled ? 'remove' : 'install'; ?>"
                          data-component-label="OpenAP / AP">
                    <i class="fas <?php echo $openapApEnabled ? 'fa-trash-alt' : 'fa-download'; ?> me-1" aria-hidden="true"></i>
                    <?php echo $openapApEnabled ? _("Remove") : _("Install"); ?>
                  </button>
                </div>
              </div>

              <div class="openap-admin-component-row">
                <div class="openap-admin-component-info">
                  <span class="openap-admin-component-icon" aria-hidden="true"><i class="fas fa-tv"></i></span>
                  <div>
                    <div class="openap-admin-component-title">APtv / IPTV</div>
                    <div class="openap-admin-component-copy"><?php echo _("Live TV, movies and series"); ?></div>
                  </div>
                </div>
                <div class="openap-admin-component-actions">
                  <span class="openap-admin-component-status <?php echo $openapIptvEnabled ? 'is-installed' : 'is-missing'; ?>">
                    <?php echo $openapIptvEnabled ? _("Installed") : _("Not installed"); ?>
                  </span>
                  <button type="button" class="btn btn-sm js-openap-component-action <?php echo $openapIptvEnabled ? 'btn-outline-danger' : 'btn-primary'; ?>"
                          data-component="iptv" data-component-action="<?php echo $openapIptvEnabled ? 'remove' : 'install'; ?>"
                          data-component-label="APtv / IPTV">
                    <i class="fas <?php echo $openapIptvEnabled ? 'fa-trash-alt' : 'fa-download'; ?> me-1" aria-hidden="true"></i>
                    <?php echo $openapIptvEnabled ? _("Remove") : _("Install"); ?>
                  </button>
                </div>
              </div>
            </div>
            <div class="openap-admin-component-wizard" id="openapApWizard" hidden>
              <div class="openap-admin-component-wizard-heading">
                <i class="fas fa-magic" aria-hidden="true"></i>
                <span>Configure OpenAP / AP</span>
              </div>
              <p class="openap-admin-component-wizard-copy">
                Choose the hotspot interface and credentials used for the AP installation.
              </p>
              <div class="openap-admin-component-wizard-status form-text" id="openapApWizardStatus" role="status" hidden></div>
              <div class="openap-admin-component-wizard-grid">
                <div>
                  <label class="form-label" for="openapApWizardInterface">Hotspot interface</label>
                  <select class="form-select" id="openapApWizardInterface" required></select>
                  <div class="form-text" id="openapApWizardInterfaceHelp">The recommended adapter is selected automatically.</div>
                </div>
                <div>
                  <label class="form-label" for="openapApWizardCountry">Wireless country</label>
                  <input class="form-control" id="openapApWizardCountry" maxlength="2" minlength="2" pattern="[A-Za-z]{2}" value="IT" required>
                  <div class="form-text">Two-letter regulatory country code.</div>
                </div>
                <div>
                  <label class="form-label" for="openapApWizardSsid">Hotspot SSID</label>
                  <input class="form-control" id="openapApWizardSsid" maxlength="32" required value="OpenAP">
                </div>
                <div>
                  <label class="form-label" for="openapApWizardPassword">Hotspot password</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="openapApWizardPassword" minlength="8" maxlength="63" autocomplete="new-password" required>
                    <div class="input-group-text js-toggle-password" data-bs-target="#openapApWizardPassword" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                  </div>
                  <div class="form-text">8 to 63 characters.</div>
                </div>
              </div>
              <div class="openap-admin-component-wizard-network">
                <span>Ethernet uplink</span>
                <strong id="openapApWizardEthernet">Detecting...</strong>
              </div>
              <div id="openapApWizardAdminFields" hidden>
                <div class="openap-admin-component-wizard-subheading">Web administrator credentials</div>
                <div class="openap-admin-component-wizard-grid">
                  <div>
                    <label class="form-label" for="openapApWizardAdminUser">Admin username</label>
                    <input class="form-control" id="openapApWizardAdminUser" value="admin" autocomplete="username">
                  </div>
                  <div>
                    <label class="form-label" for="openapApWizardAdminPassword">Admin password</label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="openapApWizardAdminPassword" minlength="12" maxlength="128" autocomplete="new-password">
                      <div class="input-group-text js-toggle-password" data-bs-target="#openapApWizardAdminPassword" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                    </div>
                  </div>
                  <div>
                    <label class="form-label" for="openapApWizardAdminPasswordConfirm">Repeat admin password</label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="openapApWizardAdminPasswordConfirm" minlength="12" maxlength="128" autocomplete="new-password">
                      <div class="input-group-text js-toggle-password" data-bs-target="#openapApWizardAdminPasswordConfirm" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="openap-admin-component-wizard-preserved" id="openapApWizardAdminPreserved" hidden>
                Existing web administrator credentials will be preserved.
              </div>
              <div class="openap-admin-component-wizard-actions">
                <button type="button" class="btn btn-light" id="openapApWizardCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="openapApWizardContinue">Continue</button>
              </div>
            </div>
            <div class="openap-admin-component-feedback" id="openapComponentFeedback" aria-live="polite"></div>
          </div>

          <div class="openap-admin-session">
            <div>
              <div class="openap-admin-session-title"><?php echo _("Active session"); ?></div>
              <div class="openap-admin-session-copy"><?php echo _("End the current administrator session and return to login."); ?></div>
            </div>
            <button type="submit" class="btn btn-outline-danger" name="logout" value="1" formnovalidate>
              <i class="fas fa-sign-out-alt me-1"></i><?php echo _("Logout"); ?>
            </button>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times" aria-hidden="true"></i> <?php echo _("Cancel"); ?></button>
          <?php if (!OPENAP_MONITOR_ENABLED) : ?>
            <button type="submit" class="btn btn-primary openap-admin-save" name="UpdateAdminPassword" value="1">
              <i class="fas fa-save me-1"></i><?php echo _("Save settings"); ?>
            </button>
          <?php endif ?>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade openap-about-modal" id="openapAboutModal" tabindex="-1" aria-labelledby="openapAboutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable openap-about-dialog">
    <div class="modal-content openap-about-modal-content">
      <div class="modal-header openap-ap-ethernet-header">
        <div>
          <div class="openap-ap-ethernet-title" id="openapAboutModalLabel"><?php echo _("About APtv"); ?></div>
          <div class="openap-ap-ethernet-subtitle"><?php echo _("The unified OpenAP and IPTV platform"); ?></div>
        </div>
        <div class="openap-ap-ethernet-header-actions">
          <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
        </div>
      </div>
      <div class="modal-body openap-about-modal-body">
        <?php echo renderTemplate("aptv_about"); ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('openapAdminModalForm');
  var feedback = document.getElementById('openapAdminFeedback');
  var modal = document.getElementById('openapAdminModal');
  var username = document.getElementById('openap-admin-username');
  var logoutButton = form ? form.querySelector('[name="logout"]') : null;
  var newPassword = document.getElementById('openap-admin-newpass');
  var repeatedPassword = document.getElementById('openap-admin-newpassagain');
  var passwordMatchFeedback = document.getElementById('openap-admin-password-match-feedback');
  var componentFeedback = document.getElementById('openapComponentFeedback');
  var componentButtons = Array.prototype.slice.call(document.querySelectorAll('.js-openap-component-action'));
  var apWizard = document.getElementById('openapApWizard');
  var apWizardInterface = document.getElementById('openapApWizardInterface');
  var apWizardStatus = document.getElementById('openapApWizardStatus');
  var apWizardCountry = document.getElementById('openapApWizardCountry');
  var apWizardSsid = document.getElementById('openapApWizardSsid');
  var apWizardPassword = document.getElementById('openapApWizardPassword');
  var apWizardEthernet = document.getElementById('openapApWizardEthernet');
  var apWizardAdminFields = document.getElementById('openapApWizardAdminFields');
  var apWizardAdminPreserved = document.getElementById('openapApWizardAdminPreserved');
  var apWizardAdminUser = document.getElementById('openapApWizardAdminUser');
  var apWizardAdminPassword = document.getElementById('openapApWizardAdminPassword');
  var apWizardAdminPasswordConfirm = document.getElementById('openapApWizardAdminPasswordConfirm');
  var apWizardCancel = document.getElementById('openapApWizardCancel');
  var apWizardContinue = document.getElementById('openapApWizardContinue');
  var pendingApWizardData = null;
  var componentNotification = null;
  var componentNotificationActive = false;
  var componentNotificationTitle = '';
  var componentNotificationLog = [];
  var componentNotificationTerminalLog = [];
  var componentNotificationLastMessage = '';
  var workspaceSwitch = document.querySelector('.openap-workspace-switch');
  var componentSwitcherTransitionKey = 'openap|component-switcher-transition';
  var componentOperationResultKey = 'openap|component-operation-result-v2';
  var legacyComponentOperationResultKey = 'openap|component-operation-result';
  var reducedComponentSwitcherMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var adminModalContent = modal ? modal.querySelector('.openap-admin-modal-content') : null;
  var adminModalHeightTimer = 0;
  var reducedAdminModalMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function playComponentSwitcherEntry() {
    if (!workspaceSwitch) return;
    var transition = document.documentElement.classList.contains('openap-component-entry-pending') ? 'enter' : null;
    try {
      var storedTransition = window.sessionStorage.getItem(componentSwitcherTransitionKey);
      if (storedTransition) transition = storedTransition;
      window.sessionStorage.removeItem(componentSwitcherTransitionKey);
    } catch (transitionError) {
      transition = null;
    }
    if (transition !== 'enter') {
      return;
    }
    document.documentElement.classList.remove('openap-component-entry-pending');
    if (reducedComponentSwitcherMotion) {
      return;
    }
    workspaceSwitch.classList.add('is-component-entering');
    window.setTimeout(function () {
      workspaceSwitch.classList.remove('is-component-entering');
    }, 820);
  }

  function showPendingComponentNotification(attempt) {
    attempt = Number(attempt) || 0;
    var redirectAction = null;
    var redirectLabel = '';
    var resultUrl = null;
    try {
      resultUrl = new URL(window.location.href);
      redirectAction = resultUrl.searchParams.get('openap_component_result');
      redirectLabel = resultUrl.searchParams.get('openap_component_label') || '';
      if (redirectAction !== 'install' && redirectAction !== 'remove') redirectAction = null;
    } catch (resultUrlError) {
      resultUrl = null;
      redirectAction = null;
      redirectLabel = '';
    }
    var raw = null;
    try {
      raw = window.sessionStorage.getItem(componentOperationResultKey);
      window.sessionStorage.removeItem(legacyComponentOperationResultKey);
    } catch (notificationStorageError) {
      raw = null;
    }
    if (!redirectAction) return;
    if (typeof window.openapNotify !== 'function') {
      if (attempt < 20) {
        window.setTimeout(function () { showPendingComponentNotification(attempt + 1); }, 100);
      }
      return;
    }
    var result = null;
    try {
      result = JSON.parse(raw);
    } catch (notificationParseError) {
      result = null;
    }
    if (!result && redirectAction) result = {};
    if (result && redirectAction) {
      result.action = redirectAction;
      if (!Array.isArray(result.details)) result.details = [];
      if (!Array.isArray(result.sequence)) result.sequence = [];
      result.title = redirectAction === 'remove'
        ? '<?php echo addslashes(_("Component removed")); ?>'
        : '<?php echo addslashes(_("Component installed")); ?>';
      if (redirectLabel) {
        result.message = redirectLabel + (redirectAction === 'remove'
          ? ' <?php echo addslashes(_("was removed successfully.")); ?>'
          : ' <?php echo addslashes(_("was installed successfully.")); ?>');
      }
    }
    try {
      window.sessionStorage.removeItem(componentOperationResultKey);
    } catch (notificationRemoveError) {
      // The notification can still be shown when sessionStorage is read-only.
    }
    if (!result || !result.message) return;
    window.openapNotify({
      level: 'success',
      icon: result.action === 'remove' ? 'fa-trash-alt' : 'fa-check-circle',
      title: result.title || 'Component operation completed',
      message: result.message,
      duration: 5000,
      details: result.details,
      sequenceMode: true,
      sequence: result.sequence.length ? result.sequence : [result.message],
      sequenceIndex: Math.max(0, (result.sequence.length ? result.sequence : [result.message]).length - 1)
    });
    if (resultUrl && window.history && window.history.replaceState) {
      resultUrl.searchParams.delete('openap_component_result');
      resultUrl.searchParams.delete('openap_component_label');
      window.history.replaceState(window.history.state, document.title, resultUrl.toString());
    }
  }

  playComponentSwitcherEntry();
  window.setTimeout(function () { showPendingComponentNotification(0); }, 0);

  function prepareComponentSwitcherTransition(action) {
    if (action === 'install') {
      try {
        window.sessionStorage.setItem(componentSwitcherTransitionKey, 'enter');
      } catch (transitionError) {
        // The destination page can still render the switcher normally.
      }
      return;
    }
    if (action === 'remove' && workspaceSwitch && !reducedComponentSwitcherMotion) {
      workspaceSwitch.classList.remove('is-component-entering');
      workspaceSwitch.classList.remove('is-component-exiting');
      void workspaceSwitch.offsetWidth;
      workspaceSwitch.classList.add('is-component-exiting');
    }
  }

  if (!form || !feedback || !newPassword || !repeatedPassword) {
    return;
  }

  function setApWizardFieldsRequired(required) {
    [apWizardInterface, apWizardCountry, apWizardSsid, apWizardPassword].forEach(function (field) {
      if (field) field.required = required;
    });
  }

  setApWizardFieldsRequired(false);

  function showAccountNotification(level, title, message, details) {
    if (typeof window.openapNotify === 'function') {
      window.openapNotify({
        level: level,
        icon: level === 'success' ? 'fa-user-check' : 'fa-user-lock',
        title: title,
        message: message,
        duration: level === 'success' ? 5000 : 0,
        details: Array.isArray(details) ? details : []
      });
      feedback.innerHTML = '';
      return true;
    }
    return false;
  }

  function resetComponentNotification(title) {
    componentNotification = null;
    componentNotificationActive = true;
    componentNotificationTitle = title;
    componentNotificationLog = [];
    componentNotificationTerminalLog = [];
    componentNotificationLastMessage = '';
    if (componentFeedback) componentFeedback.innerHTML = '';
  }

  function updateComponentNotification(message, kind, progress, terminalLines) {
    if (typeof window.openapNotify !== 'function') return false;
    var text = String(message || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
    if (text && text !== componentNotificationLastMessage) {
      componentNotificationLog.push(text);
      componentNotificationLastMessage = text;
    }
    if (Array.isArray(terminalLines) && terminalLines.length) {
      componentNotificationTerminalLog = terminalLines
        .map(function (line) { return String(line || '').trim(); })
        .filter(Boolean);
    }
    var level = kind === 'danger' ? 'error' : 'success';
    var icon = kind === 'danger' ? 'fa-exclamation-triangle' : (Number(progress) >= 100 ? 'fa-check-circle' : 'fa-cog fa-spin');
    var options = {
      level: level,
      icon: icon,
      title: componentNotificationTitle || '<?php echo addslashes(_("Component operation")); ?>',
      message: text,
      duration: 0,
      details: componentNotificationTerminalLog,
      sequenceMode: true,
      sequence: componentNotificationLog,
      sequenceIndex: componentNotificationLog.length - 1
    };
    if (componentNotification && componentNotification.element && document.body.contains(componentNotification.element)) {
      componentNotification.update(options);
    } else {
      componentNotification = window.openapNotify(options);
    }
    return true;
  }

  function submitLogout() {
    if (form.dataset.logoutSubmitting === 'true') {
      return;
    }
    form.dataset.logoutSubmitting = 'true';
    var logoutField = document.createElement('input');
    logoutField.type = 'hidden';
    logoutField.name = 'logout';
    logoutField.value = '1';
    form.appendChild(logoutField);
    form.noValidate = true;
    if (modal && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      var modalInstance = window.bootstrap.Modal.getInstance(modal);
      if (modalInstance) modalInstance.hide();
    }
    if (modal) {
      modal.classList.remove('show');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      modal.removeAttribute('aria-modal');
      modal.removeAttribute('role');
    }
    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
      backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    var submit = function () { HTMLFormElement.prototype.submit.call(form); };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      submit();
      return;
    }
    try {
      window.sessionStorage.setItem('openap|auth-transition', 'logout');
    } catch (storageError) {
      // Logout must not depend on sessionStorage.
    }
    var pageContent = document.querySelector('#layoutSidenav_content main > .container-fluid');
    if (pageContent) {
      pageContent.classList.remove('openap-page-enter', 'openap-page-enter-forward', 'openap-page-enter-backward', 'openap-page-exit', 'openap-page-exit-forward', 'openap-page-exit-backward');
      pageContent.style.animation = 'none';
      pageContent.style.transition = 'none';
      pageContent.style.transform = 'none';
      pageContent.style.opacity = '1';
    }
    document.documentElement.classList.remove('openap-page-preload-forward', 'openap-page-preload-backward', 'openap-area-enter-down', 'openap-area-enter-up');
    window.scrollTo(0, 0);
    [document.scrollingElement, document.documentElement, document.body,
      document.querySelector('#layoutSidenav_content')].forEach(function (element) {
      if (element) {
        element.scrollTop = 0;
        element.scrollLeft = 0;
      }
    });
    document.body.classList.add('openap-auth-logout-exit');
    window.setTimeout(submit, 420);
  }

  if (logoutButton) {
    logoutButton.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var message = '<?php echo addslashes(_("End the current administrator session and return to login?")); ?>';
      if (typeof window.openapNotify === 'function') {
        window.openapNotify({
          level: 'success',
          icon: 'fa-sign-out-alt',
          title: '<?php echo addslashes(_("Confirm logout")); ?>',
          message: message,
          duration: 0,
          actions: [
            { label: '<?php echo addslashes(_("Logout")); ?>', icon: 'fa-sign-out-alt', onClick: submitLogout },
            { label: '<?php echo addslashes(_("Cancel")); ?>', icon: 'fa-times', onClick: function () {} }
          ]
        });
        return;
      }
      if (window.confirm(message)) submitLogout();
    });
  }

  function validatePasswordMatch(showState) {
    var hasRepeat = repeatedPassword.value.length > 0;
    var mismatch = hasRepeat && newPassword.value !== repeatedPassword.value;
    repeatedPassword.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
    repeatedPassword.setAttribute('aria-invalid', mismatch ? 'true' : 'false');
    repeatedPassword.classList.toggle('is-invalid', mismatch && (showState || hasRepeat));
    repeatedPassword.classList.toggle('is-valid', hasRepeat && !mismatch);
    if (passwordMatchFeedback) {
      passwordMatchFeedback.textContent = mismatch
        ? '<?php echo addslashes(_("Passwords do not match.")); ?>'
        : '<?php echo addslashes(_("Please re-enter your new password.")); ?>';
    }
    return !mismatch;
  }

  newPassword.addEventListener('input', function () {
    validatePasswordMatch(false);
  });
  repeatedPassword.addEventListener('input', function () {
    validatePasswordMatch(false);
  });

  function resetAdminForm() {
    form.reset();
    form.classList.remove('was-validated');
    feedback.innerHTML = '';
    form.querySelectorAll('.is-valid, .is-invalid').forEach(function (field) {
      field.classList.remove('is-valid', 'is-invalid');
      field.setAttribute('aria-invalid', 'false');
    });
    repeatedPassword.setCustomValidity('');
    if (passwordMatchFeedback) {
      passwordMatchFeedback.textContent = '<?php echo addslashes(_("Please re-enter your new password.")); ?>';
    }
    ['oldpass', 'newpass', 'newpassagain'].forEach(function (fieldName) {
      var field = form.querySelector('[name="' + fieldName + '"]');
      if (field) {
        field.value = '';
        field.type = 'password';
      }
    });
    form.querySelectorAll('.js-toggle-password i').forEach(function (icon) {
      icon.classList.remove('fa-eye-slash');
      icon.classList.add('fa-eye');
    });
    closeApWizard();
    pendingApWizardData = null;
    if (!componentNotificationActive) {
      componentButtons.forEach(function (item) { item.disabled = false; });
    }
  }

  function closeComponentModal() {
    if (!modal) return;
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      var modalInstance = window.bootstrap.Modal.getInstance(modal)
        || window.bootstrap.Modal.getOrCreateInstance(modal);
      modalInstance.hide();
      return;
    }
    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    modal.removeAttribute('role');
    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
      backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
  }

  function showComponentConfirmationNotification(title, message, actionLabel, actionIcon, onConfirm) {
    var options = {
      level: 'success',
      icon: actionIcon,
      title: title,
      message: message,
      duration: 0,
      actions: [
        { label: actionLabel, icon: actionIcon, onClick: onConfirm },
        { label: '<?php echo addslashes(_("Cancel")); ?>', icon: 'fa-times', onClick: function () {} }
      ]
    };
    if (typeof window.openapNotify === 'function') {
      try {
        window.openapNotify(options);
        if (document.getElementById('openapUniversalNotification')) return;
      } catch (notificationError) {
        // Use the same APtv notification surface below when the shared helper
        // is unavailable or fails before it can attach its element.
      }
    }

    var previous = document.getElementById('openapUniversalNotification');
    if (previous) previous.remove();
    var notification = document.createElement('aside');
    notification.id = 'openapUniversalNotification';
    notification.className = 'openap-universal-notification is-success';
    notification.setAttribute('role', 'status');
    notification.setAttribute('aria-live', 'polite');
    notification.innerHTML =
      '<div class="openap-universal-notification-icon"><i class="fas"></i></div>' +
      '<div class="openap-universal-notification-main">' +
        '<div class="openap-universal-notification-heading"><strong></strong><button type="button" class="openap-universal-notification-close" aria-label="Close notification"><i class="fas fa-times"></i></button></div>' +
        '<p></p>' +
        '<div class="openap-universal-notification-actions"></div>' +
      '</div>';
    notification.querySelector('.openap-universal-notification-icon i').className = 'fas ' + actionIcon;
    notification.querySelector('strong').textContent = title;
    notification.querySelector('p').textContent = message;
    var close = function () {
      notification.classList.add('leaving');
      notification.classList.remove('show');
      window.setTimeout(function () { notification.remove(); }, 420);
    };
    notification.querySelector('.openap-universal-notification-close').addEventListener('click', close);
    var actions = notification.querySelector('.openap-universal-notification-actions');
    [
      { label: actionLabel, icon: actionIcon, onClick: onConfirm },
      { label: '<?php echo addslashes(_("Cancel")); ?>', icon: 'fa-times', onClick: function () {} }
    ].forEach(function (action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'openap-universal-notification-action';
      button.innerHTML = '<i class="fas ' + action.icon + '"></i><span></span>';
      button.querySelector('span').textContent = action.label;
      button.addEventListener('click', function () {
        try { action.onClick(); } finally { close(); }
      });
      actions.appendChild(button);
    });
    document.body.appendChild(notification);
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { notification.classList.add('show'); });
    });
  }

  if (modal) {
    modal.addEventListener('hidden.bs.modal', resetAdminForm);
  }

  function componentMessage(message, kind) {
    if (componentNotificationActive && updateComponentNotification(message, kind, arguments.length > 2 ? arguments[2] : 0, arguments.length > 3 ? arguments[3] : null)) {
      return;
    }
    if (!componentFeedback) return;
    componentFeedback.innerHTML = '<div class="alert alert-' + kind + ' fade show" role="alert">' + message + '</div>';
  }

  function animateAdminModalHeight(change) {
    if (!adminModalContent || !modal || !modal.classList.contains('show') || reducedAdminModalMotion) {
      change();
      return;
    }
    window.clearTimeout(adminModalHeightTimer);
    adminModalContent.classList.remove('is-height-animating');
    var startHeight = adminModalContent.getBoundingClientRect().height;
    if (!startHeight) {
      change();
      return;
    }
    adminModalContent.style.height = startHeight + 'px';
    change();
    adminModalContent.style.height = 'auto';
    var endHeight = adminModalContent.getBoundingClientRect().height;
    adminModalContent.style.height = startHeight + 'px';
    void adminModalContent.offsetHeight;
    adminModalContent.classList.add('is-height-animating');
    window.requestAnimationFrame(function () {
      adminModalContent.style.height = endHeight + 'px';
    });
    adminModalHeightTimer = window.setTimeout(function () {
      adminModalContent.classList.remove('is-height-animating');
      adminModalContent.style.removeProperty('height');
    }, 480);
  }

  function setApWizardAdminRequired(required) {
    [apWizardAdminUser, apWizardAdminPassword, apWizardAdminPasswordConfirm].forEach(function (field) {
      if (field) field.required = required;
    });
  }

  function closeApWizard() {
    if (!apWizard) return;
    var resetWizard = function () {
      apWizard.hidden = true;
      setApWizardFieldsRequired(false);
      if (apWizardInterface) apWizardInterface.innerHTML = '';
      if (apWizardInterface) apWizardInterface.disabled = false;
      if (apWizardContinue) apWizardContinue.disabled = false;
      if (apWizardStatus) {
        apWizardStatus.textContent = '';
        apWizardStatus.hidden = true;
        apWizardStatus.classList.remove('text-danger');
      }
      if (apWizardPassword) apWizardPassword.value = '';
      if (apWizardAdminPassword) apWizardAdminPassword.value = '';
      if (apWizardAdminPasswordConfirm) apWizardAdminPasswordConfirm.value = '';
      setApWizardAdminRequired(false);
      if (componentFeedback && !componentNotificationActive) componentFeedback.innerHTML = '';
    };
    if (apWizard.hidden) {
      resetWizard();
      return;
    }
    animateAdminModalHeight(resetWizard);
  }

  function fillApWizard(data) {
    if (!apWizard || !apWizardInterface) return;
    apWizardInterface.innerHTML = '';
    var interfaces = Array.isArray(data.interfaces) ? data.interfaces : [];
    interfaces.forEach(function (item) {
      var option = document.createElement('option');
      var bands = [];
      if (item.supports_24ghz) bands.push('2.4 GHz');
      if (item.supports_5ghz) bands.push('5 GHz');
      option.value = item.name;
      option.textContent = item.name + (item.driver ? ' - ' + item.driver : '') +
        (bands.length ? ' (' + bands.join(', ') + ')' : '');
      option.selected = item.name === data.recommended_interface;
      apWizardInterface.appendChild(option);
    });
    if (!interfaces.length) {
      var unavailable = document.createElement('option');
      unavailable.value = '';
      unavailable.textContent = 'No AP-capable Wi-Fi interface detected';
      unavailable.disabled = true;
      unavailable.selected = true;
      apWizardInterface.appendChild(unavailable);
      apWizardInterface.disabled = true;
    } else {
      apWizardInterface.disabled = false;
    }
    if (!apWizardInterface.value && apWizardInterface.options.length) {
      apWizardInterface.selectedIndex = 0;
    }
    if (apWizardCountry) apWizardCountry.value = (data.country || 'IT').toUpperCase();
    if (apWizardSsid) apWizardSsid.value = 'OpenAP';
    if (apWizardEthernet) apWizardEthernet.textContent = data.ethernet || 'Automatically detected';
    var preserveAdmin = !!data.admin_credentials_present;
    if (apWizardAdminFields) apWizardAdminFields.hidden = preserveAdmin;
    if (apWizardAdminPreserved) apWizardAdminPreserved.hidden = !preserveAdmin;
    if (apWizardAdminUser && data.admin_user) apWizardAdminUser.value = data.admin_user;
    setApWizardAdminRequired(!preserveAdmin);
  }

  function setApWizardStatus(message, isError) {
    if (!apWizardStatus) return;
    apWizardStatus.textContent = message || '';
    apWizardStatus.hidden = !message;
    apWizardStatus.classList.toggle('text-danger', !!isError);
  }

  function openApWizard(label) {
    if (!apWizard) {
      pendingApWizardData = null;
      componentButtons.forEach(function (item) {
        if (item.getAttribute('data-component') === 'ap') item.click();
      });
      return;
    }
    componentButtons.forEach(function (item) { item.disabled = true; });
    animateAdminModalHeight(function () {
      apWizard.hidden = false;
      if (apWizardInterface) {
        apWizardInterface.disabled = true;
        apWizardInterface.innerHTML = '<option selected>Detecting wireless interfaces...</option>';
      }
      if (apWizardContinue) apWizardContinue.disabled = true;
      setApWizardStatus('Detecting the wireless adapter and loading the AP settings...', false);
      componentMessage('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Detecting AP hardware...', 'info');
    });
    fetch('/ajax/system/components.php?wizard=ap', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to inspect the AP hardware.');
          return data;
        });
      })
      .then(function (data) {
        animateAdminModalHeight(function () {
          fillApWizard(data);
          setApWizardFieldsRequired(true);
          if (!data.interfaces || !data.interfaces.length) {
            if (apWizardContinue) apWizardContinue.disabled = true;
            setApWizardStatus('No AP-capable Wi-Fi interface was detected. Check the wireless adapter and driver, then try again.', true);
          } else {
            if (apWizardContinue) apWizardContinue.disabled = false;
            setApWizardStatus('Review the AP settings, then continue.', false);
          }
        });
      })
      .catch(function (error) {
        setApWizardStatus(error.message || 'Unable to inspect the AP hardware.', true);
        if (apWizardContinue) apWizardContinue.disabled = true;
        if (!apWizard) componentMessage(error.message || 'Unable to inspect the AP hardware.', 'danger');
        componentButtons.forEach(function (item) { item.disabled = false; });
      });
  }

  componentButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
      var component = button.getAttribute('data-component');
      var action = button.getAttribute('data-component-action');
      var label = button.getAttribute('data-component-label') || component;
      try {
        window.sessionStorage.removeItem(componentOperationResultKey);
        window.sessionStorage.removeItem(legacyComponentOperationResultKey);
      } catch (notificationStorageError) {
        // A stale result must never change the meaning of the new operation.
      }
      if (component === 'ap' && action === 'install' && !pendingApWizardData) {
        openApWizard(label);
        return;
      }
      var wizardData = pendingApWizardData;
      pendingApWizardData = null;
      var csrfField = form.querySelector('[name="csrf_token"]');
      var csrfToken = csrfField ? csrfField.value : '';

      var startComponentOperation = function () {
        try {
          window.sessionStorage.removeItem(componentOperationResultKey);
          window.sessionStorage.removeItem(legacyComponentOperationResultKey);
        } catch (notificationStorageError) {
          // The operation must not depend on sessionStorage.
        }
        closeComponentModal();
        resetComponentNotification((action === 'remove' ? '<?php echo addslashes(_("Removing")); ?> ' : '<?php echo addslashes(_("Installing")); ?> ') + label);
        componentButtons.forEach(function (item) { item.disabled = true; });
        componentMessage((action === 'remove' ? '<?php echo addslashes(_("Removing")); ?> ' : '<?php echo addslashes(_("Installing")); ?> ') + label + '...', 'info', 5);

        var request = new FormData();
        request.set('csrf_token', csrfToken);
        request.set('component', component);
        request.set('action', action);
        if (wizardData) {
          Object.keys(wizardData).forEach(function (key) {
            request.set(key, wizardData[key]);
          });
        }

        fetch('/ajax/system/components.php', {
          method: 'POST',
          body: request,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
        })
          .then(function (response) {
            return response.json().then(function (data) {
              if (!response.ok || !data.success) {
                throw new Error(data.message || '<?php echo addslashes(_("The component operation failed.")); ?>');
              }
              return data;
            });
          })
          .then(function (data) {
            if (!data.accepted || !data.job_id) {
              throw new Error('<?php echo addslashes(_("The component operation was not started.")); ?>');
            }
            componentMessage('<?php echo addslashes(_("Operation queued.")); ?>', 'info', 10);
            pollComponentOperation(data.job_id, label, component, action, 0);
          })
          .catch(function (error) {
            componentMessage((error && error.message) ? error.message : '<?php echo addslashes(_("The component operation failed.")); ?>', 'danger', 100);
            componentNotificationActive = false;
            componentButtons.forEach(function (item) { item.disabled = false; });
          });
      };

      if (action === 'remove') {
        var removalMessage = '<?php echo addslashes(_("Remove")); ?> ' + label + '? <?php echo addslashes(_("The component and its managed services will be removed.")); ?>';
        showComponentConfirmationNotification(
          '<?php echo addslashes(_("Confirm component removal")); ?>',
          removalMessage,
          '<?php echo addslashes(_("Remove")); ?>',
          'fa-trash-alt',
          startComponentOperation
        );
        return;
      }

      if (action === 'install' && component === 'iptv') {
        var installationMessage = '<?php echo addslashes(_("Install")); ?> ' + label + '? <?php echo addslashes(_("The component and its managed services will be installed.")); ?>';
        showComponentConfirmationNotification(
          '<?php echo addslashes(_("Confirm component installation")); ?>',
          installationMessage,
          '<?php echo addslashes(_("Install")); ?>',
          'fa-download',
          startComponentOperation
        );
        return;
      }

      if (component === 'iptv') return;
      startComponentOperation();
    });
  });

  if (apWizardCancel) {
    apWizardCancel.addEventListener('click', function () {
      closeApWizard();
      pendingApWizardData = null;
      componentButtons.forEach(function (item) { item.disabled = false; });
    });
  }

  if (apWizardContinue) {
    apWizardContinue.addEventListener('click', function () {
      if (apWizardInterface && apWizardInterface.disabled) {
        setApWizardStatus('No AP-capable Wi-Fi interface is available. Connect or enable a compatible adapter and try again.', true);
        return;
      }
      if (!apWizardInterface || !apWizardInterface.value || !apWizardCountry || !apWizardCountry.checkValidity() ||
          !apWizardSsid || !apWizardSsid.checkValidity() || !apWizardPassword || !apWizardPassword.checkValidity()) {
        setApWizardStatus('Complete the hotspot settings before continuing.', true);
        return;
      }
      var adminRequired = apWizardAdminFields && !apWizardAdminFields.hidden;
      if (adminRequired && (!apWizardAdminUser.checkValidity() || !apWizardAdminPassword.checkValidity() ||
          !apWizardAdminPasswordConfirm.checkValidity() ||
          apWizardAdminPassword.value !== apWizardAdminPasswordConfirm.value)) {
        setApWizardStatus('Complete the administrator credentials and make sure the passwords match.', true);
        return;
      }
      pendingApWizardData = {
        ap_iface: apWizardInterface.value,
        country: apWizardCountry.value.toUpperCase(),
        ssid: apWizardSsid.value,
        hotspot_password: apWizardPassword.value,
        admin_user: adminRequired ? apWizardAdminUser.value : '',
        admin_password: adminRequired ? apWizardAdminPassword.value : '',
        admin_password_confirm: adminRequired ? apWizardAdminPasswordConfirm.value : ''
      };
      closeApWizard();
      var apButton = componentButtons.filter(function (item) {
        return item.getAttribute('data-component') === 'ap' &&
          item.getAttribute('data-component-action') === 'install';
      })[0];
      var apConfirmationDetails = [
        '<?php echo addslashes(_("Wireless interface")); ?>: ' + pendingApWizardData.ap_iface,
        '<?php echo addslashes(_("Wireless country")); ?>: ' + pendingApWizardData.country,
        '<?php echo addslashes(_("Hotspot SSID")); ?>: ' + pendingApWizardData.ssid,
        '<?php echo addslashes(_("Ethernet uplink")); ?>: ' + (apWizardEthernet ? apWizardEthernet.textContent : '<?php echo addslashes(_("Automatically detected")); ?>')
      ];
      apConfirmationDetails.push(adminRequired
        ? '<?php echo addslashes(_("Administrator username")); ?>: ' + pendingApWizardData.admin_user + ' · <?php echo addslashes(_("password provided")); ?>'
        : '<?php echo addslashes(_("Administrator credentials")); ?>: <?php echo addslashes(_("existing credentials preserved")); ?>');
      var startApInstallation = function () {
        if (!apButton) return;
        apButton.disabled = false;
        apButton.click();
      };
      var editApSettings = function () {
        pendingApWizardData = null;
        openApWizard('<?php echo addslashes(_("OpenAP")); ?>');
      };
      var confirmationMessage = '<?php echo addslashes(_("The entered AP settings are valid. Continue with the OpenAP installation?")); ?>';
      if (typeof window.openapNotify === 'function') {
        window.openapNotify({
          level: 'success',
          icon: 'fa-check-circle',
          title: '<?php echo addslashes(_("AP settings verified")); ?>',
          message: confirmationMessage,
          duration: 0,
          details: apConfirmationDetails,
          actions: [
            { label: '<?php echo addslashes(_("Install")); ?>', icon: 'fa-download', onClick: startApInstallation },
            { label: '<?php echo addslashes(_("Edit")); ?>', icon: 'fa-edit', onClick: editApSettings }
          ]
        });
      } else if (window.confirm(confirmationMessage)) {
        startApInstallation();
      } else {
        editApSettings();
      }
    });
  }

  function pollComponentOperation(jobId, label, component, action, attempt) {
    fetch('/ajax/system/components.php?job=' + encodeURIComponent(jobId), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.success) {
            throw new Error(data.message || '<?php echo addslashes(_("Unable to read operation status.")); ?>');
          }
          return data;
        });
      })
      .then(function (data) {
        if (data.state === 'queued' || data.state === 'running' || data.state === 'pending') {
          var stateProgress = data.state === 'queued' || data.state === 'pending' ? 20 : 55;
          componentMessage('<?php echo addslashes(_("Working on")); ?> ' + label + ' (' + data.state + ')...', 'info', stateProgress, data.log);
          window.setTimeout(function () { pollComponentOperation(jobId, label, component, action, attempt + 1); }, 1200);
          return;
        }
        if (data.state === 'success') {
          if (data.operation === 'remove') {
            action = 'remove';
          } else if (data.operation === 'add') {
            action = 'install';
          }
          var completedTitle = action === 'remove'
            ? '<?php echo addslashes(_("Component removed")); ?>'
            : '<?php echo addslashes(_("Component installed")); ?>';
          var completedMessage = label + (action === 'remove'
            ? ' <?php echo addslashes(_("was removed successfully.")); ?>'
            : ' <?php echo addslashes(_("was installed successfully.")); ?>');
          componentMessage(data.message || completedMessage, 'success', 100, data.log);
          try {
            window.sessionStorage.setItem(componentOperationResultKey, JSON.stringify({
              action: action,
              title: completedTitle,
              message: completedMessage,
              details: Array.isArray(data.log) ? data.log : [],
              sequence: componentNotificationLog.slice()
            }));
          } catch (notificationStorageError) {
            // The in-page operation notification remains the fallback.
          }
          prepareComponentSwitcherTransition(action);
          var currentPath = window.location.pathname || '/';
          var onIptvPage = /^\/aptv(?:\/|$)/.test(currentPath);
          var redirectPath = window.location.href;
          var redirectDelay = action === 'remove' ? 1600 : 900;
          var componentAreaRedirect = false;
          if (action === 'remove' && component === 'iptv' && onIptvPage) {
            redirectPath = '/';
            componentAreaRedirect = true;
          } else if (action === 'remove' && component === 'ap' && !onIptvPage) {
            redirectPath = '/aptv';
            componentAreaRedirect = true;
          }
          if (componentAreaRedirect) {
            try {
              window.sessionStorage.setItem('openap|auth-transition', 'login');
            } catch (areaTransitionError) {
              // The destination can still load normally when storage is unavailable.
            }
          }
          try {
            var operationRedirectUrl = new URL(redirectPath, window.location.href);
            operationRedirectUrl.searchParams.set('openap_component_result', action);
            operationRedirectUrl.searchParams.set('openap_component_label', label);
            operationRedirectUrl.searchParams.set('openap_component_reload', String(Date.now()));
            if (action === 'install') {
              operationRedirectUrl.searchParams.set('openap_component_transition', 'enter');
            }
            redirectPath = operationRedirectUrl.toString();
          } catch (redirectUrlError) {
            // The session marker remains the fallback signal.
          }
          window.setTimeout(function () { window.location.assign(redirectPath); }, redirectDelay);
          return;
        }
        throw new Error(data.message || '<?php echo addslashes(_("The component operation failed.")); ?>');
      })
      .catch(function () {
        if (attempt < 180) {
          componentMessage('<?php echo addslashes(_("The service is restarting. Waiting for the operation to finish...")); ?>', 'info', 70);
          window.setTimeout(function () { pollComponentOperation(jobId, label, component, action, attempt + 1); }, 1500);
          return;
        }
        componentMessage('<?php echo addslashes(_("The connection was interrupted. Wait a moment, then refresh the page.")); ?>', 'danger', 100);
        componentNotificationActive = false;
        componentButtons.forEach(function (item) { item.disabled = false; });
      });
  }

  form.addEventListener('submit', function (event) {
    var submitter = event.submitter || document.activeElement;

    if (submitter && submitter.name === 'logout') {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    validatePasswordMatch(true);
    if (!form.checkValidity()) {
      form.classList.add('was-validated');
      showAccountNotification(
        'error',
        '<?php echo addslashes(_("Unable to save account settings")); ?>',
        repeatedPassword.validity.customError
          ? '<?php echo addslashes(_("The new passwords do not match.")); ?>'
          : '<?php echo addslashes(_("Complete the required account fields.")); ?>',
        ['Operation: account settings save']
      );
      return;
    }

    var saveButton = form.querySelector('[name="UpdateAdminPassword"]');
    var originalText = saveButton ? saveButton.innerHTML : '';
    var formData = new FormData(form);
    var csrfField = form.querySelector('[name="csrf_token"]');
    var csrfToken = csrfField ? csrfField.value : '';
    formData.set('UpdateAdminPassword', '1');

    feedback.innerHTML = '';
    if (saveButton) {
      saveButton.disabled = true;
      saveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><?php echo _("Saving"); ?>';
    }

    fetch(form.action, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken,
        'Accept': 'application/json'
      }
    })
      .then(function (response) {
        return response.text().then(function (text) {
          if (!response.ok) {
            throw new Error(text || 'HTTP ' + response.status);
          }
          try {
            return JSON.parse(text);
          } catch (error) {
            throw new Error(text ? text.slice(0, 220) : 'Empty response');
          }
        });
      })
      .then(function (data) {
        var accountMessage = data.message || (data.success
          ? '<?php echo addslashes(_("Administrator credentials updated.")); ?>'
          : '<?php echo addslashes(_("Please verify the current password and try again.")); ?>');
        showAccountNotification(
          data.success ? 'success' : 'error',
          data.success
            ? '<?php echo addslashes(_("Account settings saved")); ?>'
            : '<?php echo addslashes(_("Unable to update account settings")); ?>',
          accountMessage,
          data.success
            ? ['Operation: account settings save']
            : ['Operation: account settings save', 'Cause: ' + accountMessage]
        );
        feedback.innerHTML = '';
        if (data.success) {
          if (username && data.username) {
            username.value = data.username;
            username.defaultValue = data.username;
          }
          form.classList.remove('was-validated');
          ['oldpass', 'newpass', 'newpassagain'].forEach(function (fieldName) {
            var field = form.querySelector('[name="' + fieldName + '"]');
            if (field) {
              field.value = '';
            }
          });
          repeatedPassword.setCustomValidity('');
          repeatedPassword.classList.remove('is-valid', 'is-invalid');
          repeatedPassword.setAttribute('aria-invalid', 'false');
        }
      })
      .catch(function (error) {
        var detail = error && error.message ? error.message : '<?php echo _("Please try again."); ?>';
        if (!showAccountNotification(
          'error',
          '<?php echo addslashes(_("Unable to update account settings")); ?>',
          detail,
          ['Operation: account settings save', 'Cause: ' + detail]
        )) {
          feedback.innerHTML = '<div class="alert alert-danger fade show" role="alert"><?php echo _("Unable to update account settings."); ?><div class="small mt-1">' + detail.replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
          }) + '</div></div>';
        }
      })
      .finally(function () {
        if (saveButton) {
          saveButton.disabled = false;
          saveButton.innerHTML = originalText;
        }
      });
  });
});
</script>
