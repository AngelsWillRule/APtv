<!-- OpenAP Login -->
<style>
  .sb-topnav,.sb-sidenav,footer.py-4,#layoutSidenav_nav,.sidebar-brand-text{display:none!important}
  #layoutSidenav_content{margin-left:0!important;padding:0!important;top:0!important;min-height:100vh!important}
  .container-fluid.mt-2{padding:0!important;margin:0!important;max-width:100%}
  main{padding:0!important}
  body.sb-nav-fixed{padding-top:0!important;background:#eef3f5}
  #layoutSidenav{margin-top:0!important}
  .openap-login-shell{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:28px;
    position:relative;
    overflow:hidden;
    background:linear-gradient(90deg,#0a5559,#126869)!important;
    color:#173233;
    font-family:"Rajdhani","DejaVu Sans",Arial,sans-serif;
  }
  .openap-login-shell::before{
    display:none!important;
  }
  .openap-login-theme-toggle{
    position:absolute;
    top:16px;
    right:16px;
    z-index:3;
  }
  .openap-login-theme-toggle .openap-theme-toggle-button{
    width:38px;
    height:38px;
    padding:0;
    border-color:rgba(255,255,255,.32);
    border-radius:9px;
    background:rgba(255,255,255,.16);
    box-shadow:0 8px 24px rgba(4,31,33,.16);
  }
  .openap-login-theme-toggle .openap-theme-toggle-button:hover,
  .openap-login-theme-toggle .openap-theme-toggle-button:focus-visible{
    border-color:rgba(255,255,255,.5);
    background:rgba(255,255,255,.26);
  }
  .openap-login-card{
    width:100%;
    max-width:392px;
    position:relative;
    z-index:1;
    background:#fff!important;
    border:1px solid rgba(18,104,105,.18);
    border-radius:8px;
    box-shadow:0 18px 50px rgba(17,45,47,.14);
    padding:34px;
  }
  .openap-login-card *{box-sizing:border-box}
  .openap-login-mark{
    width:100%;
    height:92px;
    position:relative;
    overflow:hidden;
    margin-bottom:20px;
    background:transparent;
    box-shadow:none;
  }
  .openap-login-mark img{
    position:absolute;
    top:50%;
    left:50%;
    width:140%;
    max-width:none;
    height:auto;
    transform:translate(-50%,-50%);
  }
  .openap-login-kicker{
    display:inline-flex;
    align-items:center;
    gap:8px;
    border:1px solid rgba(18,104,105,.22);
    border-radius:999px;
    padding:5px 10px;
    color:#126869;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
    background:rgba(18,104,105,.07);
    margin-bottom:14px;
  }
  .openap-login-kicker::before{
    content:"";
    width:7px;
    height:7px;
    border-radius:999px;
    background:#2d8580;
    box-shadow:0 0 0 4px rgba(45,133,128,.14);
  }
  .openap-login-title{
    margin:0;
    color:#173233;
    font-size:28px;
    line-height:1.1;
    font-weight:800;
    letter-spacing:0;
  }
  .openap-login-subtitle{
    color:#607374;
    font-size:14px;
    margin:8px 0 26px;
  }
  .openap-login-label{
    color:#496365;
    display:block;
    font-size:12px;
    font-weight:700;
    margin-bottom:7px;
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .openap-login-input{
    width:100%!important;
    background:#f7faf9!important;
    border:1px solid #d9e5e2!important;
    border-radius:8px!important;
    color:#173233!important;
    font-size:14px!important;
    min-height:46px;
    padding:11px 13px!important;
    box-shadow:none!important;
  }
  .openap-login-card .input-group{display:flex;width:100%}
  .openap-login-input:focus{
    border-color:#2d8580!important;
    box-shadow:0 0 0 3px rgba(45,133,128,.14)!important;
    background:#fff!important;
  }
  .openap-login-toggle{
    display:flex!important;
    align-items:center;
    background:#f7faf9!important;
    border:1px solid #d9e5e2!important;
    border-left:0!important;
    border-radius:0 8px 8px 0!important;
    color:#6e8584!important;
    cursor:pointer;
    padding:0 14px!important;
  }
  .openap-login-password{
    flex:1 1 auto;
    min-width:0;
    border-right:0!important;
    border-radius:8px 0 0 8px!important;
  }
  .openap-login-button{
    width:100%;
    min-height:46px;
    border:0!important;
    border-radius:8px!important;
    background:linear-gradient(135deg,#126869,#0a5559)!important;
    color:#fff!important;
    font-size:14px;
    font-weight:800;
    box-shadow:0 12px 24px rgba(18,104,105,.24);
  }
  .openap-login-button:hover,.openap-login-button:focus{
    background:linear-gradient(135deg,#157678,#0b5f63)!important;
    color:#fff!important;
  }
  .openap-login-meta{
    display:flex;
    justify-content:space-between;
    gap:8px;
    margin-top:22px;
    padding-top:18px;
    border-top:1px solid #e3ecea;
  }
  .openap-login-meta span{
    color:#6b7f80;
    font-size:11px;
    text-align:center;
    white-space:nowrap;
  }
  .openap-login-meta i{color:#2d8580}
  html[data-bs-theme="dark"] .openap-login-shell{
    background:linear-gradient(90deg,#071214,#0d4f50)!important;
  }
  html[data-bs-theme="dark"] .openap-login-card{
    background:#132123!important;
    color:#edf7f6!important;
    border-color:rgba(85,195,187,.24)!important;
    box-shadow:0 24px 74px rgba(0,0,0,.48)!important;
  }
  html[data-bs-theme="dark"] .openap-login-mark{
    background:transparent!important;
    box-shadow:none!important;
  }
  html[data-bs-theme="dark"] .openap-login-mark img{
    filter:brightness(0) invert(1);
    opacity:.94;
  }
  html[data-bs-theme="dark"] .openap-login-title{color:#edf7f6!important}
  html[data-bs-theme="dark"] .openap-login-subtitle,
  html[data-bs-theme="dark"] .openap-login-label,
  html[data-bs-theme="dark"] .openap-login-meta span{color:#a8bdbc!important}
  html[data-bs-theme="dark"] .openap-login-input,
  html[data-bs-theme="dark"] .openap-login-toggle{
    background:#0f1c1e!important;
    border-color:rgba(85,195,187,.28)!important;
    color:#edf7f6!important;
  }
  html[data-bs-theme="dark"] .openap-login-input::placeholder{
    color:#78908f!important;
  }
  html[data-bs-theme="dark"] .openap-login-input:focus{
    background:#122326!important;
    border-color:#55c3bb!important;
    box-shadow:0 0 0 3px rgba(85,195,187,.18)!important;
  }
  html[data-bs-theme="dark"] .openap-login-toggle{
    color:#a8bdbc!important;
  }
  html[data-bs-theme="dark"] .openap-login-meta{
    border-top-color:rgba(85,195,187,.18)!important;
  }
  @media (max-width:480px){
    .openap-login-shell{padding:18px}
    .openap-login-card{padding:28px 22px}
    .openap-login-title{font-size:25px}
    .openap-login-meta{flex-direction:column;align-items:flex-start}
  }

  /* Compact login card aligned with AP Ethernet and Account settings. */
  .openap-login-shell{padding:12px}
  .openap-login-card{
    max-width:430px;
    overflow:hidden;
    padding:0;
    border:1px solid rgba(18,104,105,.48);
    border-top:3px solid #126869;
    border-radius:12px;
    background:#fff!important;
    box-shadow:0 18px 48px rgba(18,104,105,.18);
  }
  .openap-login-header{
    min-height:72px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:9px 14px;
    border-bottom:1px solid rgba(255,255,255,.16);
    background:linear-gradient(90deg,#0b4e50,#16847f);
    color:#fff;
  }
  .openap-login-header .openap-login-mark{
    width:126px;
    height:48px;
    flex:0 0 126px;
    margin:0;
  }
  .openap-login-header .openap-login-mark img{
    width:132%;
    filter:brightness(0) invert(1);
  }
  .openap-login-header-copy{min-width:0}
  .openap-login-header .openap-login-title{
    color:#fff!important;
    font-size:16px;
    font-weight:800;
    letter-spacing:.35px;
    text-transform:uppercase;
  }
  .openap-login-header .openap-login-subtitle{
    margin:3px 0 0;
    color:rgba(255,255,255,.76)!important;
    font-size:10px;
  }
  .openap-login-header-icon{
    width:34px;
    height:34px;
    display:grid;
    flex:0 0 34px;
    place-items:center;
    margin-left:auto;
    border:1px solid rgba(18,104,105,.24);
    border-radius:9px;
    background:#fff;
    color:#1e3a8a;
    font-size:14px;
  }
  .openap-login-body{padding:14px}
  .openap-login-body .openap-login-kicker{margin-bottom:10px;padding:4px 8px;font-size:9px}
  .openap-login-body .mb-3{margin-bottom:9px!important}
  .openap-login-body .mb-4{margin-bottom:11px!important}
  .openap-login-label{margin-bottom:4px;font-size:10px}
  .openap-login-input{
    min-height:38px;
    padding:7px 9px!important;
    border-radius:7px!important;
    font-size:12px!important;
  }
  .openap-login-card .input-group{
    display:grid;
    grid-template-columns:minmax(0,1fr) 42px;
    gap:7px;
  }
  .openap-login-password{
    border:1px solid #d9e5e2!important;
    border-radius:7px!important;
  }
  .openap-login-toggle{
    width:42px;
    min-width:42px;
    min-height:38px;
    display:grid!important;
    place-items:center;
    padding:0!important;
    border:1px solid #d9e5e2!important;
    border-radius:7px!important;
    background:#f7faf9!important;
    color:#2d8580!important;
    cursor:pointer;
    touch-action:manipulation;
  }
  .openap-login-toggle:hover,.openap-login-toggle:focus-visible{
    border-color:#2d8580!important;
    background:#eef7f5!important;
    color:#126869!important;
    outline:2px solid rgba(45,133,128,.18);
    outline-offset:1px;
  }
  .openap-login-toggle i{margin:0!important}
  .openap-login-button{min-height:38px;font-size:12px;box-shadow:none}
  .openap-login-body .alert{margin-bottom:9px!important;padding:7px 9px!important;font-size:10px!important}
  html[data-bs-theme="dark"] .openap-login-card{background:var(--surface)!important}
  html[data-bs-theme="dark"] .openap-login-header .openap-login-mark{background:transparent!important;box-shadow:none!important}
  html[data-bs-theme="dark"] .openap-login-toggle{
    border-color:rgba(85,195,187,.28)!important;
    background:#0f1c1e!important;
    color:#55c3bb!important;
  }
  @media (max-width:480px){
    .openap-login-shell{padding-right:20px;padding-left:20px}
    .openap-login-card{width:100%;padding:0}
    .openap-login-header{min-height:60px;padding:7px 10px;gap:8px}
    .openap-login-header .openap-login-mark{width:104px;height:42px;flex-basis:104px}
    .openap-login-header-icon{width:32px;height:32px;flex-basis:32px}
    .openap-login-body{padding:11px}
    .openap-login-toggle{width:44px;min-width:44px;min-height:40px}
  }

  /* Standalone symbol above the login prompt. */
  .openap-login-shell{
    flex-direction:column;
    gap:10px;
  }
  .openap-login-emblem{
    width:clamp(160px,42vw,200px);
    height:clamp(160px,42vw,200px);
    position:relative;
    z-index:1;
    flex:0 0 auto;
    overflow:hidden;
    transform:translateY(-75px);
  }
  .openap-login-emblem img{
    position:absolute;
    width:425%;
    height:auto;
    max-width:none;
    left:-72%;
    top:-74%;
    filter:brightness(0) invert(1);
  }
  /* APtv logo trial: use the complete transparent mark instead of the
     cropped OpenAP sprite used by the original login artwork. */
  .openap-login-emblem img{
    position:relative;
    inset:auto;
    display:block;
    width:100%;
    height:100%;
    max-width:100%;
    object-fit:contain;
    filter:none;
  }
  .openap-login-brand-below{display:none!important}
  .openap-login-card{transform:translateY(-75px)}
  @keyframes openapLoginErrorShakeCard{
    0%,100%{transform:translate3d(0,-75px,0)}
    18%{transform:translate3d(-10px,-75px,0)}
    36%{transform:translate3d(9px,-75px,0)}
    54%{transform:translate3d(-7px,-75px,0)}
    72%{transform:translate3d(5px,-75px,0)}
    88%{transform:translate3d(-2px,-75px,0)}
  }
  @keyframes openapLoginErrorShakeEmblem{
    0%,100%{transform:translate3d(0,-75px,0)}
    18%{transform:translate3d(-10px,-75px,0)}
    36%{transform:translate3d(9px,-75px,0)}
    54%{transform:translate3d(-7px,-75px,0)}
    72%{transform:translate3d(5px,-75px,0)}
    88%{transform:translate3d(-2px,-75px,0)}
  }
  .openap-login-shell.is-login-error .openap-login-card{
    animation:openapLoginErrorShakeCard .52s cubic-bezier(.36,.07,.19,.97) both;
  }
  .openap-login-shell.is-login-error .openap-login-emblem{
    animation:openapLoginErrorShakeEmblem .52s cubic-bezier(.36,.07,.19,.97) both;
  }
  @media (prefers-reduced-motion:reduce){
    .openap-login-shell.is-login-error .openap-login-card,
    .openap-login-shell.is-login-error .openap-login-emblem{animation:none}
  }
  .openap-login-brand-below{
    position:relative;
    z-index:1;
    display:flex;
    flex-direction:column;
    align-items:center;
    margin-top:14px;
    transform:translateY(-75px);
  }
  .openap-login-brand-crop{
    position:relative;
    overflow:hidden;
  }
  .openap-login-brand-crop img{
    position:absolute;
    width:215%;
    height:auto;
    max-width:none;
    left:-91%;
    filter:brightness(0) invert(1);
  }
  .openap-login-brand-name{
    width:clamp(190px,52vw,230px);
    aspect-ratio:6.29;
  }
  .openap-login-brand-name img{top:-316%}
  .openap-login-brand-subtitle{
    width:clamp(190px,52vw,230px);
    aspect-ratio:12;
    margin-top:15px;
  }
  .openap-login-brand-subtitle img{top:-820%}
  @media (max-width:480px){
    .openap-login-shell{gap:6px}
    .openap-login-theme-toggle{top:10px;right:10px}
    .openap-login-emblem{
      width:clamp(140px,42vw,176px);
      height:clamp(140px,42vw,176px);
    }
  }

  /* Full HD is the visual reference. Scale the two login composition units
     together so logo, fields, controls and their established hierarchy stay
     intact on QHD and UHD displays. */
  @media (min-width:2400px) and (min-height:1350px){
    .openap-login-shell{gap:13.3333px}
    .openap-login-emblem,.openap-login-card,.openap-login-theme-toggle{zoom:1.3333333333}
  }
  @media (min-width:3600px) and (min-height:2025px){
    .openap-login-shell{gap:20px}
    .openap-login-emblem,.openap-login-card,.openap-login-theme-toggle{zoom:2}
  }
</style>
<div class="openap-login-shell<?php echo !empty($loginFailed) ? ' is-login-error' : ''; ?>">
<div class="openap-login-emblem" aria-hidden="true">
  <img src="app/img/aptv-login-logo-test.png?v=<?php echo filemtime('app/img/aptv-login-logo-test.png'); ?>" alt="">
</div>
<div class="openap-login-card">
  <div class="openap-login-body">

  <?php if ($sessionExpired): ?>
    <div class="alert alert-warning openap-persistent-alert py-2 mb-3" role="status" style="font-size:13px;text-align:center">
      Your session has expired due to inactivity. Please sign in again.
    </div>
  <?php endif; ?>

  <form id="admin-login-form" action="login" method="POST" class="needs-validation" novalidate>
    <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
    <input type="hidden" name="login-auth">
    <input type="hidden" id="redirect-url" name="redirect-url" value="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="mb-3">
      <label class="openap-login-label" for="username">Username</label>
      <input type="text" class="form-control openap-login-input" id="username" name="username" placeholder="Enter username" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" required>
    </div>
    <div class="mb-4">
      <label class="openap-login-label" for="password">Password</label>
      <div class="input-group">
        <input type="password" class="form-control openap-login-input openap-login-password" id="password" name="password" placeholder="Enter password" autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="go" required>
        <button type="button" class="js-toggle-password openap-login-toggle" data-bs-target="[name=password]" data-toggle-with="fas fa-eye-slash" aria-label="Show or hide password">
          <i class="fas fa-eye"></i>
        </button>
      </div>
    </div>
    <div id="openapLoginFeedback" aria-live="polite">
      <?php if ($status): ?>
        <div class="alert alert-danger py-2 mb-3" style="font-size:13px;text-align:center"><?php echo htmlspecialchars($status, ENT_QUOTES); ?></div>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary openap-login-button">Sign in</button>
</form>
  </div>
</div>
<div class="openap-login-brand-below" aria-hidden="true">
  <div class="openap-login-brand-crop openap-login-brand-name">
    <img src="app/img/openap-header-logo.png?v=<?php echo filemtime('app/img/openap-header-logo.png'); ?>" alt="">
  </div>
  <div class="openap-login-brand-crop openap-login-brand-subtitle">
    <img src="app/img/openap-header-logo.png?v=<?php echo filemtime('app/img/openap-header-logo.png'); ?>" alt="">
  </div>
</div>
</div>
