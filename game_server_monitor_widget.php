<?php
/**
 * =============================================================
 *  踏雪笑傲 · 服務程序監控小工具（共用元件）
 *  -------------------------------------------------------------
 *  於後台首頁（admin/gmpanel.php）與前台會員中心（home.php）共用。
 *  提供：
 *    - 各服務即時狀態（LED、程序數、PID）
 *    - 單一服務快捷開關（啟動／停止）
 *    - 整體伺服器快捷啟動／關閉
 *    - 自動輪詢（免重整）、確認對話框、Toast 提示
 *  資料來源：admin/game_server_control_api.php（需 GM + CSRF）。
 *
 *  使用方式（須先登入 GM；未登入者不會輸出任何內容）：
 *    $monitorServices = [['key'=>'authd','label'=>'帳號數據服務','icon'=>'fa-key'], ...];
 *    $monitorApi      = 'game_server_control_api.php';   // 相對於目前頁面
 *    $monitorCsrf     = $_SESSION['game_control_csrf'];
 *    $monitorTarget   = 'root@1.2.3.4';                   // 可空
 *    $monitorEnabled  = true;                             // 控制功能是否啟用
 *    $monitorVariant  = 'dark' | 'light';
 *    $monitorControlHref = 'game_server_control_admin.php'; // 可空
 *    include __DIR__ . '/game_server_monitor_widget.php';
 * =============================================================
 */

if (!isset($_SESSION['gm_username']) || $_SESSION['gm_username'] === '') {
    return; // 僅 GM 可見
}

$monitorServices     = (array)($monitorServices ?? []);
$monitorApi          = (string)($monitorApi ?? 'game_server_control_api.php');
$monitorCsrf         = (string)($monitorCsrf ?? '');
$monitorTarget       = (string)($monitorTarget ?? '');
$monitorEnabled      = (bool)($monitorEnabled ?? true);
$monitorVariant      = ((string)($monitorVariant ?? 'dark') === 'light') ? 'light' : 'dark';
$monitorControlHref  = (string)($monitorControlHref ?? '');
$monitorTitle        = (string)($monitorTitle ?? '服務程序監控');
$monitorSubtitle     = (string)($monitorSubtitle ?? 'PROCESS MONITOR · LIVE');
$monitorId           = 'svcmon_' . substr(md5(uniqid('', true)), 0, 6);

$monServicesJson = json_encode($monitorServices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$monSvcCount     = count($monitorServices);
$monVariantClass = ($monitorVariant === 'light') ? 'svcmon--light' : 'svcmon--dark';
?>
<style>
.svcmon{
  --sm-radius:18px;
  position:relative;
  border-radius:var(--sm-radius);
  font-family:'Manrope','Noto Serif TC',sans-serif;
  color:var(--sm-txt);
  margin:0;
}
.svcmon--dark{
  --sm-bg:linear-gradient(180deg,rgba(19,28,43,.94),rgba(11,16,26,.97));
  --sm-panel:rgba(11,17,28,.55);
  --sm-panel2:rgba(19,28,43,.7);
  --sm-line:rgba(51,65,85,.75);
  --sm-line2:rgba(71,85,105,.6);
  --sm-txt:#e2e8f0;--sm-mut:#94a3b8;--sm-mut2:#64748b;
  --sm-accent:#38bdf8;--sm-accent2:#0284c7;
  --sm-on:#34d399;--sm-off:#64748b;--sm-rose:#fb7185;--sm-amber:#fbbf24;
  --sm-shadow:0 30px 70px -34px rgba(0,0,0,.92);
}
.svcmon--light{
  --sm-bg:linear-gradient(180deg,rgba(255,255,255,.97),rgba(241,245,249,.94));
  --sm-panel:rgba(248,250,252,.92);
  --sm-panel2:rgba(255,255,255,.95);
  --sm-line:rgba(203,213,225,.95);
  --sm-line2:rgba(203,213,225,.7);
  --sm-txt:#0f172a;--sm-mut:#475569;--sm-mut2:#94a3b8;
  --sm-accent:#0284c7;--sm-accent2:#0369a1;
  --sm-on:#059669;--sm-off:#94a3b8;--sm-rose:#e11d48;--sm-amber:#d97706;
  --sm-shadow:0 26px 60px -36px rgba(15,23,42,.45);
}
.svcmon *{box-sizing:border-box}
.svcmon__frame{
  position:relative;border-radius:var(--sm-radius);overflow:hidden;
  background:var(--sm-bg);border:1px solid var(--sm-line);box-shadow:var(--sm-shadow);
}
.svcmon__frame::before{
  content:"";position:absolute;inset:0 0 auto 0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(56,189,248,.55),transparent);
}
.svcmon__glow{position:absolute;top:-90px;right:-60px;width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(56,189,248,.22),transparent 70%);pointer-events:none;filter:blur(6px)}
.svcmon__glow--2{left:-70px;top:auto;bottom:-110px;right:auto;background:radial-gradient(circle,rgba(167,139,250,.18),transparent 70%)}

