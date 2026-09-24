<?php
/**
 * =============================================================
 *  踏雪笑傲 · 頂部導航右上角「會員登入 / 註冊」元件
 *  使用：於各前台頁面 <header> 右側 include 本檔
 *        <?php include __DIR__ . '/site_auth.php'; ?>
 *  依賴：assets/css/site-ui.css、assets/site-ui.js、site_login.php
 * =============================================================
 */

if (!isset($__sauAuthed)) {
    $__sauAuthed = (session_status() === PHP_SESSION_ACTIVE) && !empty($_SESSION['username']);
}
$__sauName = $__sauAuthed ? (string)$_SESSION['username'] : '';
$__sauIsGm = $__sauAuthed ? !empty($_SESSION['is_gm']) : false;

if (!function_exists('sau_initial')) {
    function sau_initial($name)
    {
        if ($name === '') { return '俠'; }
        if (function_exists('mb_substr')) { return mb_substr($name, 0, 1, 'UTF-8'); }
        return substr($name, 0, 1);
    }
}

if (empty($_SESSION['sau_form_token'])) {
    $_SESSION['sau_form_token'] = bin2hex(random_bytes(32));
}
$__sauToken = $_SESSION['sau_form_token'];
?>
<div class="sau">
  <?php if ($__sauAuthed): ?>
    <div class="sau-user" id="sauUser">
      <button type="button" class="sau-user__btn" id="sauUserBtn" aria-haspopup="true" aria-expanded="false">
        <span class="sau-user__avatar"><?= htmlspecialchars(sau_initial($__sauName), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="sau-user__meta">
          <span class="sau-user__name"><?= htmlspecialchars($__sauName, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="sau-user__role"><?= $__sauIsGm ? 'GM' : '俠客' ?></span>
        </span>
        <svg class="sau-user__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <div class="sau-menu" role="menu">
        <div class="sau-menu__head">
          <b><?= htmlspecialchars($__sauName, ENT_QUOTES, 'UTF-8') ?></b>
          <span>江湖身分：<?= $__sauIsGm ? '管理者' : '俠客' ?></span>
        </div>
        <a class="sau-menu__item" href="../home.php" role="menuitem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" stroke-linecap="round" stroke-linejoin="round"/></svg>
          會員中心
        </a>
        <a class="sau-menu__item" href="../usercontrol.php" role="menuitem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0" stroke-linecap="round"/></svg>
          帳號設定
        </a>
        <a class="sau-menu__item" href="../recharge.php" role="menuitem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20" stroke-linecap="round"/></svg>
          儲值中心
        </a>
        <div class="sau-menu__sep"></div>
        <a class="sau-menu__item sau-menu__item--danger" href="../logout.php" role="menuitem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 12H3m0 0l4-4m-4 4l4 4" stroke-linecap="round" stroke-linejoin="round"/><path d="M11 5V4a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2h-5a2 2 0 0 1-2-2v-1" stroke-linecap="round" stroke-linejoin="round"/></svg>
          安全登出
        </a>
      </div>
    </div>
  <?php else: ?>
    <button type="button" class="sau-btn sau-btn--ghost" id="sauLoginOpen">
      <svg class="sau-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 17l5-5-5-5M15 12H3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      登入
    </button>
    <a class="sau-btn sau-btn--primary" href="../index.php">
      <svg class="sau-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6" stroke-linecap="round"/></svg>
      註冊帳號
    </a>
  <?php endif; ?>
</div>

<?php if (!$__sauAuthed): ?>
<div class="sau-modal" id="sauModal" aria-hidden="true">
  <div class="sau-modal__backdrop" data-sau-close></div>
  <div class="sau-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sauModalTitle">
    <button type="button" class="sau-modal__close" data-sau-close aria-label="關閉">&times;</button>
    <div class="sau-modal__brand">Snow Wanderer</div>
    <h3 class="sau-modal__title" id="sauModalTitle">會員登入</h3>
    <p class="sau-modal__hint">踏雪笑傲帳號 · 子帳號亦可由此登入</p>

    <div class="sau-alert" id="sauAlert" role="alert"></div>

    <form id="sauLoginForm" action="site_login.php" method="post" novalidate>
      <input type="hidden" name="form_token" id="sauToken" value="<?= htmlspecialchars($__sauToken, ENT_QUOTES, 'UTF-8') ?>">
      <div class="sau-field">
        <label for="sauLogin">帳號</label>
        <input type="text" name="login" id="sauLogin" autocomplete="username" placeholder="請輸入帳號" required>
      </div>
      <div class="sau-field sau-field--pass">
        <label for="sauPasswd">密碼</label>
        <input type="password" name="passwd" id="sauPasswd" autocomplete="current-password" placeholder="請輸入密碼" required>
        <button type="button" class="sau-eye" id="sauEye" aria-label="顯示密碼">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <label class="sau-remember">
        <input type="checkbox" id="sauRemember" name="remember">
        記住我 · 保持登入
      </label>
      <button type="submit" class="sau-btn sau-btn--primary sau-btn--block" id="sauSubmit">登入江湖</button>
    </form>

    <div class="sau-modal__foot">
      <a href="../login.php">前往登入頁</a>
      <span class="dot">·</span>
      <a href="../index.php">註冊帳號</a>
    </div>
  </div>
</div>
<?php endif; ?>
