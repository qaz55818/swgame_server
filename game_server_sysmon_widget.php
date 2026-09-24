<?php
/**
 * =============================================================
 *  踏雪笑傲 · 遠端 Linux 伺服器系統監控元件（game_server_sysmon_widget.php）
 *  -------------------------------------------------------------
 *  於後台首頁左側預留區顯示遠端遊戲伺服器即時資訊：
 *    - CPU 使用率（環形儀表 + 使用者/系統/IO等待/閒置 堆疊 + 負載平均）
 *    - 記憶體 / 交換空間（動畫長條）
 *    - 網路即時流量（上下行即時速率 + Canvas 波形）
 *    - 硬碟即時 IO（讀寫速率、IOPS、使用率 + Canvas 波形）
 *    - 根分割區容量與系統執行時間
 *
 *  資料來源：admin/server_remote_status_api.php（GM 專屬，GET 唯讀）
 *  使用方式（於已登入 GM 的頁面）：
 *    $sysmonApi = 'server_remote_status_api.php';
 *    include __DIR__ . '/../game_server_sysmon_widget.php';
 *  即時刷新：前端每 3 秒以 fetch 取得最新數據，免重整。
 * =============================================================
 */

if (!isset($_SESSION['gm_username']) || $_SESSION['gm_username'] === '') {
    return; // 僅 GM 可見
}
$sysmonApi = (string)($sysmonApi ?? 'server_remote_status_api.php');
$sysmonId  = 'sysmon_' . substr(md5(uniqid('', true)), 0, 6);
$sysmonGradId = $sysmonId . '_cpu';
?>
<style>
.sysmon{--sys-radius:18px;--sys-line:rgba(51,65,85,.7);--sys-line2:rgba(71,85,105,.55);
  --sys-txt:#e2e8f0;--sys-mut:#94a3b8;--sys-mut2:#64748b;
  --sys-cpu:#38bdf8;--sys-mem:#a78bfa;--sys-net:#34d399;--sys-disk:#fbbf24;
  position:relative;height:100%;font-family:'Manrope','Noto Serif TC',sans-serif;color:var(--sys-txt)}
.sysmon *{box-sizing:border-box}
.sysmon__frame{position:relative;height:100%;display:flex;flex-direction:column;border-radius:var(--sys-radius);overflow:hidden;
  background:linear-gradient(180deg,rgba(19,28,43,.94),rgba(11,16,26,.97));border:1px solid var(--sys-line);
  box-shadow:0 30px 70px -34px rgba(0,0,0,.92)}
.sysmon__frame::before{content:"";position:absolute;inset:0 0 auto 0;height:1px;background:linear-gradient(90deg,transparent,rgba(167,139,250,.55),rgba(52,211,153,.5),transparent)}
.sysmon__glow{position:absolute;top:-100px;left:-60px;width:300px;height:300px;border-radius:50%;pointer-events:none;filter:blur(8px);
  background:radial-gradient(circle,rgba(56,189,248,.16),transparent 70%)}
.sysmon__glow--2{top:auto;left:auto;right:-80px;bottom:-120px;background:radial-gradient(circle,rgba(251,191,36,.12),transparent 70%)}

