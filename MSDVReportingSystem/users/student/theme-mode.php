<?php
/* =========================================================
   theme-mode.php   (Light / Dark / Auto mode for STUDENT pages)
   Include this ONCE per page, INSIDE <head>, after the page's
   own <style> / <link> tags and before </head>:

       <?php require_once 'theme-mode.php'; ?>

   - Adds a sun/moon toggle button automatically:
       * inside .topbar-right (or .navbar-right) if the page has one
       * otherwise a floating button at the bottom-right
   - Saved per browser (same key as the admin theme-head.php).
   - Own button? Add  data-mode-toggle  to any element.
   - Style extra things in dark mode with:
       [data-mode="dark"] .my-class { ... }
   ========================================================= */

if (defined('MDSV_MODE_LOADED')) return;
define('MDSV_MODE_LOADED', true);
?>
<style id="mdsv-mode">
/* ── DARK MODE: page variables ─────────────────────────────
   Selector is html:root[...] so it wins no matter where the
   page defines its own :root variables.                     */
html:root[data-mode="dark"]{
    color-scheme:dark;

    /* student pages (warm dark palette) */
    --bg:#141311;
    --surface:#1d1c1a;
    --border:#33312d;
    --border-focus:#efede8;
    --text-primary:#efede8;
    --text-secondary:#b5b1a8;
    --text-muted:#8a867e;

    --accent-light:rgba(239,68,68,.14);
    --accent-border:rgba(248,113,113,.40);
    --danger:#f87171;
    --danger-light:rgba(239,68,68,.14);
    --danger-border:rgba(248,113,113,.35);
    --success:#6ee7a1;
    --success-light:rgba(34,197,94,.14);
    --warn:#fbbf24;
    --warn-light:rgba(245,158,11,.14);
    --minor-bg:rgba(59,130,246,.16);
    --minor-color:#8ab4ff;
    --shadow-md:0 4px 16px rgba(0,0,0,.5);

    /* admin-style variable names (so shared pages also work) */
    --text:var(--text-primary); --text-2:var(--text-secondary); --text-3:var(--text-muted);
    --border-soft:var(--border);
}

