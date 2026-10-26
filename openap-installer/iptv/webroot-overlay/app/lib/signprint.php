<?php

require_once '../../includes/config.php';
require_once '../../includes/defaults.php';

// prevent direct file access
if (!isset($_SERVER['HTTP_REFERER'])) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$hostapd = parse_ini_file(OPENAP_HOSTAPD_CONFIG, false, INI_SCANNER_RAW);
$ssid = $hostapd['ssid'];
$password = $hostapd['wpa_psk'] ?? $hostapd['wpa_passphrase'] ?? '';

?>

<html>
<head>
  <title><?php echo _("Printable Wi-Fi sign"); ?></title>
  <!-- Bootstrap Core CSS -->
  <link href="../../dist/bootstrap/css/bootstrap.css" rel="stylesheet">

  <!-- SB Admin CSS -->
  <link href="../../dist/sb-admin/css/styles.css" rel="stylesheet">

  <!-- Custom Fonts -->
  <link href="../../dist/font-awesome/css/all.min.css" rel="stylesheet" type="text/css">
</head>
<body id="page-top">
  <div id="wrapper">
    <div id="content-wrapper" class="d-flex flex-column">
      <div id="container">
        <div class="row text-center">
          <div class="col">
            <div class="mt-5 mb-5">
              <h2><i class="fas fa-wifi"></i> <?php echo _("Wi-Fi Connect"); ?></h2>
            </div>
          </div>
        </div><!-- /row -->

        <div class="row">
          <div class="col"></div>
          <div class="col-5">
            <img src="../img/wifi-qr-code.php" class="figure-img img-fluid" alt="OpenAP WiFi QR code" style="width:100%;">
          </div>
          <div class="col"></div>
        </div><!-- /row -->

        <div class="row text-center">
          <div class="col"></div>
          <div class="col-8">
            <div class="mt-4">
              <div><?php echo _("To connect with your phone or tablet, scan the QR code above with your camera app."); ?></div>
              <div><?php echo _("For other devices, use the login credentials below."); ?></div>
            </div>
          </div>
          <div class="col"></div>
        </div><!-- /row -->

        <div class="row text-center">
          <div class="col"></div>
          <div class="col-8">
            <div class="mt-4">
              <?php echo _("Network"); ?>
              <h3 class="mb-3"><?php echo $ssid ?></h3> 
              <?php echo $password === '' ? _("Security") : _("Password"); ?>
              <h3 class="mb-5"><?php echo htmlspecialchars($password === '' ? _("None (open network)") : $password, ENT_QUOTES); ?></h3>
            </div>
          </div>
          <div class="col"></div>
        </div><!-- /row -->

      </div><!-- /content -->
    </div><!-- /content-wrapper -->
  </div><!-- /page wrapper -->
</body>
</html>