.sysmon__head{position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:13px 16px;border-bottom:1px solid var(--sys-line)}
.sysmon__title{display:flex;align-items:center;gap:11px;min-width:0}
.sysmon__badge{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:none;color:#fff;font-size:15px;
  background:linear-gradient(135deg,#a78bfa,#7c3aed);box-shadow:0 10px 22px -12px rgba(167,139,250,.95),inset 0 1px 0 rgba(255,255,255,.3)}
.sysmon__title b{display:block;font-family:'Noto Serif TC',serif;font-size:14.5px;letter-spacing:.04em;line-height:1.2}
.sysmon__sub{display:block;font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--sys-mut2);margin-top:2px;font-family:ui-monospace,Consolas,monospace}
.sysmon__meta{margin-left:auto;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.sysmon__host{font-size:11px;font-family:ui-monospace,Consolas,monospace;color:var(--sys-mut);padding:5px 11px;border-radius:9px;border:1px dashed var(--sys-line2);background:rgba(11,17,28,.55);white-space:nowrap;max-width:260px;overflow:hidden;text-overflow:ellipsis}
.sysmon__live{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:700;letter-spacing:.12em;color:var(--sys-net);padding:5px 10px;border-radius:999px;border:1px solid rgba(52,211,153,.45);background:rgba(6,78,59,.22)}
.sysmon__live i{width:7px;height:7px;border-radius:50%;background:var(--sys-net);box-shadow:0 0 0 4px rgba(52,211,153,.18);animation:sysPulse 1.6s ease-in-out infinite}
.sysmon__live.is-err{color:#fb7185;border-color:rgba(251,113,133,.5);background:rgba(76,5,25,.22)}
.sysmon__live.is-err i{background:#fb7185;box-shadow:0 0 0 4px rgba(251,113,133,.18)}
.sysmon__updated{font-size:10px;color:var(--sys-mut2);font-family:ui-monospace,Consolas,monospace}
@keyframes sysPulse{0%,100%{opacity:1}50%{opacity:.3}}

.sysmon__grid{position:relative;flex:1;display:grid;grid-template-columns:repeat(auto-fit,minmax(252px,1fr));gap:12px;padding:14px 16px;align-content:stretch}
.sysmon__card{position:relative;display:flex;flex-direction:column;gap:9px;min-height:150px;padding:13px 14px;border-radius:14px;
  border:1px solid var(--sys-line);background:linear-gradient(180deg,rgba(19,28,43,.72),rgba(11,17,28,.5));overflow:hidden;transition:.2s}
.sysmon__card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent,#64748b);opacity:.75}
.sysmon__card:hover{border-color:color-mix(in srgb,var(--accent) 45%,transparent)}
.sysmon__cardh{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;letter-spacing:.06em;color:var(--sys-mut);font-family:'Noto Serif TC',serif}
.sysmon__cardh i{color:var(--accent);font-size:13px}
.sysmon__cardh .tag{margin-left:auto;font-size:9.5px;font-family:ui-monospace,Consolas,monospace;color:var(--sys-mut2);font-weight:500}

/* CPU */
.sysmon__cpubody{display:flex;align-items:center;gap:15px;flex:1}
.sysmon__ring{position:relative;width:104px;height:104px;flex:none}
.sysmon__svg{width:100%;height:100%;transform:rotate(-90deg)}
.sysmon__track{fill:none;stroke:rgba(148,163,184,.16);stroke-width:8}
.sysmon__progress{fill:none;stroke:url(#<?= $sysmonGradId ?>);stroke-width:8;stroke-linecap:round;
  stroke-dasharray:263.9;stroke-dashoffset:263.9;transition:stroke-dashoffset .7s cubic-bezier(.4,0,.2,1)}
.sysmon__ringc{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px}
.sysmon__ringc b{font-size:24px;font-family:'Noto Serif TC',serif;line-height:1;color:#fff}
.sysmon__ringc span{font-size:9.5px;color:var(--sys-mut2);letter-spacing:.14em;text-transform:uppercase}
.sysmon__cpuinfo{flex:1;min-width:0;display:flex;flex-direction:column;gap:8px}
.sysmon__loads{display:flex;gap:6px;flex-wrap:wrap}
.sysmon__loads span{flex:1;min-width:52px;text-align:center;font-size:9.5px;color:var(--sys-mut2);border:1px solid var(--sys-line2);border-radius:9px;padding:5px 4px;background:rgba(11,17,28,.5)}
.sysmon__loads b{display:block;font-size:13px;color:var(--sys-txt);font-family:ui-monospace,Consolas,monospace;margin-top:2px}
.sysmon__stack{display:flex;height:9px;border-radius:999px;overflow:hidden;background:rgba(148,163,184,.12)}
.sysmon__stack>i{display:block;height:100%;transition:width .7s cubic-bezier(.4,0,.2,1)}
.sysmon__legend{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:9.5px;color:var(--sys-mut2)}
.sysmon__legend i{display:inline-block;width:7px;height:7px;border-radius:2px;margin-right:4px;vertical-align:middle}

/* 記憶體 */
.sysmon__big{display:flex;align-items:baseline;gap:6px}
.sysmon__big b{font-size:26px;font-family:'Noto Serif TC',serif;line-height:1;color:#fff}
.sysmon__big span{font-size:10.5px;color:var(--sys-mut)}
.sysmon__barbox{display:flex;flex-direction:column;gap:9px;margin-top:2px}
.sysmon__barrow{font-size:10px;color:var(--sys-mut2)}
.sysmon__barrow .lbl{display:flex;justify-content:space-between;margin-bottom:3px;font-family:ui-monospace,Consolas,monospace}
.sysmon__bar{height:8px;border-radius:999px;background:rgba(148,163,184,.14);overflow:hidden}
.sysmon__barfill{display:block;height:100%;width:0;border-radius:999px;transition:width .7s cubic-bezier(.4,0,.2,1)}

/* 網路 / 硬碟 */
.sysmon__rates{display:flex;gap:12px}
.sysmon__rate{flex:1;min-width:0}
.sysmon__rate .k{font-size:9.5px;color:var(--sys-mut2);display:flex;align-items:center;gap:5px;letter-spacing:.04em}
.sysmon__rate .k i{font-size:10px}
.sysmon__rate .v{font-size:15px;font-family:ui-monospace,Consolas,monospace;color:#fff;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sysmon__rate .v small{font-size:9.5px;color:var(--sys-mut2);margin-left:2px}
.sysmon__chart{position:relative;flex:1;min-height:60px;margin-top:2px}
.sysmon__chart canvas{position:absolute;inset:0;width:100%;height:100%;display:block}
.sysmon__chips{display:flex;flex-wrap:wrap;gap:6px 12px;font-size:9.5px;color:var(--sys-mut2);font-family:ui-monospace,Consolas,monospace}

.sysmon__foot{position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px;padding:10px 16px;border-top:1px solid var(--sys-line);font-size:10.5px;color:var(--sys-mut2);font-family:ui-monospace,Consolas,monospace}
.sysmon__fs{flex:1;min-width:180px;display:flex;align-items:center;gap:9px}
.sysmon__fs .bar{flex:1;height:5px;border-radius:999px;background:rgba(148,163,184,.14);overflow:hidden}
.sysmon__fs .bar>span{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#fbbf24,#fb923c);transition:width .7s ease}
.sysmon__overlay{position:absolute;inset:0;display:none;align-items:center;justify-content:center;flex-direction:column;gap:10px;
  text-align:center;padding:24px;background:rgba(8,12,20,.72);backdrop-filter:blur(3px);z-index:5}
.sysmon__overlay.is-on{display:flex}
.sysmon__overlay i{font-size:26px;color:#fb7185}
.sysmon__overlay b{font-family:'Noto Serif TC',serif;color:#e2e8f0;font-size:14px}
.sysmon__overlay span{font-size:11px;color:var(--sys-mut);max-width:420px}
@media (max-width:640px){.sysmon__grid{grid-template-columns:1fr}.sysmon__host{display:none}}
</style>

<section class="sysmon" id="<?= htmlspecialchars($sysmonId) ?>" data-api="<?= htmlspecialchars($sysmonApi, ENT_QUOTES) ?>">
  <div class="sysmon__frame">
    <span class="sysmon__glow" aria-hidden="true"></span>
    <span class="sysmon__glow sysmon__glow--2" aria-hidden="true"></span>

    <header class="sysmon__head">
      <div class="sysmon__title">
        <span class="sysmon__badge"><i class="fa-solid fa-server"></i></span>
        <div>
          <b>遠端 Linux 伺服器</b>
          <span class="sysmon__sub">REMOTE SYSTEM MONITOR</span>
        </div>
      </div>
      <div class="sysmon__meta">
        <span class="sysmon__host" data-role="host"><i class="fa-solid fa-plug"></i> 連線中…</span>
        <span class="sysmon__live" data-role="live"><i></i> LIVE</span>
        <span class="sysmon__updated" data-role="updated">--:--:--</span>
      </div>
    </header>

    <div class="sysmon__grid">
      <!-- CPU -->
      <article class="sysmon__card" style="--accent:var(--sys-cpu)">
        <div class="sysmon__cardh"><i class="fa-solid fa-microchip"></i> CPU 處理器 <span class="tag" data-role="cores">— CORE</span></div>
        <div class="sysmon__cpubody">
          <div class="sysmon__ring">
            <svg class="sysmon__svg" viewBox="0 0 100 100">
              <defs>
                <linearGradient id="<?= htmlspecialchars($sysmonGradId) ?>" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0%" stop-color="#38bdf8"/><stop offset="100%" stop-color="#818cf8"/>
                </linearGradient>
              </defs>
              <circle class="sysmon__track" cx="50" cy="50" r="42"/>
              <circle class="sysmon__progress" data-role="cpu-arc" cx="50" cy="50" r="42"/>
            </svg>
            <div class="sysmon__ringc"><b data-role="cpu-pct">0</b><span>USAGE</span></div>
          </div>
          <div class="sysmon__cpuinfo">
            <div class="sysmon__loads">
              <span>1 min<b data-role="load1">—</b></span>
              <span>5 min<b data-role="load5">—</b></span>
              <span>15 min<b data-role="load15">—</b></span>
            </div>
            <div class="sysmon__stack">
              <i data-role="seg-user" style="background:#38bdf8;width:0"></i>
              <i data-role="seg-system" style="background:#818cf8;width:0"></i>
              <i data-role="seg-iowait" style="background:#fbbf24;width:0"></i>
              <i data-role="seg-steal" style="background:#fb7185;width:0"></i>
            </div>
            <div class="sysmon__legend">
              <span><i style="background:#38bdf8"></i>使用者 <b data-role="u-user">0%</b></span>
              <span><i style="background:#818cf8"></i>系統 <b data-role="u-system">0%</b></span>
              <span><i style="background:#fbbf24"></i>IO等待 <b data-role="u-iowait">0%</b></span>
            </div>
          </div>
        </div>
      </article>

      <!-- 記憶體 -->
      <article class="sysmon__card" style="--accent:var(--sys-mem)">
        <div class="sysmon__cardh"><i class="fa-solid fa-memory"></i> 記憶體 <span class="tag" data-role="mem-total-tag">—</span></div>
        <div class="sysmon__big"><b data-role="mem-pct">0</b><span>% 已使用</span></div>
        <div class="sysmon__barbox">
          <div class="sysmon__barrow">
            <div class="lbl"><span>RAM</span><span data-role="mem-text">— / —</span></div>
            <div class="sysmon__bar"><span class="sysmon__barfill" data-role="mem-bar" style="background:linear-gradient(90deg,#a78bfa,#7c3aed)"></span></div>
          </div>
          <div class="sysmon__barrow">
            <div class="lbl"><span>SWAP</span><span data-role="swap-text">— / —</span></div>
            <div class="sysmon__bar"><span class="sysmon__barfill" data-role="swap-bar" style="background:linear-gradient(90deg,#f472b6,#be123c)"></span></div>
          </div>
        </div>
        <div class="sysmon__chips">
          <span>可用 <b data-role="mem-avail">—</b></span>
          <span>快取 <b data-role="mem-cached">—</b></span>
        </div>
      </article>

      <!-- 網路 -->
      <article class="sysmon__card" style="--accent:var(--sys-net)">
        <div class="sysmon__cardh"><i class="fa-solid fa-arrow-right-arrow-left"></i> 即時網路流量 <span class="tag">NET</span></div>
        <div class="sysmon__rates">
          <div class="sysmon__rate">
            <div class="k"><i class="fa-solid fa-arrow-down" style="color:#34d399"></i>下行</div>
            <div class="v" data-role="net-rx">0<small>B/s</small></div>
          </div>
          <div class="sysmon__rate">
            <div class="k"><i class="fa-solid fa-arrow-up" style="color:#38bdf8"></i>上行</div>
            <div class="v" data-role="net-tx">0<small>B/s</small></div>
          </div>
        </div>
        <div class="sysmon__chart"><canvas data-role="net-canvas"></canvas></div>
        <div class="sysmon__chips">
          <span style="color:#34d399"><i class="fa-solid fa-arrow-down"></i> 累計 <b data-role="net-rxtotal">—</b></span>
          <span style="color:#38bdf8"><i class="fa-solid fa-arrow-up"></i> 累計 <b data-role="net-txtotal">—</b></span>
        </div>
      </article>

      <!-- 硬碟 IO -->
      <article class="sysmon__card" style="--accent:var(--sys-disk)">
        <div class="sysmon__cardh"><i class="fa-solid fa-hard-drive"></i> 硬碟即時 IO <span class="tag" data-role="io-util-tag">—</span></div>
        <div class="sysmon__rates">
          <div class="sysmon__rate">
            <div class="k"><i class="fa-solid fa-book-open" style="color:#fbbf24"></i>讀取</div>
            <div class="v" data-role="disk-r">0<small>B/s</small></div>
          </div>
          <div class="sysmon__rate">
            <div class="k"><i class="fa-solid fa-pen" style="color:#fb923c"></i>寫入</div>
            <div class="v" data-role="disk-w">0<small>B/s</small></div>
          </div>
        </div>
        <div class="sysmon__chart"><canvas data-role="disk-canvas"></canvas></div>
        <div class="sysmon__chips">
          <span>讀 IOPS <b data-role="disk-riops">—</b></span>
          <span>寫 IOPS <b data-role="disk-wiops">—</b></span>
          <span>使用率 <b data-role="disk-util">0%</b></span>
        </div>
      </article>
    </div>

    <footer class="sysmon__foot">
      <div class="sysmon__fs">
        <i class="fa-solid fa-database" style="color:#fbbf24"></i>
        <span>根分割區</span>
        <span class="bar"><span data-role="fs-bar"></span></span>
        <span data-role="fs-text">—</span>
      </div>
      <span><i class="fa-regular fa-clock"></i> 運行 <b data-role="uptime">—</b></span>
    </footer>

    <div class="sysmon__overlay" data-role="overlay">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <b>無法取得遠端系統資訊</b>
      <span data-role="overlay-msg">請確認目標伺服器與 SSH 設定。</span>
    </div>
  </div>
</section>

<script>
(function () {
  'use strict';
  var root = document.getElementById(<?= json_encode($sysmonId) ?>);
  if (!root) return;
  var API = root.getAttribute('data-api');
  var POLL = 3000;
  var $ = function (r) { return root.querySelector('[data-role="' + r + '"]'); };
  var C = 2 * Math.PI * 42;

  /* 動畫狀態（cur 逐步逼近 tgt） */
  var A = { cpu: 0, mem: 0, swap: 0, rx: 0, tx: 0, dr: 0, dw: 0, util: 0, fs: 0 };
  var T = { cpu: 0, mem: 0, swap: 0, rx: 0, tx: 0, dr: 0, dw: 0, util: 0, fs: 0 };
  var H = { rx: [], tx: [], dr: [], dw: [] };
  var MAXLEN = 48;
  var busy = false, timer = null, raf = null, alive = true;
  var staticData = { cores: 1, memTotal: 0, memAvail: 0, memUsed: 0, memCached: 0, swapTotal: 0, swapUsed: 0, rxTotal: 0, txTotal: 0, riops: 0, wiops: 0, user: 0, sys: 0, iowait: 0, steal: 0, load1: 0, load5: 0, load15: 0, fsTotal: 0, fsUsed: 0, fsPct: 0, uptime: 0 };

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n.toFixed(0) + ' B';
    var u = ['KB', 'MB', 'GB', 'TB', 'PB'], i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return (n >= 100 ? n.toFixed(0) : n.toFixed(1)) + ' ' + u[i];
  }
  function fmtRate(n) { var s = fmtBytes(n); return s.replace(' B', ' B') + '/s'; }
  function uptimeText(sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    if (d > 0) return d + ' 天 ' + h + ' 時';
    if (h > 0) return h + ' 時 ' + m + ' 分';
    return m + ' 分 ' + (sec % 60) + ' 秒';
  }
  function setText(role, txt) { var el = $(role); if (el) el.textContent = txt; }

  /* ---------- Canvas 波形 ---------- */
  function fitCanvas(cv) {
    var dpr = window.devicePixelRatio || 1;
    var w = cv.clientWidth || 200, h = cv.clientHeight || 60;
    if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) {
      cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
    }
    var ctx = cv.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx: ctx, w: w, h: h };
  }
  function series(hist, cur) {
    var a = hist.slice(); if (!a.length) return a; a[a.length - 1] = cur; return a;
  }
  function drawChart(cv, sets) {
    if (!cv) return;
    var f = fitCanvas(cv), ctx = f.ctx, w = f.w, h = f.h;
    ctx.clearRect(0, 0, w, h);
    var max = 1;
    sets.forEach(function (s) { s.data.forEach(function (v) { if (v > max) max = v; }); });
    max *= 1.15;
    // 基準線
    ctx.strokeStyle = 'rgba(148,163,184,.12)'; ctx.lineWidth = 1;
    for (var g = 1; g <= 2; g++) { var yy = h - (h * g / 3); ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(w, yy); ctx.stroke(); }
    sets.forEach(function (s) {
      var d = s.data; if (d.length < 2) return;
      var step = w / (MAXLEN - 1);
      var pts = d.map(function (v, i) { return [i * step + (w - (d.length - 1) * step) * 0, h - (Math.min(v, max) / max) * (h - 4) - 2]; });
      // 區域
      var grad = ctx.createLinearGradient(0, 0, 0, h);
      grad.addColorStop(0, s.fill + '.36'); grad.addColorStop(1, s.fill + '0');
      ctx.beginPath(); ctx.moveTo(pts[0][0], h);
      pts.forEach(function (p) { ctx.lineTo(p[0], p[1]); });
      ctx.lineTo(pts[pts.length - 1][0], h); ctx.closePath(); ctx.fillStyle = grad; ctx.fill();
      // 線
      ctx.beginPath(); pts.forEach(function (p, i) { i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1]); });
      ctx.strokeStyle = s.color; ctx.lineWidth = 1.6; ctx.lineJoin = 'round'; ctx.stroke();
      // 端點
      var lp = pts[pts.length - 1];
      ctx.beginPath(); ctx.arc(lp[0], lp[1], 2.4, 0, Math.PI * 2); ctx.fillStyle = s.color; ctx.fill();
    });
  }

  function paint() {
    // CPU
    var arc = $('cpu-arc');
    if (arc) arc.style.strokeDashoffset = (C * (1 - Math.min(100, A.cpu) / 100)).toFixed(2);
    setText('cpu-pct', (A.cpu < 10 ? A.cpu.toFixed(1) : A.cpu.toFixed(0)));
    setText('cores', staticData.cores + ' CORE');
    setText('load1', staticData.load1.toFixed(2));
    setText('load5', staticData.load5.toFixed(2));
    setText('load15', staticData.load15.toFixed(2));
    var su = $('seg-user'), ss = $('seg-system'), si = $('seg-iowait'), st = $('seg-steal');
    if (su) su.style.width = staticData.user + '%';
    if (ss) ss.style.width = staticData.sys + '%';
    if (si) si.style.width = staticData.iowait + '%';
    if (st) st.style.width = staticData.steal + '%';
    setText('u-user', staticData.user.toFixed(1) + '%');
    setText('u-system', staticData.sys.toFixed(1) + '%');
    setText('u-iowait', staticData.iowait.toFixed(1) + '%');

    // 記憶體
    setText('mem-pct', (A.mem < 10 ? A.mem.toFixed(1) : A.mem.toFixed(0)));
    var mb = $('mem-bar'); if (mb) mb.style.width = Math.min(100, A.mem) + '%';
    var sb = $('swap-bar'); if (sb) sb.style.width = Math.min(100, A.swap) + '%';
    setText('mem-text', fmtBytes(staticData.memUsed) + ' / ' + fmtBytes(staticData.memTotal));
    setText('mem-total-tag', fmtBytes(staticData.memTotal));
    setText('swap-text', staticData.swapTotal > 0 ? (fmtBytes(staticData.swapUsed) + ' / ' + fmtBytes(staticData.swapTotal)) : '未配置');
    setText('mem-avail', fmtBytes(staticData.memAvail));
    setText('mem-cached', fmtBytes(staticData.memCached));

    // 網路
    setTextRate('net-rx', A.rx); setTextRate('net-tx', A.tx);
    setText('net-rxtotal', fmtBytes(staticData.rxTotal));
    setText('net-txtotal', fmtBytes(staticData.txTotal));
    drawChart($('net-canvas'), [
      { data: series(H.rx, A.rx), color: '#34d399', fill: 'rgba(52,211,153,' },
      { data: series(H.tx, A.tx), color: '#38bdf8', fill: 'rgba(56,189,248,' }
    ]);

    // 硬碟
    setTextRate('disk-r', A.dr); setTextRate('disk-w', A.dw);
    setText('disk-riops', staticData.riops.toFixed(0));
    setText('disk-wiops', staticData.wiops.toFixed(0));
    setText('disk-util', A.util.toFixed(1) + '%');
    setText('io-util-tag', '使用率 ' + A.util.toFixed(0) + '%');
    drawChart($('disk-canvas'), [
      { data: series(H.dr, A.dr), color: '#fbbf24', fill: 'rgba(251,191,36,' },
      { data: series(H.dw, A.dw), color: '#fb923c', fill: 'rgba(251,146,60,' }
    ]);

    // 頁尾
    var fb = $('fs-bar'); if (fb) fb.style.width = Math.min(100, A.fs) + '%';
    setText('fs-text', fmtBytes(staticData.fsUsed) + ' / ' + fmtBytes(staticData.fsTotal) + ' (' + staticData.fsPct.toFixed(0) + '%)');
    setText('uptime', uptimeText(staticData.uptime));
  }
  function setTextRate(role, val) {
    var el = $(role); if (!el) return;
    el.innerHTML = fmtRate(val).replace(/(\d[\d.]*)\s*([KMGT]?B)/, '$1<small>$2/s</small>');
  }

  function loop() {
    var k, changed = false;
    for (k in A) {
      var d = T[k] - A[k];
      if (Math.abs(d) > 0.01) { A[k] += d * 0.14; changed = true; } else { A[k] = T[k]; }
    }
    if (changed || !loop.done) { paint(); loop.done = true; }
    raf = requestAnimationFrame(loop);
  }

  function fail(msg) {
    alive = false;
    var live = $('live'); if (live) live.classList.add('is-err');
    var ov = $('overlay'); if (ov) { ov.classList.add('is-on'); setText('overlay-msg', msg || '請確認目標伺服器與 SSH 設定。'); }
    setText('host', '連線失敗');
  }

  function apply(d) {
    alive = true;
    var ov = $('overlay'); if (ov) ov.classList.remove('is-on');
    var live = $('live'); if (live) live.classList.remove('is-err');
    setText('host', d.host || '—');
    setText('updated', new Date((d.ts || Date.now() / 1000) * 1000).toLocaleTimeString('zh-TW', { hour12: false }));

    staticData.cores = d.cpu.cores;
    staticData.load1 = d.cpu.load1; staticData.load5 = d.cpu.load5; staticData.load15 = d.cpu.load15;
    staticData.user = d.cpu.user || 0; staticData.sys = d.cpu.system || 0;
    staticData.iowait = d.cpu.iowait || 0; staticData.steal = d.cpu.steal || 0;

    staticData.memTotal = d.mem.total; staticData.memUsed = d.mem.used; staticData.memAvail = d.mem.avail;
    staticData.memCached = d.mem.cached || 0; staticData.swapTotal = d.mem.swapTotal; staticData.swapUsed = d.mem.swapUsed;

    staticData.rxTotal = d.net.rxTotal; staticData.txTotal = d.net.txTotal;
    staticData.riops = d.disk.readIops; staticData.wiops = d.disk.writeIops;
    staticData.fsTotal = d.fs.total; staticData.fsUsed = d.fs.used; staticData.fsPct = d.fs.percent;
    staticData.uptime = d.uptime;

    T.cpu = d.cpu.percent == null ? A.cpu : d.cpu.percent;
    T.mem = d.mem.percent; T.swap = d.mem.swapPercent;
    T.rx = d.net.rxRate; T.tx = d.net.txRate;
    T.dr = d.disk.readRate; T.dw = d.disk.writeRate;
    T.util = d.disk.util; T.fs = d.fs.percent;

    if (d.cpu.percent != null) {
      H.rx.push(d.net.rxRate); H.tx.push(d.net.txRate);
      H.dr.push(d.disk.readRate); H.dw.push(d.disk.writeRate);
      ['rx', 'tx', 'dr', 'dw'].forEach(function (key) { if (H[key].length > MAXLEN) H[key].shift(); });
    }
  }

  function refresh() {
    if (busy) return; busy = true;
    fetch(API, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || d.reachable === false) { fail((d && d.message) || ''); return; }
        apply(d);
      })
      .catch(function () { fail('無法讀取遠端系統資訊。'); })
      .finally(function () {
        busy = false;
        if (!document.hidden) { if (timer) clearInterval(timer); timer = setInterval(refresh, POLL); }
      });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { if (timer) { clearInterval(timer); timer = null; } }
    else { refresh(); }
  });
  window.addEventListener('resize', function () { paint(); });

  paint();
  raf = requestAnimationFrame(loop);
  refresh();
})();
</script>
