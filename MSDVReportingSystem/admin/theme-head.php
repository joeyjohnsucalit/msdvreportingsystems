<?php
/* =========================================================
   theme-head.php
   Include this ONCE per admin page, right before </head>
   (it must come AFTER the page's own <style> / <link> tags):

       <?php require_once 'theme-head.php'; ?>
   ========================================================= */

if (!function_exists('mdsv_themes')) {
    function mdsv_themes() {
        return [
            'navy' => [
                'name'   => 'Navy',
                'note'   => 'Default system color',
                'main'   => '#091b4b',
                'bar'    => '#c8322b',
                'accent' => '#2563eb',
                'hover'  => '#1d4ed8',
                'light'  => '#eff4ff',
                'label'  => '#b8960c',
                'text'   => '#c8cce0',
                'sub'    => '#8a90b0',
            ],
            'ember' => [
                'name'   => 'Ember',
                'note'   => 'Muted terracotta',
                'main'   => '#bf5540',
                'bar'    => '#6e2a1c',
                'accent' => '#a8442f',
                'hover'  => '#8d3624',
                'light'  => '#fbeeea',
                'label'  => '#f1d29a',
                'text'   => '#f8e6e0',
                'sub'    => '#e9bfb3',
                /* warm neutral family: sand-cream surfaces, clay borders, dark roasted-brown dark mode */
                'nbg'    => '#faf5f2', 'nborder' => '#eddfd8', 'nsoft'  => '#f5ebe6', 'nrow' => '#fbf3ef',
                'ntext'  => '#2b1a15', 'ntext2'  => '#7a645b', 'ntext3' => '#a8958c',
                'dbg'    => '#17100e', 'dsurface'=> '#211815', 'dborder'=> '#3a2b26', 'dsoft' => '#2c211d',
                'dtext'  => '#f4ece8', 'dtext2'  => '#c3b0a7', 'dtext3' => '#8f7d74',
                'family' => ['#bf5540', '#6e2a1c', '#a8442f', '#e0b25a', '#faf5f2'],
            ],
            'forest' => [
                'name'   => 'Forest',
                'note'   => 'Deep green with gold',
                'main'   => '#0b5d4b',
                'bar'    => '#d4a017',
                'accent' => '#0f766e',
                'hover'  => '#0b5f58',
                'light'  => '#e6f6f3',
                'label'  => '#f2d27a',
                'text'   => '#d6ece6',
                'sub'    => '#9fcfc3',
                'nbg'    => '#f4f8f6', 'nborder' => '#dde9e4', 'nsoft'  => '#eaf2ee', 'nrow' => '#f6faf8',
                'ntext'  => '#10201b', 'ntext2'  => '#5d756c', 'ntext3' => '#93a9a1',
                'dbg'    => '#0c1512', 'dsurface'=> '#131f1b', 'dborder'=> '#243630', 'dsoft' => '#1a2a25',
                'dtext'  => '#e5efeb', 'dtext2'  => '#a6bbb3', 'dtext3' => '#74897f',
                'family' => ['#0b5d4b', '#d4a017', '#0f766e', '#f2d27a', '#f4f8f6'],
            ],
            'royal' => [
                'name'   => 'Royal',
                'note'   => 'Purple with gold',
                'main'   => '#4c2a85',
                'bar'    => '#e0a82e',
                'accent' => '#6d3fc0',
                'hover'  => '#5a31a3',
                'light'  => '#f3eefc',
                'label'  => '#f1d68a',
                'text'   => '#e6def5',
                'sub'    => '#b9a8dc',
                'nbg'    => '#f8f6fb', 'nborder' => '#e6e0f0', 'nsoft'  => '#f0ecf7', 'nrow' => '#f9f7fd',
                'ntext'  => '#1c1530', 'ntext2'  => '#6b6384', 'ntext3' => '#9d96b3',
                'dbg'    => '#110d1c', 'dsurface'=> '#1a1527', 'dborder'=> '#2f2745', 'dsoft' => '#231d36',
                'dtext'  => '#ece8f6', 'dtext2'  => '#b2aacb', 'dtext3' => '#7f7899',
                'family' => ['#4c2a85', '#e0a82e', '#6d3fc0', '#c9b6f0', '#f8f6fb'],
            ],
            'ocean' => [
                'name'   => 'Ocean',
                'note'   => 'Calm blue with sky',
                'main'   => '#0a5c8f',
                'bar'    => '#38bdf8',
                'accent' => '#0369a1',
                'hover'  => '#075985',
                'light'  => '#e8f3fa',
                'label'  => '#9bd8f2',
                'text'   => '#dcedf7',
                'sub'    => '#9cc3dc',
                'nbg'    => '#f4f8fb', 'nborder' => '#dfe9f0', 'nsoft'  => '#ebf2f7', 'nrow' => '#f6fafc',
                'ntext'  => '#0f1f2c', 'ntext2'  => '#5b7080', 'ntext3' => '#92a4b2',
                'dbg'    => '#0b141c', 'dsurface'=> '#121d28', 'dborder'=> '#243546', 'dsoft' => '#1a2836',
                'dtext'  => '#e4edf4', 'dtext2'  => '#a7bac9', 'dtext3' => '#758899',
                'family' => ['#0a5c8f', '#38bdf8', '#0369a1', '#9bd8f2', '#f4f8fb'],
            ],
            'maroon' => [
                'name'   => 'Maroon',
                'note'   => 'Classic school maroon',
                'main'   => '#7a1f2b',
                'bar'    => '#d4a017',
                'accent' => '#9b2335',
                'hover'  => '#7d1b2a',
                'light'  => '#fbeef0',
                'label'  => '#f0d28a',
                'text'   => '#f5dfe2',
                'sub'    => '#d9a7ae',
                'nbg'    => '#fbf6f6', 'nborder' => '#eddcdd', 'nsoft'  => '#f6eaea', 'nrow' => '#fcf4f4',
                'ntext'  => '#2a1417', 'ntext2'  => '#7b6063', 'ntext3' => '#a99295',
                'dbg'    => '#170d0f', 'dsurface'=> '#211416', 'dborder'=> '#3b272a', 'dsoft' => '#2d1d20',
                'dtext'  => '#f5eced', 'dtext2'  => '#c7adb0', 'dtext3' => '#927a7d',
                'family' => ['#7a1f2b', '#d4a017', '#9b2335', '#f0d28a', '#fbf6f6'],
            ],
            'graphite' => [
                'name'   => 'Graphite',
                'note'   => 'Neutral dark with teal',
                'main'   => '#1f2937',
                'bar'    => '#14b8a6',
                'accent' => '#0f766e',
                'hover'  => '#115e59',
                'light'  => '#e7f5f3',
                'label'  => '#7dd3c8',
                'text'   => '#d1d5db',
                'sub'    => '#9ca3af',
                'nbg'    => '#f6f7f9', 'nborder' => '#e5e7eb', 'nsoft'  => '#f1f3f5', 'nrow' => '#fafbfc',
                'ntext'  => '#111827', 'ntext2'  => '#6b7280', 'ntext3' => '#9ca3af',
                'dbg'    => '#0e1116', 'dsurface'=> '#171b22', 'dborder'=> '#2a303b', 'dsoft' => '#1f242d',
                'dtext'  => '#e6e9ee', 'dtext2'  => '#a8b0bd', 'dtext3' => '#78818f',
                'family' => ['#1f2937', '#14b8a6', '#0f766e', '#7dd3c8', '#f6f7f9'],
            ],
        ];
    }
}

