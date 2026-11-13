/*!
 * MSDV install gate
 * Blocks the whole page until the web app is installed, then shows a
 * step-by-step tutorial for iPhone/iPad, Android and PC.
 *
 * Usage: add to the <head> of EVERY page (index, login, dashboards...):
 *   <script src="install-gate.js"></script>
 * Put it BEFORE your service worker registration script.
 */
(function () {
  'use strict';

  /* ---------- SETTINGS ---------- */
  var CFG = {
    appName: 'MSDV',
    fullName: 'MCC Student Discipline and Violation Reporting System',
    logo: 'assets/images/mccLogo.png',
    flagKey: 'msdvInstalled',
    // Want real screenshots instead of the drawn illustrations?
    // Set a folder, e.g. 'assets/install', and add files named:
    //   ios-1.png ... ios-4.png, android-1.png ... android-4.png, pc-1.png ... pc-3.png
    // Any missing file automatically falls back to the drawn illustration.
    screenshotDir: null,
    // Hosts where the gate is skipped. Empty = the gate shows everywhere.
    // Example for development only: ['localhost', '127.0.0.1']
    devHosts: []
  };

  function log(msg) { try { console.info('[MSDV gate] ' + msg); } catch (e) {} }

  /* ---------- HELPERS ---------- */
  function getFlag() { try { return localStorage.getItem(CFG.flagKey) === '1'; } catch (e) { return false; } }
  function setFlag() { try { localStorage.setItem(CFG.flagKey, '1'); } catch (e) {} }
  function clearFlag() { try { localStorage.removeItem(CFG.flagKey); } catch (e) {} }

  function isStandalone() {
    return (window.matchMedia && (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.matchMedia('(display-mode: fullscreen)').matches ||
      window.matchMedia('(display-mode: minimal-ui)').matches
    )) || window.navigator.standalone === true ||
      (document.referrer || '').indexOf('android-app://') === 0;
  }

  // Running as the installed app: remember it and let the page load normally.
  if (isStandalone()) { setFlag(); log('running as installed app, gate skipped'); return; }
  if (CFG.devHosts.indexOf(location.hostname) !== -1) { log('dev host, gate skipped'); return; }

  // Hide the page immediately so nothing flashes before the gate mounts.
  document.documentElement.classList.add('msdv-locked');
  log('gate active');

  /* ---------- PLATFORM DETECTION ---------- */
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isAndroid = /android/i.test(ua);
  var platform = isIOS ? 'ios' : (isAndroid ? 'android' : 'pc');
  // Facebook, Messenger, Instagram, TikTok... cannot install web apps.
  var inApp = /FBAN|FBAV|FB_IAB|Messenger|Instagram|Line\/|TikTok|Snapchat|Twitter|; wv\)/i.test(ua);
  var HOST = location.host || 'your-site';

  /* ---------- INSTALL EVENTS (register right away) ---------- */
  var deferred = null;
  var gate = null;
  var state = getFlag() ? 'installed' : 'intro';
  var tab = platform;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    log('install prompt available');
  });
  window.addEventListener('appinstalled', function () {
    deferred = null;
    setFlag();
    state = 'installed';
    draw();
  });

  /* ---------- ILLUSTRATIONS (inline SVG) ---------- */
  var NAVY = '#0a1d4d', RED = '#e03a3e', BLUE = '#2f6bff', GOLD = '#e8b21c';
  var S = 'fill="none" stroke="#2f6bff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

  function svg(inner) {
    return '<svg viewBox="0 0 240 150" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">' + inner + '</svg>';
  }
  function t(x, y, s, o) {
    o = o || {};
    return '<text x="' + x + '" y="' + y + '" font-size="' + (o.size || 11) + '" fill="' + (o.fill || '#0f1b3d') +
      '" text-anchor="' + (o.anchor || 'start') + '" font-weight="' + (o.w || 400) +
      '" font-family="Arial,Helvetica,sans-serif">' + s + '</text>';
  }
  function bg(fill) { return '<rect width="240" height="150" rx="12" fill="' + (fill || '#e9edf6') + '"/>'; }
  function ring(cx, cy, r) {
    return '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + RED + '" stroke-width="3">' +
      '<animate attributeName="r" values="' + r + ';' + (r + 4) + ';' + r + '" dur="1.3s" repeatCount="indefinite"/></circle>';
  }
  function ringR(x, y, w, h, rx) {
    return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + (rx || 8) +
      '" fill="none" stroke="' + RED + '" stroke-width="3"/>';
  }
  function lines(y) {
    return '<rect x="24" y="' + y + '" width="110" height="8" rx="4" fill="#cfd7e8"/>' +
      '<rect x="24" y="' + (y + 16) + '" width="190" height="6" rx="3" fill="#dde3f0"/>' +
      '<rect x="24" y="' + (y + 28) + '" width="160" height="6" rx="3" fill="#dde3f0"/>';
  }
  function appIcon(x, y, s) {
    return '<rect x="' + x + '" y="' + y + '" width="' + s + '" height="' + s + '" rx="' + Math.round(s * 0.23) + '" fill="' + NAVY + '"/>' +
      t(x + s / 2, y + s * 0.68, 'M', { anchor: 'middle', fill: GOLD, w: 700, size: Math.round(s * 0.55) });
  }
  function homeScreen() {
    var out = '<rect width="240" height="150" rx="12" fill="#2a4796"/>';
    var xs = [26, 76, 126, 176], ys = [16, 80];
    ys.forEach(function (y, r) {
      xs.forEach(function (x, c) {
        if (r === 1 && c === 0) return;
        out += '<rect x="' + x + '" y="' + y + '" width="38" height="38" rx="9" fill="#fff" fill-opacity=".22"/>';
      });
    });
    out += appIcon(26, 80, 38) + t(45, 132, CFG.appName, { anchor: 'middle', fill: '#fff', size: 9 }) + ringR(19, 74, 52, 64, 12);
    return svg(out);
  }

  /* iPhone / iPad */
  function iosShare() {
    return svg(bg() + lines(16) +
      '<path d="M0 104H240V138A12 12 0 0 1 228 150H12A12 12 0 0 1 0 138Z" fill="#f8f9fc" stroke="#d8deeb"/>' +
      '<path d="M32 119L25 127L32 135M74 119L81 127L74 135" ' + S + '/>' +
      '<path d="M120 130V113M114 119L120 113L126 119M114 124H110V141H130V124H126" ' + S + '/>' +
      '<rect x="158" y="119" width="20" height="15" rx="2" ' + S + '/>' +
      '<rect x="203" y="119" width="15" height="15" rx="2" ' + S + '/>' +
      ring(120, 128, 19));
  }
  function iosSheet() {
    return svg(bg('#c9cfdd') + '<rect x="26" y="12" width="188" height="128" rx="14" fill="#fff"/>' +
      t(42, 36, 'Copy') + '<line x1="26" y1="48" x2="214" y2="48" stroke="#e3e8f2"/>' +
      t(42, 66, 'Add to Bookmarks') + '<line x1="26" y1="78" x2="214" y2="78" stroke="#e3e8f2"/>' +
      '<rect x="30" y="82" width="180" height="30" rx="8" fill="#fff0f0" stroke="' + RED + '" stroke-width="2.5"/>' +
      t(42, 101, 'Add to Home Screen', { w: 700 }) +
      '<rect x="184" y="90" width="16" height="16" rx="3" fill="none" stroke="#0f1b3d" stroke-width="1.6"/>' +
      '<path d="M192 94V102M188 98H196" stroke="#0f1b3d" stroke-width="1.6"/>' +
      '<line x1="26" y1="116" x2="214" y2="116" stroke="#e3e8f2"/>' + t(42, 132, 'Find on Page'));
  }
  function iosAdd() {
    return svg(bg('#c9cfdd') + '<rect x="20" y="14" width="200" height="122" rx="14" fill="#fff"/>' +
      t(34, 40, 'Cancel', { fill: '#6b7690', size: 10 }) +
      t(120, 40, 'Add to Home Screen', { anchor: 'middle', w: 700, size: 9 }) +
      t(206, 40, 'Add', { anchor: 'end', fill: BLUE, w: 700, size: 11 }) + ringR(178, 26, 36, 20, 10) +
      appIcon(36, 62, 40) +
      '<rect x="86" y="64" width="124" height="36" rx="8" fill="#f2f5fb"/>' + t(96, 86, CFG.appName, { w: 700, size: 12 }) +
      t(36, 122, HOST, { size: 9, fill: '#6b7690' }));
  }

  /* Android */
  function andChrome() {
    return svg(bg() + lines(52) +
      '<path d="M0 12A12 12 0 0 1 12 0H228A12 12 0 0 1 240 12V36H0Z" fill="#fff" stroke="#d8deeb"/>' +
      '<rect x="12" y="8" width="170" height="20" rx="10" fill="#eef1f8"/>' + t(24, 22, HOST, { size: 10, fill: '#6b7690' }) +
      '<circle cx="212" cy="10" r="2.3" fill="#0f1b3d"/><circle cx="212" cy="18" r="2.3" fill="#0f1b3d"/><circle cx="212" cy="26" r="2.3" fill="#0f1b3d"/>' +
      ring(212, 18, 15));
  }
  function andMenu() {
    return svg(bg('#c9cfdd') + '<rect x="96" y="8" width="136" height="134" rx="10" fill="#fff" stroke="#d8deeb"/>' +
      t(110, 32, 'New tab') + t(110, 54, 'New Incognito tab') +
      '<rect x="100" y="66" width="128" height="26" rx="6" fill="#fff0f0" stroke="' + RED + '" stroke-width="2.5"/>' +
      t(110, 83, 'Install app', { w: 700 }) + t(110, 112, 'Share…') + t(110, 132, 'Find in page'));
  }
  function andDialog() {
    return svg(bg('#c9cfdd') + '<rect x="20" y="30" width="200" height="92" rx="16" fill="#fff"/>' +
      appIcon(36, 44, 30) + t(76, 57, 'Install app?', { w: 700, size: 12 }) + t(76, 72, HOST, { size: 9, fill: '#6b7690' }) +
      t(110, 106, 'Cancel', { anchor: 'middle', fill: BLUE }) +
      '<rect x="150" y="90" width="56" height="24" rx="12" fill="' + BLUE + '"/>' +
      t(178, 106, 'Install', { anchor: 'middle', fill: '#fff', w: 700 }) + ringR(146, 86, 64, 32, 16));
  }

  /* PC */
  function pcChrome() {
    return '<rect width="240" height="150" rx="10" fill="#e9edf6"/>' +
      '<path d="M0 10A10 10 0 0 1 10 0H230A10 10 0 0 1 240 10V24H0Z" fill="#dfe4f0"/>' +
      '<circle cx="12" cy="12" r="3" fill="#e03a3e"/><circle cx="24" cy="12" r="3" fill="#e8b21c"/><circle cx="36" cy="12" r="3" fill="#16a34a"/>' +
      '<rect x="0" y="24" width="240" height="28" fill="#f8f9fc"/>' +
      '<rect x="14" y="30" width="192" height="16" rx="8" fill="#fff" stroke="#d8deeb"/>' + t(26, 42, HOST, { size: 9, fill: '#6b7690' }) +
      '<circle cx="226" cy="33" r="1.6" fill="#0f1b3d"/><circle cx="226" cy="38" r="1.6" fill="#0f1b3d"/><circle cx="226" cy="43" r="1.6" fill="#0f1b3d"/>';
  }
  function pcIcon() {
    return svg(pcChrome() + lines(64) +
      '<g ' + S + '><rect x="178" y="32" width="14" height="10" rx="1.5"/><path d="M185 34V39M182.5 36.5L185 39L187.5 36.5"/></g>' +
      ring(185, 38, 12));
  }
  function pcDialog() {
    return svg(pcChrome() +
      '<rect x="108" y="54" width="124" height="88" rx="10" fill="#fff" stroke="#c9cfdd"/>' +
      appIcon(120, 64, 26) + t(154, 76, 'Install app?', { w: 700, size: 10 }) + t(154, 88, HOST, { size: 8, fill: '#6b7690' }) +
      t(136, 127, 'Cancel', { anchor: 'middle', fill: BLUE, size: 10 }) +
      '<rect x="160" y="112" width="60" height="22" rx="11" fill="' + BLUE + '"/>' +
      t(190, 127, 'Install', { anchor: 'middle', fill: '#fff', w: 700, size: 10 }) + ringR(156, 108, 68, 30, 15));
  }
  function pcApp() {
    return svg(bg() + '<rect x="28" y="14" width="184" height="122" rx="8" fill="#fff" stroke="#c9cfdd"/>' +
      '<path d="M28 22A8 8 0 0 1 36 14H204A8 8 0 0 1 212 22V36H28Z" fill="' + NAVY + '"/>' +
      '<rect x="36" y="20" width="12" height="12" rx="3" fill="#16337a"/>' + t(54, 30, CFG.appName, { fill: '#fff', w: 700, size: 10 }) +
      '<rect x="44" y="54" width="100" height="10" rx="5" fill="#cfd7e8"/>' +
      '<rect x="44" y="72" width="150" height="7" rx="3" fill="#dde3f0"/>' +
      '<rect x="44" y="86" width="120" height="7" rx="3" fill="#dde3f0"/>' +
      t(120, 122, 'Opens in its own window', { anchor: 'middle', size: 10, fill: '#6b7690' }));
  }

  /* ---------- TUTORIAL CONTENT ---------- */
  var GUIDES = {
    ios: {
      label: 'iPhone / iPad',
      note: 'Use <b>Safari</b>. Installing does not work from Facebook, Messenger or other in-app browsers.',
      steps: [
        { t: 'Tap the Share button', d: 'In Safari, tap the Share icon (a square with an arrow pointing up) in the bottom bar. On iPad it is at the top.', art: iosShare },
        { t: 'Tap “Add to Home Screen”', d: 'Scroll down the list if you do not see it right away.', art: iosSheet },
        { t: 'Tap “Add”', d: 'Keep the name as ' + CFG.appName + ', then tap <b>Add</b> in the top-right corner.', art: iosAdd },
        { t: 'Open ' + CFG.appName + ' from your Home Screen', d: 'Close Safari, then tap the new ' + CFG.appName + ' icon. Log in from the app.', art: homeScreen }
      ]
    },
    android: {
      label: 'Android',
      note: 'Use <b>Chrome</b>. Installing does not work from Facebook, Messenger or other in-app browsers.',
      steps: [
        { t: 'Open the browser menu', d: 'Tap the three dots (⋮) at the top-right of Chrome.', art: andChrome },
        { t: 'Tap “Install app”', d: 'Some phones show “Add to Home screen” instead. Choose that one. On Samsung Internet: tap ≡ → Add page to → Home screen.', art: andMenu },
        { t: 'Tap “Install”', d: 'Confirm in the pop-up. The app is added to your phone.', art: andDialog },
        { t: 'Open ' + CFG.appName, d: 'Find the ' + CFG.appName + ' icon on your home screen or in your app drawer, then log in from the app.', art: homeScreen }
      ]
    },
    pc: {
      label: 'PC / Laptop',
      note: 'Use <b>Google Chrome</b> or <b>Microsoft Edge</b>. Firefox cannot install web apps.',
      steps: [
        { t: 'Click the install icon', d: 'Look at the right end of the address bar for the install icon (a screen with a down arrow). Not there? Chrome: ⋮ → Cast, save and share → Install page as app. Edge: ⋯ → Apps → Install this site as an app.', art: pcIcon },
        { t: 'Click “Install”', d: 'Confirm in the small pop-up window.', art: pcDialog },
        { t: 'Open the ' + CFG.appName + ' app', d: 'It opens in its own window and is added to your Start menu / Applications. Use it to log in from now on.', art: pcApp }
      ]
    }
  };

  /* ---------- STYLES ---------- */
  var CSS = [
    'html.msdv-locked{overflow:hidden!important}',
    'html.msdv-locked body>*:not(#msdvGate){display:none!important}',
    '#msdvGate,#msdvGate *{box-sizing:border-box}',
    '#msdvGate{position:fixed;inset:0;z-index:2147483000;display:flex;overflow-y:auto;-webkit-overflow-scrolling:touch;',
    'background:linear-gradient(180deg,#061238 0%,#0a1d4d 60%,#061238 100%);color:#fff;',
    'font-family:"Oswald",sans-serif;padding:max(28px,env(safe-area-inset-top)) 16px max(28px,env(safe-area-inset-bottom));border-bottom:4px solid #e03a3e}',
    '#msdvGate .g-card{margin:auto;width:100%;max-width:560px;text-align:center}',
    '#msdvGate .g-logo{width:84px;height:84px;margin:0 auto 18px;border-radius:50%;overflow:hidden;background:#16337a;border:3px solid #2a4796;box-shadow:0 0 0 8px rgba(47,107,255,.12)}',
    '#msdvGate .g-logo img{width:100%;height:100%;object-fit:cover;display:block}',
    '#msdvGate h1{font-size:clamp(1.8rem,7vw,2.5rem);font-weight:700;line-height:1.1;letter-spacing:-.01em;margin:0 0 6px}',
    '#msdvGate .g-sub{color:#9fb0d8;font-size:14px;margin:0 0 14px}',
    '#msdvGate .g-p{color:#cdd8f3;font-size:16px;line-height:1.5;max-width:40ch;margin:0 auto 22px}',
    '#msdvGate .g-btn{display:inline-flex;align-items:center;justify-content:center;min-width:220px;min-height:52px;padding:12px 28px;border:2px solid #e03a3e;border-radius:12px;background:#e03a3e;color:#fff;font:700 17px "Oswald",sans-serif;cursor:pointer;transition:transform .15s,background .15s}',
    '#msdvGate .g-btn:hover{background:#c92f33}',
    '#msdvGate .g-btn:active{transform:scale(.97)}',
    '#msdvGate .g-btn.alt{background:transparent;border-color:#3a56a3}',
    '#msdvGate .g-btn.alt:hover{background:#0e2760}',
    '#msdvGate :focus-visible{outline:3px solid #e8b21c;outline-offset:3px}',
    '#msdvGate .g-warn{background:#fff7e6;color:#7a4300;border-radius:12px;padding:14px 16px;text-align:left;font-size:15px;line-height:1.5;margin:0 0 18px}',
    '#msdvGate .g-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:28px 0 14px}',
    '#msdvGate .g-tab{background:#0e2760;color:#cdd8f3;border:1px solid #2a4796;border-radius:10px;padding:11px 6px;font:600 14px "Oswald",sans-serif;cursor:pointer}',
    '#msdvGate .g-tab.on{background:#fff;color:#0a1d4d;border-color:#fff}',
    '#msdvGate .g-note{color:#b9c7ec;font-size:14px;line-height:1.5;margin:0 0 14px}',
    '#msdvGate .g-note b{color:#e8b21c;font-weight:600}',
    '#msdvGate .g-list{list-style:none;display:grid;gap:14px;margin:0 0 22px;padding:0;text-align:left}',
    '#msdvGate .g-step{background:#fff;color:#0f1b3d;border-radius:16px;overflow:hidden}',
    '#msdvGate .g-art{background:#e9edf6;padding:12px}',
    '#msdvGate .g-art svg,#msdvGate .g-art img{display:block;width:100%;height:auto;border-radius:8px}',
    '#msdvGate .g-txt{padding:12px 16px 16px}',
    '#msdvGate .g-txt b{display:flex;gap:10px;align-items:center;font-size:18px;font-weight:600}',
    '#msdvGate .g-txt b span{flex:none;width:26px;height:26px;border-radius:8px;background:#0a1d4d;color:#fff;display:grid;place-items:center;font-size:13px}',
    '#msdvGate .g-txt p{color:#4c5873;font-size:15px;line-height:1.5;margin:6px 0 0}',
    '#msdvGate .g-ok{width:84px;height:84px;margin:0 auto 18px;border-radius:50%;background:#16a34a;display:grid;place-items:center;box-shadow:0 0 0 8px rgba(22,163,74,.18)}',
    '#msdvGate .g-foot{color:#7f90b8;font-size:12.5px;margin-top:22px}',
    '@media (prefers-reduced-motion:reduce){#msdvGate .g-btn{transition:none}}'
  ].join('');

  /* ---------- RENDERING ---------- */
  function intro() {
    var h = '<div class="g-logo"><img src="' + CFG.logo + '" alt="' + CFG.appName + ' logo"></div>' +
      '<h1>Install ' + CFG.appName + ' to continue</h1>' +
      '<p class="g-sub">' + CFG.fullName + '</p>';
    if (inApp) {
      h += '<div class="g-warn"><b>Open this page in your browser first.</b><br>You are inside another app (like Facebook or Messenger), which cannot install ' + CFG.appName +
        '. Tap the ⋯ or ⋮ menu and choose <b>Open in browser</b> (Safari on iPhone, Chrome on Android), or copy the link and paste it there.</div>' +
        '<button class="g-btn" type="button" data-act="copy">Copy link</button>';
    } else if (state === 'intro') {
      h += '<p class="g-p">' + CFG.appName + ' works as an installed app. Install it on your device to log in and use the system.</p>' +
        '<button class="g-btn" type="button" data-act="install" id="msdvInstallBtn">Install App</button>';
    }
    return h;
  }

  function steps() {
    var g = GUIDES[tab];
    var h = '<div class="g-tabs" role="tablist" aria-label="Choose your device">' +
      ['ios', 'android', 'pc'].map(function (k) {
        return '<button type="button" role="tab" class="g-tab' + (k === tab ? ' on' : '') + '" data-tab="' + k +
          '" aria-selected="' + (k === tab) + '">' + GUIDES[k].label + '</button>';
      }).join('') + '</div>';
    h += '<p class="g-note">' + g.note + '</p><ol class="g-list">' +
      g.steps.map(function (s, i) {
        var art = CFG.screenshotDir
          ? '<img src="' + CFG.screenshotDir + '/' + tab + '-' + (i + 1) + '.png" alt="Step ' + (i + 1) + ': ' + s.t + '" data-i="' + i + '">'
          : s.art();
        return '<li class="g-step"><div class="g-art">' + art + '</div><div class="g-txt"><b><span>' + (i + 1) + '</span>' + s.t + '</b><p>' + s.d + '</p></div></li>';
      }).join('') + '</ol>' +
      '<button class="g-btn alt" type="button" data-act="done">I have installed it</button>' +
      '<p class="g-foot">After installing, open ' + CFG.appName + ' from its new icon. This page stays locked in the browser.</p>';
    return h;
  }

  function installed() {
    return '<div class="g-ok"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>' +
      '<h1>' + CFG.appName + ' is installed</h1>' +
      '<p class="g-p">Close this tab and open <b>' + CFG.appName + '</b> from your home screen, app list or Start menu to log in.</p>' +
      '<button class="g-btn alt" type="button" data-act="reset">Not installed? Show the steps</button>';
  }

  function draw() {
    if (!gate) return;
    var h = state === 'installed' ? installed() : intro() + (state === 'steps' ? steps() : '');
    gate.innerHTML = '<div class="g-card">' + h + '</div>';
  }

  function scrollToSteps() {
    var el = gate && gate.querySelector('.g-tabs');
    if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function install() {
    if (deferred) {
      var d = deferred;
      deferred = null;
      d.prompt();
      d.userChoice.then(function (r) {
        if (r && r.outcome === 'accepted') { setFlag(); state = 'installed'; draw(); }
        else { state = 'steps'; draw(); scrollToSteps(); }
      });
      return;
    }
    state = 'steps';
    draw();
    scrollToSteps();
  }

  function onClick(e) {
    var el = e.target.closest ? e.target.closest('[data-act],[data-tab]') : null;
    if (!el) return;
    if (el.getAttribute('data-tab')) { tab = el.getAttribute('data-tab'); draw(); return; }
    var act = el.getAttribute('data-act');
    if (act === 'install') install();
    else if (act === 'done') { state = 'installed'; draw(); }
    else if (act === 'reset') { clearFlag(); state = 'steps'; draw(); scrollToSteps(); }
    else if (act === 'copy') {
      var done = function () { el.textContent = 'Link copied'; };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, function () {});
      else done();
    }
  }

  // Missing screenshot file -> fall back to the drawn illustration.
  function onImgError(e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG' || img.getAttribute('data-i') === null) return;
    var step = GUIDES[tab].steps[+img.getAttribute('data-i')];
    if (step && img.parentNode) img.parentNode.innerHTML = step.art();
  }

  function mount() {
    try {
      if (document.getElementById('msdvGate')) return;
      var style = document.createElement('style');
      style.textContent = CSS;
      document.head.appendChild(style);

      gate = document.createElement('div');
      gate.id = 'msdvGate';
      gate.setAttribute('role', 'dialog');
      gate.setAttribute('aria-modal', 'true');
      gate.setAttribute('aria-label', 'Install ' + CFG.appName);
      gate.addEventListener('click', onClick);
      gate.addEventListener('error', onImgError, true);
      document.body.appendChild(gate);

      draw();
      log('gate shown (' + state + ', ' + platform + ')');
      var first = gate.querySelector('.g-btn');
      if (first) first.focus({ preventScroll: true });
    } catch (err) {
      // Never leave the visitor with a blank page if something goes wrong.
      document.documentElement.classList.remove('msdv-locked');
      try { console.error('[MSDV gate] failed to start:', err); } catch (e) {}
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
