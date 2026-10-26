<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo _("OpenAP Exception"); ?></title>
    <link href="dist/bootstrap/css/bootstrap.css" rel="stylesheet">
    <link href="dist/sb-admin/css/styles.css" rel="stylesheet">
    <link rel="shortcut icon" type="image/png" href="app/icons/openap-favicon-96x96.png">
  </head>
  <body id="page-top">
    <div id="wrapper">
      <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
          <div class="row">
            <div class="col-lg-12">
              <p class="text-light bg-danger ps-3 p-1"><?php echo _("OpenAP Exception"); ?></p>
            </div>
            <div class="col-lg-12 ms-3">
              <h3 class="mt-2"><?php echo _("An exception occurred"); ?></h3>
              <h5>Stack trace:</h5>
              <pre><?php print_r($trace); ?></pre>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>
