<?php
session_start();
require_once '../db/connection.php';
?>
<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* settings table (created on first visit) */
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
)");

/* load the shared theme block (also defines mdsv_themes()) */
ob_start();
require 'theme-head.php';
$themeHead = ob_get_clean();
$themes = mdsv_themes();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if (($_POST['action'] ?? '') === 'save_theme') {
    $key = $_POST['theme'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['err'] = 'Session expired. Please try again.';
    } elseif (!isset($themes[$key])) {
        $_SESSION['err'] = 'Invalid theme selected.';
    } else {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('theme_color', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key]);
        $_SESSION['msg'] = 'Theme changed to ' . $themes[$key]['name'] . '. It now applies to every admin page.';
    }
    header('Location: theme-settings.php'); exit;
}

$current = 'navy';
$q = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='theme_color'");
$q->execute();
$v = $q->fetchColumn();
if ($v && isset($themes[$v])) $current = $v;

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Theme Settings</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="notif-theme.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:#f6f7f9; --surface:#fff; --border:#eaecf0; --border-soft:#f2f4f7;
    --text:#111827; --text-2:#6b7280; --text-3:#9ca3af;
    --accent:#2563eb; --accent-light:#eff4ff;
    --sidebar:#091b4b; --sidebar-red:#c8322b;
    --r-sm:8px; --r-md:12px; --r-lg:16px;
}
body { display:flex; background:var(--bg); min-height:100vh; font-family:'Plus Jakarta Sans',sans-serif; color:var(--text); }
::-webkit-scrollbar { width:0; }

