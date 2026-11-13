<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$user = null;
try { $user = current_user(); }
catch (Throwable $t) { http_response_code(500); exit('Veritabanına bağlanılamadı. config.php ayarlarını kontrol edin veya setup.php ile kurulumu tamamlayın.'); }
$v = '28';
$marka = 'Cari Takip'; $logo = null; $logoKoyu = 'normal';
try { if ($user) ensure_schema(); $marka = marka_ad(); $logo = logo_url(); $logoKoyu = ayar('logo_koyu') ?: 'normal'; } catch (Throwable $t) { /* ayarlar okunamazsa varsayılan ad */ }
?><!doctype html>
<html lang="tr" data-logo-koyu="<?= e($logoKoyu) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= e($marka) ?></title>
<?php if ($logo): ?><link rel="icon" href="<?= e($logo) ?>"><?php endif; ?>
<link rel="manifest" href="api.php?a=manifest">
<meta name="theme-color" content="#f3f4f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0c1018" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(mb_substr(trim((string)ayar_guvenli('uygulama_ad')) ?: 'Cari', 0, 12)) ?>">
<link rel="apple-touch-icon" href="api.php?a=ikon&amp;s=180&amp;v=<?= e(ayar_guvenli('logo_surum')) ?>">
<script>if ("serviceWorker" in navigator && (location.protocol === "https:" || location.hostname === "localhost" || location.hostname === "127.0.0.1")) addEventListener("load", () => navigator.serviceWorker.register("api.php?a=sw", { scope: "./" }).catch(() => {}));</script>
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<script>try{var t=localStorage.getItem("cari-tema");if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t)}catch(e){}</script>
</head>
<?php if (!$user): ?>
<body class="auth">
<main class="auth-box">
  <?= marka_html() ?>
  <h1><?= e($marka) ?></h1>
  <p class="muted small">Devam etmek için giriş yapın.</p>
  <form id="login" class="form" style="grid-template-columns:1fr">
    <label class="f">Kullanıcı adı<input id="l-user" name="kullanici" autocomplete="username" required autofocus></label>
    <label class="f">Şifre<input id="l-pass" name="sifre" type="password" autocomplete="current-password" required></label>
    <div class="err" id="l-err" hidden></div>
    <button class="btn primary" type="submit">Giriş yap</button>
  </form>
</main>
<script>
document.getElementById('login').addEventListener('submit', async e => {
  e.preventDefault();
  const err = document.getElementById('l-err'); err.hidden = true;
  const btn = e.target.querySelector('button'); btn.disabled = true;
  try {
    const r = await fetch('api.php?a=login', {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin',
      body: JSON.stringify({kullanici: document.getElementById('l-user').value, sifre: document.getElementById('l-pass').value})});
    const j = await r.json();
    if (j.ok) { location.reload(); return; }
    err.textContent = j.error || 'Giriş yapılamadı.'; err.hidden = false;
  } catch (x) { err.textContent = 'Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.'; err.hidden = false; }
  btn.disabled = false;
});
</script>
</body>
<?php else: ?>
<body>
<div class="app">
  <aside class="rail">
    <div class="brand" id="brand"><?= marka_html() ?><small><?= e($marka) ?> · sürüm <?= $v ?></small></div>
    <nav class="nav" id="nav"></nav>
    <div class="who">
      <span class="av" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string)$user['ad']) ?: (string)$user['kullanici'], 0, 1))) ?></span><span><?= e($user['ad']) ?><br><span class="muted small"><?= $user['rol'] === 'admin' ? 'Yönetici' : 'Salt okunur' ?></span></span>
      <button class="btn ghost" id="logout">Çıkış</button>
    </div>
  </aside>
  <main id="main"><div class="empty"><strong>Kayıtlar yükleniyor</strong></div></main>
</div>
<nav class="altbar" id="altbar" aria-label="Ana menü"></nav>
<div id="modal-root"></div>
<div id="toast-root"></div>
<script>window.CARI_SURUM = <?= json_encode($v) ?>;</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
<?php endif; ?>
</html>
