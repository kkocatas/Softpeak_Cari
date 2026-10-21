<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$user = null;
try { $user = current_user(); }
catch (Throwable $t) { http_response_code(500); exit('Veritabanına bağlanılamadı. config.php ayarlarını kontrol edin veya setup.php ile kurulumu tamamlayın.'); }
$v = '15';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Softpeak Cari</title>
<link rel="icon" href="assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<?php if (!$user): ?>
<body class="auth">
<main class="auth-box">
  <img class="logo" src="assets/logo.png" alt="Softpeak">
  <h1>Cari takip</h1>
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
    <div class="brand"><img class="logo" src="assets/logo.png" alt="Softpeak"><small>Cari takip · sürüm <?= $v ?></small></div>
    <nav class="nav" id="nav"></nav>
    <div class="who">
      <span><?= e($user['ad']) ?><br><span class="muted small"><?= $user['rol'] === 'admin' ? 'Yönetici' : 'Salt okunur' ?></span></span>
      <button class="btn ghost" id="logout">Çıkış</button>
    </div>
  </aside>
  <main id="main"><div class="empty"><strong>Kayıtlar yükleniyor</strong></div></main>
</div>
<div id="modal-root"></div>
<div id="toast-root"></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
<?php endif; ?>
</html>