/* SIDEBAR (unchanged) */
.sidebar { width:260px; height:100vh; background:var(--sidebar); color:#fff; position:fixed; top:0; left:0; display:flex; flex-direction:column; z-index:999; box-shadow:1px 0 0 rgba(255,255,255,0.04); }
.adm_logo { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:3px solid var(--sidebar-red); flex-shrink:0; }
.adm_logo img { width:52px; height:52px; border-radius:50%; border:2px solid rgba(255,255,255,0.15); object-fit:cover; }
.logotext h2 { font-size:15px; font-weight:600; color:#fff; line-height:1.2; }
.logotext p  { font-size:10px; color:#8a90b0; margin-top:2px; }
.sidebar_content { flex:1; overflow-y:auto; padding:8px 0; }
.sidebar_content ul { list-style:none; padding:0; margin:0; }
.sidebar_content h3 { padding:14px 18px 5px; font-size:9.5px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; color:#b8960c; }
.sidebar_content ul li a { display:flex; align-items:center; gap:10px; padding:10px 18px; color:#c8cce0; text-decoration:none; font-size:13px; transition:all 0.18s; margin:1px 8px; border-radius:6px; }
.sidebar_content ul li a i { font-size:14px; width:18px; text-align:center; flex-shrink:0; }
.sidebar_content ul li a:hover { background:rgba(255,255,255,0.06); color:#fff; }
.sidebar_content ul li a.active { background:rgba(255,255,255,0.07); color:#fff; border-left:3px solid var(--sidebar-red); padding-left:15px; margin-left:8px; }

/* MAIN + NAVBAR (unchanged) */
.main-content { margin-left:260px; width:calc(100% - 260px); min-height:100vh; display:flex; flex-direction:column; }
.navbar { background:var(--sidebar); padding:0 28px; height:60px; position:fixed; left:260px; right:0; top:0; display:flex; align-items:center; justify-content:space-between; border-bottom:3px solid var(--sidebar-red); z-index:1000; }
.navbar h1 { color:#fff; font-size:18px; font-weight:600; letter-spacing:-0.2px; }
.navbar-right { display:flex; align-items:center; gap:12px; }
.bell-wrap { position:relative; }
.bell-btn { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); color:#fff; width:36px; height:36px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; position:relative; transition:background 0.15s; }
.bell-btn:hover { background:rgba(255,255,255,0.13); }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e53e3e; color:#fff; font-size:9px; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; border:2px solid var(--sidebar); pointer-events:none; }
.notif-dropdown { width:360px; border-radius:var(--r-lg); border:1px solid var(--border); box-shadow:0 8px 32px rgba(0,0,0,0.10),0 1px 4px rgba(0,0,0,0.06); background:var(--surface); overflow:hidden; }
.notif-head { padding:16px 20px 14px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.notif-head-left { display:flex; align-items:center; gap:8px; }
.notif-head-left span { font-size:14px; font-weight:600; color:var(--text); }
.unread-pill { background:#eff4ff; color:#2563eb; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; line-height:1.6; display:none; }
.unread-pill.visible { display:inline-flex; }
.mark-all-btn { background:none; border:none; color:var(--text-3); font-size:11.5px; font-weight:500; cursor:pointer; padding:4px 8px; border-radius:6px; font-family:'Plus Jakarta Sans',sans-serif; }
.mark-all-btn:hover { background:var(--border-soft); color:var(--text-2); }
.notif-scroll { max-height:380px; overflow-y:auto; }
.notif-item { display:flex; align-items:flex-start; gap:12px; padding:14px 20px; border-bottom:1px solid var(--border-soft); text-decoration:none; color:inherit; transition:background 0.12s; cursor:pointer; }
.notif-item:last-child { border-bottom:none; }
.notif-item:hover { background:#fafbfc; }
.notif-item.unread { background:#fafcff; }
.notif-avatar { width:32px; height:32px; border-radius:50%; background:#f0f4ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }
.notif-avatar i { font-size:12px; color:#4f8ef7; }
.notif-item.unread .notif-avatar { background:#dbeafe; }
.notif-body-text { flex:1; min-width:0; }
.notif-msg { font-size:12.5px; line-height:1.5; color:var(--text-2); margin-bottom:4px; }
.notif-item.unread .notif-msg { color:var(--text); font-weight:500; }
.notif-ts { font-size:10.5px; color:var(--text-3); }
.notif-dot-ind { width:6px; height:6px; border-radius:50%; background:#2563eb; flex-shrink:0; margin-top:6px; }
.notif-empty { padding:40px 20px; text-align:center; }
.notif-empty i { font-size:24px; color:var(--text-3); display:block; margin-bottom:8px; }
.notif-empty p { font-size:13px; color:var(--text-3); margin:0; }
.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); padding:5px 12px 5px 6px; border-radius:20px; }
.user-avatar { width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:#fff; flex-shrink:0; }
.user-name { font-size:12.5px; color:#c8cce0; font-weight:500; }

/* ═══════════════════════════════════════════
   CONTENT AREA — REDESIGNED
   ═══════════════════════════════════════════ */
.content { margin-top:60px; padding:32px 36px 48px; flex:1; max-width:1100px; width:100%; }

.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:20px; display:flex; align-items:center; gap:10px; border:1px solid; border-left-width:4px; }
.alert-banner.success { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; border-left-color:#16a34a; }
.alert-banner.error   { background:#fff5f5; color:#dc2626; border-color:#fecaca; border-left-color:#dc2626; }

/* Section cards */
.panel { background:var(--surface); border:1px solid var(--border); border-radius:20px; margin-bottom:24px; overflow:hidden; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.panel-head { display:flex; align-items:flex-start; gap:14px; padding:24px 28px 0; }
.ph-icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; background:color-mix(in srgb, var(--accent) 12%, transparent); color:var(--accent); }
.panel-head h2 { font-size:17px; font-weight:600; letter-spacing:-.2px; margin:0 0 3px; }
.panel-head p  { font-size:12.5px; color:var(--text-2); margin:0; line-height:1.55; }
.panel-body { padding:22px 28px 26px; }
.panel-foot { padding:16px 28px; border-top:1px solid var(--border); background:color-mix(in srgb, var(--text) 2.5%, var(--surface)); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.panel-foot small { color:var(--text-2); font-size:12px; display:inline-flex; align-items:center; gap:7px; }

/* Theme cards */
.theme-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
.theme-card { position:relative; display:block; border:1.5px solid var(--border); border-radius:18px; padding:10px 10px 14px; cursor:pointer; transition:transform .18s, border-color .18s, box-shadow .18s; background:var(--surface); }
.theme-card:hover { transform:translateY(-3px); border-color:var(--text-3); box-shadow:0 10px 24px -10px rgba(0,0,0,.18); }
.theme-card input { position:absolute; opacity:0; pointer-events:none; }
.theme-card.selected { border-color:var(--accent); box-shadow:0 0 0 4px color-mix(in srgb, var(--accent) 16%, transparent); }

.tc-preview { height:118px; border-radius:12px; border:1px solid var(--border); background:var(--bg); display:flex; overflow:hidden; margin-bottom:14px; }
.tc-side { width:34%; padding:10px 9px; display:flex; flex-direction:column; gap:6px; border-bottom:3px solid; }
.tc-side i { display:block; height:6px; border-radius:3px; background:rgba(255,255,255,.55); }
.tc-side i:first-child { width:70%; background:rgba(255,255,255,.9); }
.tc-main { flex:1; padding:10px; display:flex; flex-direction:column; gap:7px; }
.tc-main b { display:block; height:7px; width:55%; border-radius:3px; background:var(--border); }
.tc-main em { display:block; height:20px; border-radius:6px; background:var(--surface); border:1px solid var(--border); }
.tc-main span { display:block; height:15px; width:50px; border-radius:999px; margin-top:auto; }

.tc-meta { padding:0 6px; }
.tc-name { font-size:14px; font-weight:600; display:flex; align-items:center; justify-content:space-between; }
.tc-note { font-size:11.5px; color:var(--text-3); margin-top:3px; }
.tc-check { width:20px; height:20px; border-radius:50%; border:1.5px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:9px; color:transparent; transition:all .18s; }
.theme-card.selected .tc-check { background:var(--accent); border-color:var(--accent); color:#fff; transform:scale(1.08); }
.swatches { display:flex; gap:6px; margin-top:12px; }
.swatches span { width:18px; height:18px; border-radius:50%; border:2px solid var(--surface); box-shadow:0 0 0 1px rgba(0,0,0,.12); }

/* Mode segmented control */
.seg { display:inline-flex; gap:4px; padding:4px; border-radius:999px; background:color-mix(in srgb, var(--text) 6%, var(--surface)); }
.seg button { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; background:transparent; color:var(--text-2); border:none; border-radius:999px; cursor:pointer; transition:all .18s; }
.seg button:hover { color:var(--text); }
.seg button.active { background:var(--surface); color:var(--accent); font-weight:600; box-shadow:0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.06); }

.m-btn { display:inline-flex; align-items:center; gap:8px; padding:11px 24px; border-radius:999px; font-size:13px; font-weight:600; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; border:none; background:var(--accent); color:#fff; transition:transform .12s, filter .15s, box-shadow .2s; box-shadow:0 6px 16px -6px color-mix(in srgb, var(--accent) 70%, transparent); }
.m-btn:hover { filter:brightness(1.08); }
.m-btn:active { transform:scale(.97); }

@media (max-width:900px){ .theme-grid{grid-template-columns:1fr;} .content{padding:24px 16px;} .panel-head,.panel-body,.panel-foot{padding-left:20px;padding-right:20px;} }
@media (min-width:901px) and (max-width:1200px){ .theme-grid{grid-template-columns:repeat(2,1fr);} }
</style>
<?= $themeHead ?>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="adm_logo">
    <img src="../assets/images/mccLogo.png" alt="MCC Logo">
    <div class="logotext"><h2>Admin Panel</h2><p>MCC Student Violation System</p></div>
  </div>
  <div class="sidebar_content">
    <ul>
      <h3>Overview</h3>
      <li><a href="dashboard.php"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>
      <h3>Students</h3>
      <li><a href="student-records.php"><i class="fas fa-user-graduate"></i><span>Student Records</span></a></li>
      <li><a href="violation-records.php"><i class="fas fa-history"></i><span>Violation Records</span></a></li>
      <li><a href="student-appeals.php"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>
      <h3>Discipline</h3>
      <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
      <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
      <h3>Backup &amp; Reports</h3>
      <li><a href="data-backup.php"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>
      <h3>System</h3>
      <li><a href="user-management.php"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
      <li><a href="theme-settings.php" class="active"><i class="fas fa-palette"></i><span>Theme Settings</span></a></li>
      <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
    </ul>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <nav class="navbar">
    <h1>Theme Settings</h1>
    <div class="navbar-right">
      <div class="dropdown bell-wrap">
        <button class="bell-btn" id="bellBtn" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <?php if($unreadCount>0): ?><span class="notif-badge" id="notifBadge"><?= $unreadCount ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
          <div class="notif-head">
            <div class="notif-head-left">
              <span>Notifications</span>
              <span class="unread-pill <?= $unreadCount>0?'visible':'' ?>" id="notifPill"><?= $unreadCount ?> new</span>
            </div>
            <button class="mark-all-btn" onclick="markAllRead()">Mark all read</button>
          </div>
          <div class="notif-scroll" id="notifList">
            <?php if(empty($notifList)): ?>
            <div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You're all caught up</p></div>
            <?php else: foreach($notifList as $n): ?>
            <a class="notif-item <?= !$n['is_read']?'unread':'' ?>" href="<?= htmlspecialchars($n['link']??'#') ?>" onclick="markRead(<?= $n['id'] ?>,this);return true;">
              <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
              <div class="notif-body-text">
                <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i><?= date('M d, Y · h:i A',strtotime($n['created_at'])) ?></div>
              </div>
              <?php if(!$n['is_read']): ?><div class="notif-dot-ind"></div><?php endif; ?>
            </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
        <span class="user-name"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
      </div>
    </div>
  </nav>

  <div class="content">

    <?php if(isset($_SESSION['msg'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_SESSION['msg']) ?><?php unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['err'])): ?>
    <div class="alert-banner error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($_SESSION['err']) ?><?php unset($_SESSION['err']); ?></div>
    <?php endif; ?>

    <!-- COLOR THEME -->
    <form method="POST" class="panel">
      <input type="hidden" name="action" value="save_theme">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <div class="panel-head">
        <div class="ph-icon"><i class="fas fa-swatchbook"></i></div>
        <div>
          <h2>Color Theme</h2>
          <p>Sets the sidebar, header and accent color for every admin page. Click a theme to preview it, then save.</p>
        </div>
      </div>
      <div class="panel-body">
        <div class="theme-grid">
          <?php foreach($themes as $key => $t): ?>
          <label class="theme-card <?= $key===$current?'selected':'' ?>" data-theme="<?= $key ?>">
            <input type="radio" name="theme" value="<?= $key ?>" <?= $key===$current?'checked':'' ?>>
            <div class="tc-preview">
              <div class="tc-side" style="background:<?= $t['main'] ?>;border-color:<?= $t['bar'] ?>;"><i></i><i></i><i></i><i></i></div>
              <div class="tc-main"><b></b><em></em><span style="background:<?= $t['accent'] ?>;"></span></div>
            </div>
            <div class="tc-meta">
              <div class="tc-name"><?= htmlspecialchars($t['name']) ?><span class="tc-check"><i class="fas fa-check"></i></span></div>
              <div class="tc-note"><?= htmlspecialchars($t['note']) ?> · <?= strtoupper($t['main']) ?></div>
              <div class="swatches">
                <?php foreach (($t['family'] ?? [$t['main'], $t['bar'], $t['accent']]) as $c): ?>
                <span style="background:<?= $c ?>;"></span>
                <?php endforeach; ?>
              </div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel-foot">
        <small><i class="fas fa-info-circle"></i> Applies to all administrators.</small>
        <button type="submit" class="m-btn"><i class="fas fa-check" style="font-size:11px;"></i> Save Theme</button>
      </div>
    </form>

    <!-- APPEARANCE -->
    <div class="panel">
      <div class="panel-head">
        <div class="ph-icon"><i class="fas fa-circle-half-stroke"></i></div>
        <div>
          <h2>Appearance</h2>
          <p>Choose light or dark mode. This is saved on this device only, so each admin can pick their own.</p>
        </div>
      </div>
      <div class="panel-body">
        <div class="seg" id="modeSeg">
          <button type="button" data-mode="light"><i class="fas fa-sun"></i> Light</button>
          <button type="button" data-mode="dark"><i class="fas fa-moon"></i> Dark</button>
          <button type="button" data-mode="auto"><i class="fas fa-desktop"></i> Auto</button>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Theme preview ── */
const THEMES = <?= json_encode($themes) ?>;
const VARMAP = {main:'--theme-main', bar:'--sidebar-red', accent:'--accent', hover:'--accent-hover',
                light:'--accent-light', label:'--sb-label', text:'--sb-text', sub:'--sb-sub',
                nbg:'--n-bg', nborder:'--n-border', nsoft:'--n-soft', nrow:'--n-row',
                ntext:'--n-text', ntext2:'--n-text2', ntext3:'--n-text3',
                dbg:'--d-bg', dsurface:'--d-surface', dborder:'--d-border', dsoft:'--d-soft',
                dtext:'--d-text', dtext2:'--d-text2', dtext3:'--d-text3'};
document.querySelectorAll('.theme-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        const t = THEMES[card.dataset.theme];
        Object.entries(VARMAP).forEach(([k, v]) => { if (t[k]) document.documentElement.style.setProperty(v, t[k]); });
    });
});

/* ── Light / Dark / Auto ── */
function syncSeg(){
    document.querySelectorAll('#modeSeg button').forEach(b =>
        b.classList.toggle('active', b.dataset.mode === MDSV_THEME.mode));
}
document.querySelectorAll('#modeSeg button').forEach(b =>
    b.addEventListener('click', () => { MDSV_THEME.set(b.dataset.mode); syncSeg(); }));
document.addEventListener('mdsv:mode', syncSeg);
syncSeg();

/* ── Notifications (same as other pages) ── */
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s){ const d=new Date(s); return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' · '+d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }
function renderNotifs(data){
    const badge=document.getElementById('notifBadge'), pill=document.getElementById('notifPill'),
          listEl=document.getElementById('notifList'), btn=document.getElementById('bellBtn');
    const isOpen=btn?.closest('.dropdown')?.classList.contains('show');
    if(data.unread>0){
        if(badge){ badge.textContent=data.unread; badge.style.display=''; }
        else if(btn){ const nb=document.createElement('span'); nb.id='notifBadge'; nb.className='notif-badge'; nb.textContent=data.unread; btn.appendChild(nb); }
        if(pill){ pill.textContent=data.unread+' new'; pill.classList.add('visible'); }
    } else { if(badge) badge.style.display='none'; if(pill) pill.classList.remove('visible'); }
    if(!listEl||isOpen) return;
    if(!data.notifications.length){ listEl.innerHTML='<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You\'re all caught up</p></div>'; return; }
    listEl.innerHTML=data.notifications.map(n=>`
        <a class="notif-item${n.is_read==0?' unread':''}" href="${escHtml(n.link||'#')}" onclick="markRead(${n.id},this);return true;">
          <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
          <div class="notif-body-text"><div class="notif-msg">${escHtml(n.message)}</div>
          <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i>${fmtDate(n.created_at)}</div></div>
          ${n.is_read==0?'<div class="notif-dot-ind"></div>':''}
        </a>`).join('');
}
function markRead(id,el){
    fetch('ajax/notifications.php?action=read&id='+id);
    el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove();
    const badge=document.getElementById('notifBadge'), pill=document.getElementById('notifPill');
    if(badge){ const n=parseInt(badge.textContent)-1; n<=0?badge.style.display='none':badge.textContent=n; }
    if(pill){ const n=parseInt(pill.textContent)-1; n<=0?pill.classList.remove('visible'):pill.textContent=n+' new'; }
}
function markAllRead(){
    fetch('ajax/notifications.php?action=read_all');
    document.querySelectorAll('#notifList .notif-item').forEach(el=>{ el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove(); });
    const b=document.getElementById('notifBadge'); if(b) b.style.display='none';
    document.getElementById('notifPill')?.classList.remove('visible');
}
const pollNotifs=()=>fetch('ajax/notifications.php?action=poll').then(r=>r.ok?r.json():null).then(d=>{ if(d&&!d.error) renderNotifs(d); });
document.addEventListener('DOMContentLoaded',()=>{ pollNotifs(); setInterval(pollNotifs,15000); });
</script>
</body>
</html>
