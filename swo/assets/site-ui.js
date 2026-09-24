/* =============================================================
   踏雪笑傲 · 共用會員登入元件腳本
   Snow Wanderer Online — shared auth UI script
   ============================================================= */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    /* ---------------- 登入彈出視窗 ---------------- */
    var modal = document.getElementById('sauModal');
    var loginBtn = document.getElementById('sauLoginOpen');
    var lastFocus = null;

    /* 頂部導覽含 backdrop-filter，會成為 fixed 子元素的 containing block，
       將彈出視窗移出 header 至 <body>，確保全螢幕遮罩正常。 */
    if (modal && modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }

    function openModal() {
      if (!modal) return;
      lastFocus = document.activeElement;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      var first = modal.querySelector('input[name="login"]');
      if (first) { setTimeout(function () { first.focus(); }, 120); }
    }

    function closeModal() {
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
    }

    if (loginBtn) { loginBtn.addEventListener('click', openModal); }
    if (modal) {
      modal.querySelectorAll('[data-sau-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
        closeMenu();
      }
    });

    /* ---------------- 密碼顯示切換 ---------------- */
    var eye = document.getElementById('sauEye');
    var pass = document.getElementById('sauPasswd');
    if (eye && pass) {
      eye.addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eye.setAttribute('aria-label', show ? '隱藏密碼' : '顯示密碼');
      });
    }

    /* ---------------- 記住帳號 ---------------- */
    var loginInput = document.getElementById('sauLogin');
    var rememberBox = document.getElementById('sauRemember');
    try {
      var saved = localStorage.getItem('sau_remember_login');
      if (saved && loginInput) {
        loginInput.value = saved;
        if (rememberBox) { rememberBox.checked = true; }
      }
    } catch (e) {}

    /* ---------------- 登入送出（AJAX） ---------------- */
    var form = document.getElementById('sauLoginForm');
    var alertBox = document.getElementById('sauAlert');
    var submitBtn = document.getElementById('sauSubmit');

    function showAlert(type, message) {
      if (!alertBox) return;
      alertBox.className = 'sau-alert is-show ' + (type === 'ok' ? 'sau-alert--ok' : 'sau-alert--error');
      alertBox.textContent = message;
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!loginInput || !pass) return;
        var login = loginInput.value.trim();
        var passwd = pass.value;
        if (!login || !passwd) {
          showAlert('error', '請輸入帳號與密碼。');
          return;
        }

        try {
          if (rememberBox && rememberBox.checked) {
            localStorage.setItem('sau_remember_login', login);
          } else {
            localStorage.removeItem('sau_remember_login');
          }
        } catch (err) {}

        var original = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="sau-spin"></span><span>驗證中…</span>';
        }
        showAlert('ok', '正在驗證身分，請稍候…');

        var body = new FormData(form);

        fetch(form.getAttribute('action') || 'site_login.php', {
          method: 'POST',
          body: body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (res) { return res.json().catch(function () { return { ok: false, message: '系統回應異常，請稍後再試。' }; }); })
          .then(function (data) {
            if (data && data.ok) {
              showAlert('ok', data.message || '登入成功，正在進入江湖…');
              setTimeout(function () { window.location.href = (data.redirect || '../home.php'); }, 550);
            } else {
              showAlert('error', (data && data.message) || '登入失敗，請確認帳號密碼。');
              if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = original; }
              var tokenField = document.getElementById('sauToken');
              if (tokenField && data && data.token) { tokenField.value = data.token; }
            }
          })
          .catch(function () {
            showAlert('error', '網路連線異常，請稍後再試。');
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = original; }
          });
      });
    }

    /* ---------------- 使用者下拉選單 ---------------- */
    var userWrap = document.getElementById('sauUser');
    var userBtn = document.getElementById('sauUserBtn');

    function closeMenu() {
      if (userWrap) { userWrap.classList.remove('is-open'); }
      if (userBtn) { userBtn.setAttribute('aria-expanded', 'false'); }
    }

    if (userBtn && userWrap) {
      userBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = userWrap.classList.toggle('is-open');
        userBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function (e) {
        if (!userWrap.contains(e.target)) { closeMenu(); }
      });
    }
  });
})();