/* ---- read the saved (system-wide) color theme ---- */
$__themes   = mdsv_themes();
$__themeKey = 'navy';
if (isset($pdo)) {
    try {
        $__q = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'theme_color'");
        if ($__q && $__q->execute()) {
            $__v = $__q->fetchColumn();
            if ($__v && isset($__themes[$__v])) $__themeKey = $__v;
        }
    } catch (Throwable $e) { /* table not created yet -> default theme */ }
}
$__neutral = [
    'nbg'=>'#f6f7f9','nborder'=>'#eaecf0','nsoft'=>'#f2f4f7','nrow'=>'#fafbfc',
    'ntext'=>'#111827','ntext2'=>'#6b7280','ntext3'=>'#9ca3af',
    'dbg'=>'#0e131f','dsurface'=>'#161c2a','dborder'=>'#27304a','dsoft'=>'#1f2740',
    'dtext'=>'#e6e9f0','dtext2'=>'#a3acc0','dtext3'=>'#7c869c',
];
$__t = array_merge($__neutral, $__themes[$__themeKey]);
?>
<style id="mdsv-theme">
/* ── COLOR THEME ───────────────────────────────────────── */
html:root{
    --theme-main:<?= $__t['main'] ?>;
    --sidebar:var(--theme-main);
    --sidebar-red:<?= $__t['bar'] ?>;
    --accent:<?= $__t['accent'] ?>;
    --accent-hover:<?= $__t['hover'] ?>;
    --accent-light:<?= $__t['light'] ?>;
    --accent-text:var(--accent);
    --sb-label:<?= $__t['label'] ?>;
    --sb-text:<?= $__t['text'] ?>;
    --sb-sub:<?= $__t['sub'] ?>;
    --n-bg:<?= $__t['nbg'] ?>; --n-border:<?= $__t['nborder'] ?>; --n-soft:<?= $__t['nsoft'] ?>; --n-row:<?= $__t['nrow'] ?>;
    --n-text:<?= $__t['ntext'] ?>; --n-text2:<?= $__t['ntext2'] ?>; --n-text3:<?= $__t['ntext3'] ?>;
    --d-bg:<?= $__t['dbg'] ?>; --d-surface:<?= $__t['dsurface'] ?>; --d-border:<?= $__t['dborder'] ?>; --d-soft:<?= $__t['dsoft'] ?>;
    --d-text:<?= $__t['dtext'] ?>; --d-text2:<?= $__t['dtext2'] ?>; --d-text3:<?= $__t['dtext3'] ?>;
    --bg:var(--n-bg); --border:var(--n-border); --border-soft:var(--n-soft);
    --text:var(--n-text); --text-2:var(--n-text2); --text-3:var(--n-text3);
}
html .data-table tbody tr:hover,html .notif-item:hover{background:var(--n-row)}
html .sidebar,html .navbar{background:var(--sidebar)!important}
html .adm_logo,html .navbar{border-bottom-color:var(--sidebar-red)!important}
html .sidebar_content ul li a.active{border-left-color:var(--sidebar-red)!important}
html .sidebar_content h3{color:var(--sb-label)!important}
html .sidebar_content ul li a{color:var(--sb-text)}
html .sidebar_content ul li a:hover,html .sidebar_content ul li a.active{color:#fff}
html .logotext p,html .user-name{color:var(--sb-sub)}
html .notif-badge{border-color:var(--sidebar)}

html .filter-btn.primary,html .m-btn.primary,html .btn-add{background:var(--accent);border-color:var(--accent);color:#fff}
html .filter-btn.primary:hover,html .m-btn.primary:hover,html .btn-add:hover{background:var(--accent-hover)}
html .filter-input:focus,html .filter-select:focus,html .f-input:focus,html .f-select:focus{border-color:var(--accent)}
html .data-table td.sid,html .info-val.sid-val{color:var(--accent-text)}
html .unread-pill{background:var(--accent-light);color:var(--accent-text)}
html .notif-dot-ind{background:var(--accent)}
html .notif-avatar i,html .notif-item.unread .notif-avatar i{color:var(--accent-text)}
html .notif-item.unread .notif-avatar{background:var(--accent-light)}
html .tab-btn.active{color:var(--accent-text);border-bottom-color:var(--accent);background:var(--accent-light)}
html .view-btn:hover,html .m-btn.info-btn,html .update-btn,html .act-btn.pass{background:var(--accent-light);color:var(--accent-text)}
html .info-banner{background:var(--accent-light);color:var(--accent-text)}

/* navbar light/dark toggle (injected by script below) */
html #mdsvModeBtn{margin-right:2px}

/* ── DARK MODE ─────────────────────────────────────────── */
:root[data-mode="dark"]{
    color-scheme:dark;
    --sidebar:color-mix(in srgb,var(--theme-main) 68%,#000);
    --bg:var(--d-bg); --surface:var(--d-surface);
    --border:var(--d-border); --border-soft:var(--d-soft);
    --text:var(--d-text); --text-2:var(--d-text2); --text-3:var(--d-text3);
    --accent-light:color-mix(in srgb,var(--accent) 20%,transparent);
    --accent-text:color-mix(in srgb,var(--accent) 55%,#fff);
}
[data-mode="dark"] body{background:var(--bg);color:var(--text)}
[data-mode="dark"] .notif-item:hover,
[data-mode="dark"] .data-table tbody tr:hover{background:rgba(255,255,255,.04)}
[data-mode="dark"] .notif-item.unread{background:rgba(255,255,255,.035)}
[data-mode="dark"] .notif-item.unread:hover{background:rgba(255,255,255,.06)}
[data-mode="dark"] .data-table tbody tr.highlight{background:rgba(245,158,11,.12)}
[data-mode="dark"] .modal-footer{background:rgba(255,255,255,.03)}
[data-mode="dark"] .filter-input,
[data-mode="dark"] .filter-select,
[data-mode="dark"] .f-input,
[data-mode="dark"] .f-select,
[data-mode="dark"] .m-btn.ghost,
[data-mode="dark"] .view-btn,
[data-mode="dark"] .filter-btn.secondary{background-color:var(--surface);color:var(--text);border-color:var(--border)}
[data-mode="dark"] .m-btn.ghost:hover,
[data-mode="dark"] .filter-btn.secondary:hover{background-color:var(--bg)}
[data-mode="dark"] .filter-bar{box-shadow:none}
[data-mode="dark"] .modal-header.danger-head{background:rgba(220,38,38,.14)}
[data-mode="dark"] .modal-header.danger-head .modal-title{color:#fca5a5}
[data-mode="dark"] .alert-banner.success{background:rgba(22,163,74,.16);color:#86efac}
[data-mode="dark"] .alert-banner.error{background:rgba(220,38,38,.16);color:#fca5a5}
[data-mode="dark"] .risk-badge{background:rgba(255,255,255,.08)!important}
[data-mode="dark"] .act-btn.edit,[data-mode="dark"] .m-btn.warn{background:rgba(245,158,11,.15);color:#fcd34d;border-color:rgba(245,158,11,.35)}
[data-mode="dark"] .act-btn.danger{background:rgba(220,38,38,.15);color:#fca5a5;border-color:rgba(220,38,38,.35)}
[data-mode="dark"] .action-btn.approve{background:rgba(22,163,74,.15);color:#86efac;border-color:rgba(22,163,74,.35)}
[data-mode="dark"] .action-btn.reject{background:rgba(220,38,38,.15);color:#fca5a5;border-color:rgba(220,38,38,.35)}
[data-mode="dark"] .mini-badge.minor,[data-mode="dark"] .mini-badge.teaching,[data-mode="dark"] .role-badge.teacher{background:rgba(59,130,246,.18);color:#93c5fd}
[data-mode="dark"] .mini-badge.major,[data-mode="dark"] .mini-badge.rejected{background:rgba(239,68,68,.18);color:#fca5a5}
[data-mode="dark"] .mini-badge.completed,[data-mode="dark"] .mini-badge.approved,[data-mode="dark"] .role-badge.csu{background:rgba(34,197,94,.18);color:#86efac}
[data-mode="dark"] .mini-badge.ongoing,[data-mode="dark"] .role-badge.jassu{background:rgba(245,158,11,.18);color:#fcd34d}
[data-mode="dark"] .mini-badge.pending,[data-mode="dark"] .role-badge.student{background:rgba(148,163,184,.18);color:#cbd5e1}
[data-mode="dark"] .mini-badge.non_teaching{background:rgba(139,92,246,.2);color:#c4b5fd}
[data-mode="dark"] .role-badge.nurse{background:rgba(236,72,153,.18);color:#f9a8d4}
/* dashboard (styles live in admin_dashboard.css) */
[data-mode="dark"] .card,[data-mode="dark"] .kpi{background:var(--surface)!important;border-color:var(--border)!important;color:var(--text)}
[data-mode="dark"] .card-title,[data-mode="dark"] .kpi-lbl,[data-mode="dark"] .sec-head{color:var(--text)!important}
[data-mode="dark"] .yr-sel{background-color:var(--surface)!important;color:var(--text)!important;border-color:var(--border)!important}
</style>

<script>
/* Light / Dark / Auto — saved per browser, applied before first paint */
(function(){
    var KEY='mdsv_mode', m='light', mq=window.matchMedia('(prefers-color-scheme: dark)');
    try{ var s=localStorage.getItem(KEY); if(s==='dark'||s==='light'||s==='auto') m=s; }catch(e){}
    function eff(){ return m==='auto' ? (mq.matches?'dark':'light') : m; }
    function apply(){
        var e=eff(), r=document.documentElement;
        r.setAttribute('data-mode',e);
        r.setAttribute('data-bs-theme',e);
        var b=document.getElementById('mdsvModeBtn');
        if(b) b.innerHTML='<i class="fas '+(e==='dark'?'fa-sun':'fa-moon')+'"></i>';
        document.dispatchEvent(new CustomEvent('mdsv:mode',{detail:e}));
    }
    window.MDSV_THEME={
        get mode(){return m;},
        get effective(){return eff();},
        set:function(x){ m=x; try{localStorage.setItem(KEY,x);}catch(e){} apply(); }
    };
    var r=document.documentElement;
    r.setAttribute('data-mode',eff()); r.setAttribute('data-bs-theme',eff());
    (mq.addEventListener?mq.addEventListener.bind(mq,'change'):mq.addListener.bind(mq))(function(){ if(m==='auto') apply(); });

    document.addEventListener('DOMContentLoaded',function(){
        var host=document.querySelector('.navbar-right');
        if(host && !document.getElementById('mdsvModeBtn')){
            var b=document.createElement('button');
            b.id='mdsvModeBtn'; b.type='button'; b.className='bell-btn';
            b.title='Toggle light / dark mode';
            b.onclick=function(){ MDSV_THEME.set(eff()==='dark'?'light':'dark'); };
            host.insertBefore(b,host.firstChild);
        }
        apply();
    });
})();
</script>