/* ── DARK MODE: things the variables can't reach ──────────── */
[data-mode="dark"] .topbar-brand,
[data-mode="dark"] .profile-avatar,
[data-mode="dark"] .appeal-btn,
[data-mode="dark"] .sb-link.active,
[data-mode="dark"] .sb-user-role,
[data-mode="dark"] .icon-btn:hover{color:#f87171}
[data-mode="dark"] .appeal-btn{border-color:#f87171}
[data-mode="dark"] .alert-success,
[data-mode="dark"] .empty-icon{border-color:rgba(34,197,94,.35)}
[data-mode="dark"] .warn-badge{background:rgba(245,158,11,.18)}
[data-mode="dark"] .warn-badge svg{color:#fbbf24}
[data-mode="dark"] .password-strength-track{background:#33312d}
[data-mode="dark"] #passwordStrengthFill,
[data-mode="dark"] #passwordStrengthLabel{filter:brightness(1.5)}
[data-mode="dark"] .password-match-status.match{color:#4ade80}
[data-mode="dark"] .password-match-status.no-match{color:#f87171}
[data-mode="dark"] textarea:focus,
[data-mode="dark"] input[type="password"]:focus{box-shadow:0 0 0 3px rgba(239,237,232,.10)}
[data-mode="dark"] ::placeholder{color:var(--text-muted);opacity:1}

/* ── DARK MODE: generic fallbacks for unstyled elements ────
   :where() = zero specificity, so any rule in the page's own
   CSS always wins over these.                               */
:where([data-mode="dark"]) :where(body){background:var(--bg,#141311);color:var(--text-primary,#efede8)}
:where([data-mode="dark"]) :where(a){color:#93b4ff}
:where([data-mode="dark"]) :where(h1,h2,h3,h4,h5,h6,label,legend){color:var(--text-primary,#efede8)}
:where([data-mode="dark"]) :where(hr){border-color:var(--border,#33312d)}
:where([data-mode="dark"]) :where(table){background:var(--surface,#1d1c1a);color:var(--text-primary,#efede8);border-color:var(--border,#33312d)}
:where([data-mode="dark"]) :where(th){background:rgba(255,255,255,.04);color:var(--text-primary,#efede8);border-color:var(--border,#33312d)}
:where([data-mode="dark"]) :where(td){color:var(--text-primary,#efede8);border-color:var(--border,#33312d)}
:where([data-mode="dark"]) :where(input:not([type=checkbox],[type=radio],[type=submit],[type=button],[type=color]),select,textarea){
    background-color:var(--surface,#1d1c1a);color:var(--text-primary,#efede8);border:1px solid var(--border,#33312d);
}

/* ── TOGGLE BUTTON ─────────────────────────────────────── */
.mdsv-mode-btn{
    display:inline-flex;align-items:center;justify-content:center;
    width:34px;height:34px;padding:0;border-radius:50%;cursor:pointer;flex-shrink:0;
    background:#fff;color:#1a1a1a;border:1px solid #e2e0db;
    -webkit-tap-highlight-color:transparent;
}
.mdsv-mode-btn:hover{background:#f5f4f0}
.mdsv-mode-btn:focus-visible{outline:2px solid #b91c1c;outline-offset:2px}
.mdsv-mode-btn svg{width:17px;height:17px}
[data-mode="dark"] .mdsv-mode-btn{background:#1d1c1a;color:#efede8;border-color:#33312d}
[data-mode="dark"] .mdsv-mode-btn:hover{background:#2a2825}
.mdsv-mode-float{position:fixed;right:16px;bottom:16px;z-index:99999;width:40px;height:40px;box-shadow:0 2px 8px rgba(0,0,0,.25)}
</style>

<script>
/* Light / Dark / Auto — saved per browser, applied before first paint */
(function(){
    var KEY='mdsv_mode', m='light', mq=window.matchMedia('(prefers-color-scheme: dark)');
    try{ var s=localStorage.getItem(KEY); if(s==='dark'||s==='light'||s==='auto') m=s; }catch(e){}
    function eff(){ return m==='auto' ? (mq.matches?'dark':'light') : m; }

    var SUN='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    var MOON='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';

    function apply(){
        var e=eff(), r=document.documentElement;
        r.setAttribute('data-mode',e);
        r.setAttribute('data-bs-theme',e);
        var btns=document.querySelectorAll('[data-mode-toggle]');
        for(var i=0;i<btns.length;i++){
            var t=e==='dark'?'Switch to light mode':'Switch to dark mode';
            btns[i].setAttribute('aria-label',t); btns[i].title=t;
            if(btns[i].classList.contains('mdsv-mode-btn')) btns[i].innerHTML=e==='dark'?SUN:MOON;
        }
        document.dispatchEvent(new CustomEvent('mdsv:mode',{detail:e}));
    }

    window.MDSV_THEME={
        get mode(){return m;},
        get effective(){return eff();},
        set:function(x){ m=x; try{localStorage.setItem(KEY,x);}catch(e){} apply(); },
        toggle:function(){ this.set(eff()==='dark'?'light':'dark'); }
    };

    /* apply immediately = no white flash */
    var r=document.documentElement;
    r.setAttribute('data-mode',eff()); r.setAttribute('data-bs-theme',eff());
    (mq.addEventListener?mq.addEventListener.bind(mq,'change'):mq.addListener.bind(mq))(function(){ if(m==='auto') apply(); });

    document.addEventListener('DOMContentLoaded',function(){
        if(!document.querySelector('[data-mode-toggle]')){
            var b=document.createElement('button');
            b.type='button'; b.className='mdsv-mode-btn'; b.setAttribute('data-mode-toggle','');
            var host=document.querySelector('.topbar-right, .navbar-right');
            if(host){ host.insertBefore(b,host.firstChild); }
            else { b.className+=' mdsv-mode-float'; document.body.appendChild(b); }
        }
        document.addEventListener('click',function(ev){
            var t=ev.target.closest&&ev.target.closest('[data-mode-toggle]');
            if(t){ ev.preventDefault(); MDSV_THEME.toggle(); }
        });
        apply();
    });
})();
</script>