.svcmon__head{position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:9px 13px;padding:11px 14px;border-bottom:1px solid var(--sm-line)}
.svcmon__title{display:flex;align-items:center;gap:10px;min-width:0}
.svcmon__badge{width:33px;height:33px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex:none;
  color:#fff;font-size:14px;background:linear-gradient(135deg,#38bdf8,#0369a1);box-shadow:0 8px 18px -11px rgba(56,189,248,.9),inset 0 1px 0 rgba(255,255,255,.3)}
.svcmon--light .svcmon__badge{background:linear-gradient(135deg,#0ea5e9,#0369a1)}
.svcmon__title b{display:block;font-family:'Noto Serif TC',serif;font-size:13.5px;letter-spacing:.04em;line-height:1.2}
.svcmon__sub{display:block;font-size:9px;letter-spacing:.22em;text-transform:uppercase;color:var(--sm-mut2);margin-top:2px;font-family:ui-monospace,Consolas,monospace}
.svcmon__headmeta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.svcmon__pill{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:600;padding:5px 11px;border-radius:999px;
  border:1px solid var(--sm-line2);background:var(--sm-panel);white-space:nowrap}
.svcmon__pill .dot{width:8px;height:8px;border-radius:50%;background:var(--sm-off);flex:none}
.svcmon__pill.is-on{border-color:rgba(52,211,153,.5);color:var(--sm-on);background:rgba(6,78,59,.18)}
.svcmon--light .svcmon__pill.is-on{background:rgba(16,185,129,.1)}
.svcmon__pill.is-on .dot{background:var(--sm-on);box-shadow:0 0 0 4px rgba(52,211,153,.18);animation:smPulse 1.8s ease-in-out infinite}
.svcmon__pill.is-err{border-color:rgba(251,113,133,.5);color:var(--sm-rose);background:rgba(76,5,25,.2)}
.svcmon--light .svcmon__pill.is-err{background:rgba(225,29,72,.08)}
.svcmon__pill.is-err .dot{background:var(--sm-rose)}
.svcmon__pill.is-off{color:var(--sm-mut)}
@keyframes smPulse{0%,100%{opacity:1}50%{opacity:.35}}
.svcmon__host{font-size:10px;font-family:ui-monospace,Consolas,monospace;color:var(--sm-mut);padding:5px 10px;border-radius:8px;border:1px dashed var(--sm-line2);background:var(--sm-panel);white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis}

.svcmon__actions{margin-left:auto;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.svcmon__btn{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;font-family:inherit;
  border-radius:9px;padding:6px 11px;cursor:pointer;transition:.16s;border:1px solid var(--sm-line2);
  background:var(--sm-panel2);color:var(--sm-txt);text-decoration:none;line-height:1;white-space:nowrap}
.svcmon__btn:hover{transform:translateY(-1px);border-color:var(--sm-accent)}
.svcmon__btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.svcmon__btn--go{background:linear-gradient(135deg,#059669,#047857);border-color:#047857;color:#fff}
.svcmon__btn--stop{background:linear-gradient(135deg,#e11d48,#9f1239);border-color:#9f1239;color:#fff}
.svcmon__btn--ghost{background:transparent}
.svcmon__auto{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--sm-mut);cursor:pointer;user-select:none;padding:5px 9px;border-radius:8px;border:1px solid var(--sm-line2);background:var(--sm-panel)}
.svcmon__auto input{accent-color:var(--sm-accent);cursor:pointer}

/* 單欄直列清單（精簡版） */
.svcmon__grid{position:relative;display:flex;flex-direction:column;gap:5px;padding:10px 12px}
.svcmon__card{position:relative;border:1px solid var(--sm-line);border-radius:10px;background:var(--sm-panel);padding:6px 11px;display:flex;flex-direction:row;align-items:center;gap:12px;overflow:hidden;transition:.2s}
.svcmon__card::after{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--sm-off);opacity:.35;transition:.2s}
.svcmon__card.is-on{border-color:rgba(52,211,153,.4)}
.svcmon__card.is-on::after{background:var(--sm-on);opacity:1}
.svcmon__card.is-offline{opacity:.72}
.svcmon__card.is-flash{box-shadow:0 0 0 2px rgba(52,211,153,.4)}
.svcmon__card.is-flash-rose{box-shadow:0 0 0 2px rgba(251,113,133,.4)}
.svcmon__ctop{display:flex;align-items:center;gap:9px;min-width:0;flex:1 1 auto}
.svcmon__cico{width:27px;height:27px;border-radius:8px;flex:none;display:flex;align-items:center;justify-content:center;font-size:11.5px;
  color:var(--sm-mut);background:var(--sm-panel2);border:1px solid var(--sm-line)}
.svcmon__card.is-on .svcmon__cico{color:var(--sm-on);border-color:rgba(52,211,153,.4);background:rgba(6,78,59,.16)}
.svcmon--light .svcmon__card.is-on .svcmon__cico{background:rgba(16,185,129,.1)}
.svcmon__cname{font-size:11.5px;font-weight:700;font-family:'Noto Serif TC',serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25}
.svcmon__ckey{font-size:9.5px;color:var(--sm-mut2);font-family:ui-monospace,Consolas,monospace;line-height:1.2}
.svcmon__cstate{display:flex;align-items:center;justify-content:flex-end;gap:12px;font-size:10.5px;color:var(--sm-mut);flex:0 0 auto;min-width:112px}
.svcmon__led{display:inline-flex;align-items:center;gap:6px;font-weight:600}
.svcmon__led i{width:7px;height:7px;border-radius:50%;background:var(--sm-off);display:inline-block}
.svcmon__card.is-on .svcmon__led i{background:var(--sm-on);animation:smPulse 1.6s ease-in-out infinite}
.svcmon__pid{font-family:ui-monospace,Consolas,monospace;font-size:9.5px;color:var(--sm-mut2);max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* 快捷開關（精簡橫式） */
.svcmon__sw{display:flex;align-items:center;gap:7px;border:1px solid var(--sm-line2);background:var(--sm-panel2);border-radius:9px;padding:4px 9px;cursor:pointer;font-family:inherit;transition:.16s;width:auto;flex:0 0 auto}
.svcmon__sw:hover{border-color:var(--sm-accent)}
.svcmon__sw:disabled{opacity:.5;cursor:not-allowed}
.svcmon__sw-track{position:relative;width:32px;height:17px;border-radius:999px;background:var(--sm-off);flex:none;transition:.22s;opacity:.6}
.svcmon__sw-knob{position:absolute;top:2px;left:2px;width:13px;height:13px;border-radius:50%;background:#fff;transition:.22s;box-shadow:0 1px 3px rgba(0,0,0,.35)}
.svcmon__sw.is-on .svcmon__sw-track{background:linear-gradient(135deg,#34d399,#059669);opacity:1}
.svcmon__sw.is-on .svcmon__sw-knob{left:17px}
.svcmon__sw-text{font-size:11px;font-weight:700;color:var(--sm-mut)}
.svcmon__sw.is-on .svcmon__sw-text{color:var(--sm-on)}
.svcmon__sw.is-busy .svcmon__sw-track{opacity:.4}

.svcmon__foot{position:relative;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:7px 14px;border-top:1px solid var(--sm-line);font-size:10px;color:var(--sm-mut2);font-family:ui-monospace,Consolas,monospace;flex-wrap:wrap}
.svcmon__foot .bar{flex:1;min-width:110px;height:3px;border-radius:999px;background:var(--sm-line2);overflow:hidden;max-width:300px}
.svcmon__foot .bar>span{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#34d399,#38bdf8);transition:width .5s}
.svcmon__spin{animation:smSpin 1s linear infinite;display:inline-block}
@keyframes smSpin{to{transform:rotate(360deg)}}

/* 確認對話框 */
.svcmon-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(3,7,16,.62);backdrop-filter:blur(6px);opacity:0;pointer-events:none;transition:opacity .2s}
.svcmon-modal.is-in{opacity:1;pointer-events:auto}
.svcmon-modal__box{width:100%;max-width:400px;border-radius:18px;border:1px solid var(--sm-line);background:var(--sm-bg);box-shadow:0 40px 90px -30px rgba(0,0,0,.85);padding:22px;transform:translateY(10px) scale(.97);transition:transform .22s}
.svcmon--light .svcmon-modal__box{background:#fff}
.svcmon-modal.is-in .svcmon-modal__box{transform:none}
.svcmon-modal__ico{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:13px;color:#fff}
.svcmon-modal__ico.go{background:linear-gradient(135deg,#059669,#047857)}
.svcmon-modal__ico.stop{background:linear-gradient(135deg,#e11d48,#9f1239)}
.svcmon-modal__t{font-family:'Noto Serif TC',serif;font-size:16px;font-weight:700;margin-bottom:7px}
.svcmon-modal__m{font-size:12.5px;color:var(--sm-mut);line-height:1.7;margin-bottom:18px}
.svcmon-modal__m b{color:var(--sm-rose)}
.svcmon-modal__ops{display:flex;gap:10px;justify-content:flex-end}
.svcmon-modal__ops .svcmon__btn{padding:9px 16px}

/* Toast */
.svcmon-toasts{position:fixed;top:84px;right:18px;z-index:10000;display:flex;flex-direction:column;gap:9px;max-width:340px}
.svcmon-toast{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:12px;font-size:12.5px;color:#e2e8f0;background:rgba(8,12,20,.97);border:1px solid rgba(52,211,153,.45);box-shadow:0 18px 40px -14px rgba(0,0,0,.7);transform:translateX(120%);opacity:0;transition:.3s}
.svcmon-toast.is-in{transform:none;opacity:1}
.svcmon-toast.is-err{border-color:rgba(251,113,133,.5)}
.svcmon-toast i{font-size:14px;color:#34d399}
.svcmon-toast.is-err i{color:#fb7185}
@media (max-width:560px){
  .svcmon__actions{margin-left:0;width:100%}
  .svcmon__grid{padding:10px}
  .svcmon__host{display:none}
  .svcmon__card{gap:9px}
  .svcmon__cstate{min-width:0;gap:10px}
  .svcmon__pid{display:none}
}
</style>

<section class="svcmon <?= $monVariantClass ?>" id="<?= htmlspecialchars($monitorId) ?>"
         data-api="<?= htmlspecialchars($monitorApi, ENT_QUOTES) ?>"
         data-csrf="<?= htmlspecialchars($monitorCsrf, ENT_QUOTES) ?>"
         data-services="<?= htmlspecialchars($monServicesJson, ENT_QUOTES, 'UTF-8') ?>"
         data-enabled="<?= $monitorEnabled ? '1' : '0' ?>">
  <div class="svcmon__frame">
    <span class="svcmon__glow" aria-hidden="true"></span>
    <span class="svcmon__glow svcmon__glow--2" aria-hidden="true"></span>

    <header class="svcmon__head">
      <div class="svcmon__title">
        <span class="svcmon__badge"><i class="fa-solid fa-microchip"></i></span>
        <div>
          <b><?= htmlspecialchars($monitorTitle) ?></b>
          <span class="svcmon__sub"><?= htmlspecialchars($monitorSubtitle) ?></span>
        </div>
      </div>

      <div class="svcmon__headmeta">
        <span class="svcmon__pill is-read" data-role="pill"><span class="dot"></span> 讀取中…</span>
        <?php if ($monitorTarget !== ''): ?>
          <span class="svcmon__host" title="目標主機"><i class="fa-solid fa-server"></i> <?= htmlspecialchars($monitorTarget) ?></span>
        <?php endif; ?>
      </div>

      <div class="svcmon__actions">
        <button type="button" class="svcmon__btn svcmon__btn--go" data-role="start-all" disabled><i class="fa-solid fa-play"></i> 全部啟動</button>
        <button type="button" class="svcmon__btn svcmon__btn--stop" data-role="stop-all" disabled><i class="fa-solid fa-stop"></i> 全部關閉</button>
        <button type="button" class="svcmon__btn svcmon__btn--ghost" data-role="refresh" title="立即更新"><i class="fa-solid fa-rotate"></i></button>
        <label class="svcmon__auto" title="每 5 秒自動同步狀態"><input type="checkbox" data-role="auto" checked> 自動</label>
        <?php if ($monitorControlHref !== ''): ?>
          <a class="svcmon__btn svcmon__btn--ghost" href="<?= htmlspecialchars($monitorControlHref, ENT_QUOTES) ?>"><i class="fa-solid fa-sliders"></i> 控制頁</a>
        <?php endif; ?>
      </div>
    </header>

    <div class="svcmon__grid" data-role="grid">
      <?php if ($monSvcCount === 0): ?>
        <div style="grid-column:1/-1;text-align:center;color:var(--sm-mut2);font-size:12.5px;padding:26px">
          尚未設定服務清單。
        </div>
      <?php else: foreach ($monitorServices as $svc): ?>
        <article class="svcmon__card" data-key="<?= htmlspecialchars((string)$svc['key'], ENT_QUOTES) ?>">
          <div class="svcmon__ctop">
            <span class="svcmon__cico"><i class="fa-solid <?= htmlspecialchars((string)($svc['icon'] ?? 'fa-microchip'), ENT_QUOTES) ?>"></i></span>
            <div style="min-width:0">
              <div class="svcmon__cname"><?= htmlspecialchars((string)$svc['label']) ?></div>
              <div class="svcmon__ckey"><?= htmlspecialchars((string)$svc['key']) ?></div>
            </div>
          </div>
          <div class="svcmon__cstate">
            <span class="svcmon__led"><i></i> <span data-role="state-t">—</span></span>
            <span class="svcmon__pid" data-role="pid">—</span>
          </div>
          <button type="button" class="svcmon__sw" data-role="toggle" disabled aria-checked="false">
            <span class="svcmon__sw-track"><span class="svcmon__sw-knob"></span></span>
            <span class="svcmon__sw-text" data-role="sw-text">啟動</span>
          </button>
        </article>
      <?php endforeach; endif; ?>
    </div>

    <footer class="svcmon__foot">
      <span data-role="updated">等待首次同步…</span>
      <span class="bar"><span data-role="bar"></span></span>
      <span data-role="count">0 / <?= (int)$monSvcCount ?></span>
    </footer>
  </div><!-- /.svcmon__frame -->

<div class="svcmon-modal" data-role="modal" aria-hidden="true">
  <div class="svcmon-modal__box" role="dialog" aria-modal="true">
    <div class="svcmon-modal__ico stop" data-role="modal-ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="svcmon-modal__t" data-role="modal-title">確認操作</div>
    <div class="svcmon-modal__m" data-role="modal-msg"></div>
    <div class="svcmon-modal__ops">
      <button type="button" class="svcmon__btn svcmon__btn--ghost" data-role="modal-cancel">取消</button>
      <button type="button" class="svcmon__btn svcmon__btn--stop" data-role="modal-ok">確認</button>
    </div>
  </div>
</div>
</section>

<script>
(function () {
  'use strict';
  var root = document.getElementById(<?= json_encode($monitorId) ?>);
  if (!root) return;

  var API      = root.getAttribute('data-api');
  var CSRF     = root.getAttribute('data-csrf');
  var SERVICES = JSON.parse(root.getAttribute('data-services') || '[]');
  var ENABLED  = root.getAttribute('data-enabled') === '1';
  var POLL     = 5000;

  var q  = function (sel) { return root.querySelector('[data-role="' + sel + '"]'); };
  var qa = function (sel) { return Array.prototype.slice.call(root.querySelectorAll('[data-role="' + sel + '"]')); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  };
  var ICON = {}; SERVICES.forEach(function (s) { ICON[s.key] = s.icon; });

  var cards = {};
  Array.prototype.slice.call(root.querySelectorAll('.svcmon__card')).forEach(function (el) {
    cards[el.getAttribute('data-key')] = el;
  });

  var busy = false, last = null, autoOn = true, timer = null, refreshInflight = false;

  /* ---------- toast ---------- */
  var toastBox = null;
  function toast(msg, type) {
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'svcmon-toasts';
      document.body.appendChild(toastBox);
    }
    var box = toastBox;
    var el = document.createElement('div');
    el.className = 'svcmon-toast' + (type === 'err' ? ' is-err' : '');
    el.innerHTML = '<i class="fa-solid ' + (type === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span>' + esc(msg) + '</span>';
    box.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('is-in'); });
    setTimeout(function () { el.classList.remove('is-in'); setTimeout(function () { el.remove(); }, 320); }, 4200);
  }

  /* ---------- confirm ---------- */
  function confirmBox(opt) {
    return new Promise(function (resolve) {
      var modal = q('modal'), mIco = q('modal-ico'), mT = q('modal-title'), mM = q('modal-msg');
      var ok = q('modal-ok'), cancel = q('modal-cancel');
      var tone = opt.tone === 'success' ? 'go' : 'stop';
      mIco.className = 'svcmon-modal__ico ' + tone;
      mIco.innerHTML = '<i class="fa-solid ' + (tone === 'go' ? 'fa-play' : 'fa-triangle-exclamation') + '"></i>';
      mT.textContent = opt.title || '確認操作';
      mM.innerHTML = opt.message || '';
      ok.className = 'svcmon__btn ' + (tone === 'go' ? 'svcmon__btn--go' : 'svcmon__btn--stop');
      ok.textContent = opt.confirmText || '確認';
      cancel.textContent = opt.cancelText || '取消';
      function close(v) {
        modal.classList.remove('is-in');
        modal.setAttribute('aria-hidden', 'true');
        ok.removeEventListener('click', onOk);
        cancel.removeEventListener('click', onNo);
        modal.removeEventListener('click', onBg);
        resolve(v);
      }
      function onOk() { close(true); }
      function onNo() { close(false); }
      function onBg(e) { if (e.target === modal) close(false); }
      ok.addEventListener('click', onOk);
      cancel.addEventListener('click', onNo);
      modal.addEventListener('click', onBg);
      modal.classList.add('is-in');
      modal.setAttribute('aria-hidden', 'false');
    });
  }

  /* ---------- api ---------- */
  function api(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', CSRF);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); });
  }

  function setBusy(b) {
    busy = b;
    root.classList.toggle('is-busy', b);
    if (last) render(last);
  }

  /* ---------- refresh ---------- */
  function refresh(silent) {
    if (refreshInflight) return;
    refreshInflight = true;
    var rb = q('refresh');
    if (rb) { var ic = rb.querySelector('i'); if (ic && !silent) ic.classList.add('svcmon__spin'); }
    api('status').then(function (d) {
      if (!d || !d.ok) { if (!silent) toast((d && d.message) || '讀取失敗', 'err'); return; }
      render(d);
    }).catch(function () {
      if (!silent) toast('讀取狀態失敗，請檢查網路連線。', 'err');
    }).finally(function () {
      refreshInflight = false;
      if (rb) { var ic = rb.querySelector('i'); if (ic) ic.classList.remove('svcmon__spin'); }
    });
  }

  function schedule() {
    if (timer) clearInterval(timer);
    if (autoOn) timer = setInterval(function () { refresh(true); }, POLL);
  }

  /* ---------- render ---------- */
  function render(d) {
    last = d;
    var reachable = d.reachable !== false;
    var any = reachable && !!d.any_running;

    var pill = q('pill');
    if (pill) {
      pill.className = 'svcmon__pill ' + (!reachable ? 'is-err' : (any ? 'is-on' : 'is-off'));
      pill.innerHTML = '<span class="dot"></span> ' + (!reachable ? '連線失敗'
        : (any ? ('運作中 · ' + d.running_count + '/' + d.total_count) : '未運作'));
    }

    (d.services || []).forEach(function (s) {
      var el = cards[s.key]; if (!el) return;
      var on = !!s.running, was = el.getAttribute('data-on');
      el.classList.toggle('is-on', on);
      el.classList.toggle('is-offline', !reachable);
      if (was === '0' && on) { el.classList.add('is-flash'); setTimeout(function () { el.classList.remove('is-flash'); }, 950); }
      if (was === '1' && !on) { el.classList.add('is-flash-rose'); setTimeout(function () { el.classList.remove('is-flash-rose'); }, 950); }
      el.setAttribute('data-on', on ? '1' : '0');

      var st = el.querySelector('[data-role="state-t"]'); if (st) st.textContent = on ? '執行中' : '已停止';
      var pid = el.querySelector('[data-role="pid"]');
      if (pid) pid.textContent = !reachable ? '無法連線' : (on ? ('PID ' + (s.pids && s.pids.length ? s.pids.join(',') : '—')) : '—');

      var sw = el.querySelector('[data-role="toggle"]');
      if (sw) {
        sw.classList.toggle('is-on', on);
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        var txt = sw.querySelector('[data-role="sw-text"]');
        if (txt) txt.textContent = on ? '停止' : '啟動';
        sw.disabled = busy || !reachable || !d.enabled;
      }
    });

    var sb = q('start-all'), eb = q('stop-all');
    if (sb) sb.disabled = busy || any || !d.enabled || d.start_guard || !reachable;
    if (eb) eb.disabled = busy || !d.enabled || !reachable;
    if (sb) sb.title = !reachable ? '無法連線至目標伺服器' : (any ? '伺服器已在執行中，已禁止重複啟動' : (d.start_guard ? '啟動程序剛執行，請稍候' : ''));

    var cnt = q('count'); if (cnt) cnt.textContent = (reachable ? d.running_count : '—') + ' / ' + d.total_count;
    var bar = q('bar'); if (bar) bar.style.width = (reachable && d.total_count ? Math.round(d.running_count / d.total_count * 100) : 0) + '%';
    var upd = q('updated');
    var t = new Date((d.ts || (Date.now() / 1000)) * 1000).toLocaleTimeString('zh-TW', { hour12: false });
    if (upd) upd.textContent = reachable ? ('更新於 ' + t + ' · 每 ' + (POLL / 1000) + 's') : ('無法連線：' + (d.error || '未知錯誤'));
  }

  /* ---------- actions ---------- */
  function afterAction() { setTimeout(function () { refresh(true); }, 1100); }

  function doAll(kind) {
    if (busy) return;
    var start = kind === 'start_all';
    confirmBox({
      title: start ? '啟動遊戲伺服器' : '關閉遊戲伺服器',
      message: start
        ? '即將於遠端執行啟動腳本，逐一拉起所有遊戲服務。<br>程序出現後本面板將自動同步狀態。'
        : '即將於遠端執行關閉程序，<b>所有遊戲服務將被終止</b>。<br>請確認目前無玩家在線再行操作。',
      confirmText: start ? '確認啟動' : '確認關閉',
      cancelText: '暫且返回',
      tone: start ? 'success' : 'danger'
    }).then(function (ok) {
      if (!ok) return;
      setBusy(true);
      api(kind).then(function (d) {
        toast((d && d.message) || ((d && d.ok) ? '指令已送出。' : '操作失敗'), (d && d.ok) ? 'ok' : 'err');
      }).catch(function () { toast('操作失敗，請稍後再試。', 'err'); })
        .finally(function () { setBusy(false); afterAction(); });
    });
  }

  function doToggle(key, currentlyOn) {
    if (busy) return;
    var meta = SERVICES.filter(function (x) { return x.key === key; })[0] || { label: key };
    var next = currentlyOn ? 'service_stop' : 'service_start';
    var run = function () {
      setBusy(true);
      api(next, { key: key }).then(function (d) {
        toast((d && d.message) || ((d && d.ok) ? '指令已送出。' : '操作失敗'), (d && d.ok) ? 'ok' : 'err');
      }).catch(function () { toast('操作失敗，請稍後再試。', 'err'); })
        .finally(function () { setBusy(false); afterAction(); });
    };
    if (currentlyOn) {
      confirmBox({
        title: '關閉服務',
        message: '確定要終止「<b>' + esc(meta.label) + '</b>」？',
        confirmText: '確認關閉', cancelText: '暫且返回', tone: 'danger'
      }).then(function (ok) { if (ok) run(); });
    } else {
      run();
    }
  }

  /* ---------- events ---------- */
  root.addEventListener('click', function (e) {
    var sw = e.target.closest('[data-role="toggle"]');
    if (sw) {
      var card = sw.closest('.svcmon__card');
      doToggle(card.getAttribute('data-key'), card.classList.contains('is-on'));
      return;
    }
    var sb = e.target.closest('[data-role="start-all"]'); if (sb) { doAll('start_all'); return; }
    var eb = e.target.closest('[data-role="stop-all"]');  if (eb) { doAll('stop_all'); return; }
    var rb = e.target.closest('[data-role="refresh"]');   if (rb) { refresh(false); return; }
  });

  var autoEl = q('auto');
  if (autoEl) autoEl.addEventListener('change', function () {
    autoOn = this.checked; schedule();
    toast(autoOn ? '已開啟自動更新。' : '已暫停自動更新。', 'ok');
    if (autoOn) refresh(true);
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { if (timer) clearInterval(timer); }
    else { schedule(); refresh(true); }
  });

  schedule();
  refresh(false);
})();
</script>
