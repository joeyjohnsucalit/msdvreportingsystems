<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $daId=$_POST['da_id']; $newStatus=$_POST['new_status'];
    $flow=['pending'=>0,'ongoing'=>1,'completed'=>2];
    $curr=$pdo->prepare("SELECT * FROM disciplinary_actions WHERE id=?");
    $curr->execute([$daId]); $da=$curr->fetch();
    if($da){
        if(($flow[$newStatus]??-1)<=($flow[$da['status']]??0)){$_SESSION['err']='Cannot move status backwards.';}
        else{
            $startDate=$da['start_date']; $endDate=$da['end_date'];
            if($newStatus==='ongoing'&&!$startDate)$startDate=date('Y-m-d');
            if($newStatus==='completed'&&!$endDate)$endDate=date('Y-m-d');
            $pdo->prepare("UPDATE disciplinary_actions SET status=?,start_date=?,end_date=? WHERE id=?")->execute([$newStatus,$startDate,$endDate,$daId]);
            $pdo->prepare("UPDATE violations SET status=? WHERE id=?")->execute([$newStatus,$da['violation_id']]);
            $stuUser=$pdo->prepare("SELECT u.id FROM students s JOIN users u ON s.user_id=u.id WHERE s.student_id=?");
            $stuUser->execute([$da['student_id']]); $stuRow=$stuUser->fetch();
            if($stuRow){$pdo->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)")->execute([$stuRow['id'],"Your disciplinary case status has been updated to: ".strtoupper($newStatus),'../student/dashboard.php']);}
            $_SESSION['msg']='Status updated to '.ucfirst($newStatus).'.';
        }
    }
    // Stay on the same page (and keep the search / status filters) after updating.
    // The modal form has no action attribute, so it posts to the current URL and keeps the query string.
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: disciplinary-action.php'.($qs !== '' ? '?'.$qs : '')); exit;
}

$search=trim($_GET['search']??''); $filterStatus=$_GET['status']??'';
$where="WHERE 1=1"; $params=[];
if($search){$where.=" AND (s.full_name LIKE ? OR da.student_id LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($filterStatus){$where.=" AND da.status=?";$params[]=$filterStatus;}

// ── Status totals for the summary strip (all records, ignoring filters) ──
$statusCounts=['pending'=>0,'ongoing'=>0,'completed'=>0];
foreach($pdo->query("SELECT status,COUNT(*) c FROM disciplinary_actions GROUP BY status")->fetchAll() as $r){
    if(isset($statusCounts[$r['status']])) $statusCounts[$r['status']]=(int)$r['c'];
}
$allCount=array_sum($statusCounts);

// ── Pagination: 10 records per page ──
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$joins = "FROM disciplinary_actions da
  JOIN students s ON da.student_id=s.student_id
  JOIN violations v ON da.violation_id=v.id";

$countStmt=$pdo->prepare("SELECT COUNT(*) $joins $where");
$countStmt->execute($params);
$totalRecords=(int)$countStmt->fetchColumn();
$totalPages=max(1,(int)ceil($totalRecords/$perPage));
if($page>$totalPages) $page=$totalPages;
$offset=($page-1)*$perPage;   // integers, so they are safe to put in the query

// Link to another page while keeping the current search and filters
function pageUrl($p){ $q=$_GET; $q['page']=$p; return '?'.http_build_query($q); }

// Link for a status tab: keeps the search, resets to page 1
function statusUrl($st){ $q=$_GET; unset($q['page']); if($st==='') unset($q['status']); else $q['status']=$st; $s=http_build_query($q); return 'disciplinary-action.php'.($s?'?'.$s:''); }

// Page numbers to show: 1 … 4 5 [6] 7 8 … 20
function pageWindow($cur,$total){
    $pages=[1,$total];
    for($i=$cur-2;$i<=$cur+2;$i++){ if($i>1&&$i<$total) $pages[]=$i; }
    $pages=array_values(array_unique($pages)); sort($pages);
    $out=[];$prev=0;
    foreach($pages as $p){ if($p-$prev>1) $out[]='…'; $out[]=$p; $prev=$p; }
    return $out;
}

// Initials for the avatar circle
function initials($name){
    $parts=preg_split('/\s+/',trim($name)); $i='';
    foreach($parts as $p){ if($p!==''){ $i.=mb_strtoupper(mb_substr($p,0,1)); if(mb_strlen($i)>=2) break; } }
    return $i?:'?';
}

$stmt=$pdo->prepare("SELECT da.*,s.full_name,v.violation,v.category $joins $where ORDER BY da.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $daList=$stmt->fetchAll();
?>
<?php require_once 'theme-head.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Disciplinary Actions</title>
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

/* ── SIDEBAR ── */
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

/* ── MAIN ── */
.main-content { margin-left:260px; width:calc(100% - 260px); min-height:100vh; display:flex; flex-direction:column; }

/* ── NAVBAR ── */
.navbar { background:var(--sidebar); padding:0 28px; height:60px; position:fixed; left:260px; right:0; top:0; display:flex; align-items:center; justify-content:space-between; border-bottom:3px solid var(--sidebar-red); z-index:1000; }
.navbar h1 { color:#fff; font-size:18px; font-weight:600; letter-spacing:-0.2px; }
.navbar-right { display:flex; align-items:center; gap:12px; }
.bell-wrap { position:relative; }
.bell-btn { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); color:#fff; width:36px; height:36px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; position:relative; transition:background 0.15s; }
.bell-btn:hover { background:rgba(255,255,255,0.13); }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e53e3e; color:#fff; font-size:9px; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; border:2px solid var(--sidebar); pointer-events:none; }

/* ── NOTIFICATION DROPDOWN ── */
.notif-dropdown { width:360px; border-radius:var(--r-lg); border:1px solid var(--border); box-shadow:0 8px 32px rgba(0,0,0,0.10),0 1px 4px rgba(0,0,0,0.06); background:var(--surface); overflow:hidden; }
.notif-head { padding:16px 20px 14px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.notif-head-left { display:flex; align-items:center; gap:8px; }
.notif-head-left span { font-size:14px; font-weight:600; color:var(--text); }
.unread-pill { background:#eff4ff; color:#2563eb; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; line-height:1.6; display:none; }
.unread-pill.visible { display:inline-flex; }
.mark-all-btn { background:none; border:none; color:var(--text-3); font-size:11.5px; font-weight:500; cursor:pointer; padding:4px 8px; border-radius:6px; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.mark-all-btn:hover { background:var(--border-soft); color:var(--text-2); }
.notif-scroll { max-height:380px; overflow-y:auto; }
.notif-scroll::-webkit-scrollbar { width:3px; }
.notif-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.notif-item { display:flex; align-items:flex-start; gap:12px; padding:14px 20px; border-bottom:1px solid var(--border-soft); text-decoration:none; color:inherit; transition:background 0.12s; cursor:pointer; }
.notif-item:last-child { border-bottom:none; }
.notif-item:hover { background:#fafbfc; }
.notif-item.unread { background:#fafcff; }
.notif-item.unread:hover { background:#f4f8ff; }
.notif-avatar { width:32px; height:32px; border-radius:50%; background:#f0f4ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }
.notif-avatar i { font-size:12px; color:#4f8ef7; }
.notif-item.unread .notif-avatar { background:#dbeafe; }
.notif-item.unread .notif-avatar i { color:#2563eb; }
.notif-body-text { flex:1; min-width:0; }
.notif-msg { font-size:12.5px; line-height:1.5; color:var(--text-2); font-weight:400; margin-bottom:4px; }
.notif-item.unread .notif-msg { color:var(--text); font-weight:500; }
.notif-ts { font-size:10.5px; color:var(--text-3); }
.notif-dot-ind { width:6px; height:6px; border-radius:50%; background:#2563eb; flex-shrink:0; margin-top:6px; }
.notif-empty { padding:40px 20px; text-align:center; }
.notif-empty i { font-size:24px; color:var(--text-3); display:block; margin-bottom:8px; }
.notif-empty p { font-size:13px; color:var(--text-3); margin:0; }
.notif-dropdown { color:var(--text); }
.unread-pill { background:color-mix(in srgb, var(--accent) 14%, var(--surface)); color:var(--accent); }
.notif-item:hover { background:color-mix(in srgb, var(--text) 5%, var(--surface)); }
.notif-item.unread { background:color-mix(in srgb, var(--accent) 6%, var(--surface)); }
.notif-item.unread:hover { background:color-mix(in srgb, var(--accent) 12%, var(--surface)); }
.notif-avatar { background:color-mix(in srgb, var(--accent) 12%, var(--surface)); }
.notif-avatar i { color:var(--accent); }
.notif-item.unread .notif-avatar { background:color-mix(in srgb, var(--accent) 24%, var(--surface)); }
.notif-item.unread .notif-avatar i { color:var(--accent); }
.notif-dot-ind { background:var(--accent); }
/* User chip */
.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); padding:5px 12px 5px 6px; border-radius:20px; }
.user-avatar { width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:#fff; flex-shrink:0; }
.user-name { font-size:12.5px; color:#c8cce0; font-weight:500; }

/* ═══════════ REDESIGNED CONTENT AREA ═══════════ */
:root {
    --c-pending:#6b7280;   --c-pending-bg:#f1f2f4;
    --c-ongoing:#d97706;   --c-ongoing-bg:#fff6e0;
    --c-done:#16a34a;      --c-done-bg:#e9f9ef;
    --c-minor:#2563eb;     --c-major:#dc2626;
}
.content { margin-top:60px; padding:28px 32px 40px; flex:1; }

/* Alert banners */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:18px; display:flex; align-items:center; gap:10px; border:none; border-left:4px solid; }
.alert-banner.success { background:#f0fdf4; color:#15803d; border-left-color:#16a34a; }
.alert-banner.error   { background:#fff0f0; color:#dc2626; border-left-color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* Summary strip */
.stat-strip { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:22px; }
.stat { position:relative; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px 16px; overflow:hidden; }
.stat-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.stat-label { font-size:11.5px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); }
.stat-ico { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:12px; background:var(--sbg); color:var(--sc); }
.stat-num { font-size:30px; font-weight:600; letter-spacing:-1px; line-height:1; color:var(--text); }
.stat-bar { height:5px; border-radius:10px; background:var(--border-soft); margin-top:14px; overflow:hidden; }
.stat-bar span { display:block; height:100%; border-radius:10px; background:var(--sc); }
.stat.pending   { --sc:var(--c-pending); --sbg:var(--c-pending-bg); }
.stat.ongoing   { --sc:var(--c-ongoing); --sbg:var(--c-ongoing-bg); }
.stat.completed { --sc:var(--c-done);    --sbg:var(--c-done-bg); }

/* Toolbar: status tabs + search */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.seg { display:inline-flex; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:4px; gap:2px; }
.seg a { display:inline-flex; align-items:center; gap:7px; padding:7px 14px; border-radius:9px; font-size:12.5px; font-weight:500; color:var(--text-2); text-decoration:none; transition:all .15s; }
.seg a:hover { color:var(--text); background:var(--border-soft); }
.seg a.active { background:var(--accent); color:#fff; box-shadow:0 2px 8px color-mix(in srgb, var(--accent) 35%, transparent); }
.seg .n { font-size:10.5px; font-weight:700; padding:1px 7px; border-radius:20px; background:var(--border-soft); color:var(--text-3); }
.seg a.active .n { background:rgba(255,255,255,.22); color:#fff; }
.search-form { display:flex; align-items:center; gap:8px; }
.search-box { position:relative; }
.search-box i { position:absolute; left:13px; top:50%; transform:translateY(-50%); font-size:12px; color:var(--text-3); pointer-events:none; }
.search-box input { height:40px; min-width:270px; padding:0 14px 0 36px; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; transition:all .15s; }
.search-box input:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 14%, transparent); }
.btn-go { height:40px; padding:0 16px; border-radius:12px; border:none; background:var(--accent); color:#fff; font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:background .15s; }
.btn-go:hover { background:#1d4ed8; }
.btn-reset { height:40px; padding:0 14px; display:inline-flex; align-items:center; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; transition:background .15s; }
.btn-reset:hover { background:var(--bg); color:var(--text); }

/* ── TABLE CARD ── */
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; box-shadow:0 1px 2px rgba(16,24,40,.03); }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead th { padding:13px 18px; font-size:10.5px; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--text-3); background:var(--bg); border-bottom:1px solid var(--border); white-space:nowrap; text-align:left; }
.data-table tbody tr { border-bottom:1px solid var(--border-soft); transition:background .12s; }
.data-table tbody tr:last-child { border-bottom:none; }
.data-table tbody tr:hover { background:color-mix(in srgb, var(--accent) 3%, var(--surface)); }
.data-table td { padding:15px 18px; color:var(--text); vertical-align:middle; }
.empty-row td { padding:56px 16px; text-align:center; color:var(--text-3); font-size:13px; }
.empty-row i { font-size:28px; display:block; margin-bottom:10px; opacity:.6; }

/* Student cell */
.stu { display:flex; align-items:center; gap:12px; min-width:190px; }
.stu-av { width:38px; height:38px; border-radius:12px; background:var(--accent-light); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:600; flex-shrink:0; }
.stu-name { font-weight:600; font-size:13.5px; line-height:1.25; }
.stu-id { font-family:monospace; font-size:11.5px; color:var(--text-3); letter-spacing:.2px; margin-top:2px; }

/* Violation cell */
.vio { display:flex; align-items:flex-start; gap:9px; min-width:200px; }
.vio-dot { width:8px; height:8px; border-radius:50%; margin-top:6px; flex-shrink:0; }
.vio-dot.minor { background:var(--c-minor); }
.vio-dot.major { background:var(--c-major); }
.vio-title { font-size:13px; font-weight:500; line-height:1.35; }
.vio-cat { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
.vio-cat.minor { color:var(--c-minor); }
.vio-cat.major { color:var(--c-major); }
.sanction { font-size:12.5px; color:var(--text-2); max-width:200px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; line-height:1.45; }

/* Status + progress stepper */
.mini-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:20px; font-size:11px; font-weight:600; }
.mini-badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
.mini-badge.minor     { background:#eff4ff; color:var(--c-minor); }
.mini-badge.major     { background:#fff0f0; color:var(--c-major); }
.mini-badge.completed { background:var(--c-done-bg);    color:var(--c-done); }
.mini-badge.ongoing   { background:var(--c-ongoing-bg); color:var(--c-ongoing); }
.mini-badge.pending   { background:var(--c-pending-bg); color:var(--c-pending); }
.steps { display:flex; align-items:center; margin-top:9px; width:78px; }
.steps i { width:8px; height:8px; border-radius:50%; background:var(--border); flex-shrink:0; }
.steps b { flex:1; height:2px; background:var(--border); }
.steps i.on, .steps b.on { background:var(--sc); }
.steps.pending   { --sc:var(--c-pending); }
.steps.ongoing   { --sc:var(--c-ongoing); }
.steps.completed { --sc:var(--c-done); }

/* Timeline */
.tl { font-size:12.5px; color:var(--text-2); white-space:nowrap; line-height:1.6; }
.tl span { display:inline-block; width:38px; font-size:10px; font-weight:600; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
.tl em { font-style:normal; color:var(--text-3); }

/* Action buttons */
.update-btn { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:10px; font-size:12px; font-weight:600; border:none; background:var(--accent); color:#fff; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all .15s; white-space:nowrap; }
.update-btn:hover { background:#1d4ed8; transform:translateY(-1px); box-shadow:0 4px 10px color-mix(in srgb, var(--accent) 30%, transparent); }
.done-txt { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; color:var(--c-done); }

/* ── TABLE FOOTER + PAGINATION ── */
.table-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px; background:var(--bg); border-top:1px solid var(--border); }
.foot-info { font-size:12.5px; color:var(--text-3); }
.foot-info strong { color:var(--text); font-weight:600; }
.pager { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pg-btn { min-width:36px; height:36px; padding:0 11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text-2); font-size:13px; font-weight:600; text-decoration:none; transition:all .15s; }
.pg-btn:hover { background:var(--accent-light); color:var(--accent); border-color:var(--accent); transform:translateY(-1px); }
.pg-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 4px 12px color-mix(in srgb, var(--accent) 35%, transparent); pointer-events:none; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-gap { color:var(--text-3); padding:0 4px; font-size:13px; }

@media (max-width: 992px) {
    .stat-strip { grid-template-columns:1fr; }
    .table-wrap { overflow-x:auto; }
    .data-table { min-width:900px; }
}
@media (max-width: 768px) {
    .content { padding:22px 16px 32px; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .seg { overflow-x:auto; }
    .search-form { width:100%; }
    .search-box { flex:1; }
    .search-box input { min-width:0; width:100%; }
    .table-foot { justify-content:center; text-align:center; padding:10px 12px; }
    .foot-info  { width:100%; }
    .pager      { justify-content:center; }
    .pg-btn     { min-width:32px; height:32px; padding:0 8px; font-size:12px; }
}

/* ── MODAL ── */
.modal-content { border:1px solid var(--border); border-radius:20px; box-shadow:0 24px 64px rgba(0,0,0,0.16); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; background:var(--surface); color:var(--text); }
.m-head { display:flex; align-items:center; gap:14px; padding:22px 24px 18px; background:linear-gradient(135deg, var(--accent-light), var(--surface)); border-bottom:1px solid var(--border-soft); position:relative; }
.m-av { width:46px; height:46px; border-radius:14px; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:600; flex-shrink:0; }
.m-title { font-size:16px; font-weight:600; letter-spacing:-.2px; line-height:1.25; }
.m-sub { font-family:monospace; font-size:12px; color:var(--text-3); margin-top:2px; }
.m-head .btn-close { position:absolute; top:16px; right:16px; opacity:.4; }
.m-head .btn-close:hover { opacity:.75; }
.modal-body { padding:20px 24px 8px; }
.m-caption { font-size:10.5px; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--text-3); margin-bottom:8px; }

/* Case card */
.case-card { border:1px solid var(--border); border-radius:14px; padding:14px 16px; margin-bottom:18px; background:var(--bg); }
.case-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.case-viol { font-size:14px; font-weight:600; line-height:1.4; }
.case-sanction { font-size:12.5px; color:var(--text-2); line-height:1.55; padding-top:10px; border-top:1px dashed var(--border); }
.case-sanction b { display:block; font-size:10px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); margin-bottom:3px; }

/* Progress track in modal */
.track { display:flex; align-items:center; margin-bottom:20px; padding:0 4px; }
.t-node { display:flex; flex-direction:column; align-items:center; gap:6px; font-size:11px; font-weight:600; color:var(--text-3); }
.t-node span { width:26px; height:26px; border-radius:50%; background:var(--surface); border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:10px; }
.t-node.done { color:var(--text); }
.t-node.done span { background:var(--c-done); border-color:var(--c-done); color:#fff; }
.t-node.current { color:var(--accent); }
.t-node.current span { border-color:var(--accent); color:var(--accent); box-shadow:0 0 0 4px color-mix(in srgb, var(--accent) 15%, transparent); }
.t-line { flex:1; height:2px; background:var(--border); margin:0 8px 20px; }
.t-line.done { background:var(--c-done); }

/* Next-status choice cards */
.choice { display:block; position:relative; margin-bottom:10px; cursor:pointer; }
.choice input { position:absolute; opacity:0; pointer-events:none; }
.choice-box { display:flex; align-items:center; gap:12px; padding:13px 15px; border:1.5px solid var(--border); border-radius:14px; background:var(--surface); transition:all .15s; }
.choice-ico { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.choice-ico.ongoing   { background:var(--c-ongoing-bg); color:var(--c-ongoing); }
.choice-ico.completed { background:var(--c-done-bg);    color:var(--c-done); }
.choice-t { font-size:13.5px; font-weight:600; line-height:1.2; }
.choice-d { font-size:11.5px; color:var(--text-3); margin-top:2px; }
.choice-tick { margin-left:auto; width:20px; height:20px; border-radius:50%; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:9px; color:transparent; transition:all .15s; flex-shrink:0; }
.choice:hover .choice-box { border-color:#c7d7f8; }
.choice input:checked + .choice-box { border-color:var(--accent); background:var(--accent-light); }
.choice input:checked + .choice-box .choice-tick { background:var(--accent); border-color:var(--accent); color:#fff; }
.choice input:focus-visible + .choice-box { box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }

.modal-footer { padding:14px 24px 20px; display:flex; justify-content:flex-end; gap:8px; border-top:none; background:transparent; }
.m-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:11px; font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all .15s; border:1px solid; }
.m-btn.ghost   { background:var(--surface); color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:var(--bg); }
.m-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.primary:hover { background:#1d4ed8; }
</style>
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
      <li><a href="disciplinary-action.php" class="active"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
      <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
      <h3>Backup &amp; Reports</h3>
      <li><a href="data-backup.php"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>
      <h3>System</h3>
      <li><a href="user-management.php"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
         <li><a href="theme-settings.php"><i class="fas fa-palette"></i><span>Theme Settings</span></a></li>
      <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
    </ul>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <!-- NAVBAR -->
  <nav class="navbar">
    <h1>Disciplinary Actions</h1>
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

  <!-- CONTENT -->
  <div class="content">

    <?php if(isset($_SESSION['msg'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i><?= $_SESSION['msg'] ?><?php unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['err'])): ?>
    <div class="alert-banner error"><i class="fas fa-exclamation-circle"></i><?= $_SESSION['err'] ?><?php unset($_SESSION['err']); ?></div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div class="stat-strip">
      <?php
      $stats=[
        'pending'  =>['Pending','fa-hourglass-half'],
        'ongoing'  =>['Ongoing','fa-spinner'],
        'completed'=>['Completed','fa-check-double'],
      ];
      foreach($stats as $key=>$meta):
        $pct=$allCount>0?round($statusCounts[$key]/$allCount*100):0;
      ?>
      <div class="stat <?= $key ?>">
        <div class="stat-top">
          <span class="stat-label"><?= $meta[0] ?></span>
          <span class="stat-ico"><i class="fas <?= $meta[1] ?>"></i></span>
        </div>
        <div class="stat-num"><?= $statusCounts[$key] ?></div>
        <div class="stat-bar"><span style="width:<?= $pct ?>%;"></span></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Toolbar: status tabs + search -->
    <div class="toolbar">
      <div class="seg">
        <a href="<?= htmlspecialchars(statusUrl('')) ?>" class="<?= $filterStatus===''?'active':'' ?>">All <span class="n"><?= $allCount ?></span></a>
        <a href="<?= htmlspecialchars(statusUrl('pending')) ?>" class="<?= $filterStatus==='pending'?'active':'' ?>">Pending <span class="n"><?= $statusCounts['pending'] ?></span></a>
        <a href="<?= htmlspecialchars(statusUrl('ongoing')) ?>" class="<?= $filterStatus==='ongoing'?'active':'' ?>">Ongoing <span class="n"><?= $statusCounts['ongoing'] ?></span></a>
        <a href="<?= htmlspecialchars(statusUrl('completed')) ?>" class="<?= $filterStatus==='completed'?'active':'' ?>">Completed <span class="n"><?= $statusCounts['completed'] ?></span></a>
      </div>
      <form method="GET" class="search-form">
        <?php if($filterStatus!==''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search by name or ID…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn-go">Search</button>
        <?php if($search!==''||$filterStatus!==''): ?><a href="disciplinary-action.php" class="btn-reset">Reset</a><?php endif; ?>
      </form>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Violation</th>
            <th>Sanction</th>
            <th>Status</th>
            <th>Timeline</th>
            <th style="width:110px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($daList)): ?>
          <tr class="empty-row"><td colspan="6"><i class="fas fa-gavel"></i>No records found</td></tr>
          <?php else: foreach($daList as $da):
              $lvl=['pending'=>0,'ongoing'=>1,'completed'=>2][$da['status']]??0;
          ?>
          <tr>
            <td>
              <div class="stu">
                <div class="stu-av"><?= htmlspecialchars(initials($da['full_name'])) ?></div>
                <div>
                  <div class="stu-name"><?= htmlspecialchars($da['full_name']) ?></div>
                  <div class="stu-id"><?= htmlspecialchars($da['student_id']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="vio">
                <span class="vio-dot <?= htmlspecialchars($da['category']) ?>"></span>
                <div>
                  <div class="vio-title"><?= htmlspecialchars($da['violation']) ?></div>
                  <div class="vio-cat <?= htmlspecialchars($da['category']) ?>"><?= ucfirst(htmlspecialchars($da['category'])) ?></div>
                </div>
              </div>
            </td>
            <td><div class="sanction" title="<?= htmlspecialchars($da['sanction']) ?>"><?= htmlspecialchars($da['sanction']) ?></div></td>
            <td>
              <span class="mini-badge <?= htmlspecialchars($da['status']) ?>"><?= ucfirst(htmlspecialchars($da['status'])) ?></span>
              <div class="steps <?= htmlspecialchars($da['status']) ?>">
                <i class="on"></i><b class="<?= $lvl>=1?'on':'' ?>"></b><i class="<?= $lvl>=1?'on':'' ?>"></i><b class="<?= $lvl>=2?'on':'' ?>"></b><i class="<?= $lvl>=2?'on':'' ?>"></i>
              </div>
            </td>
            <td>
              <div class="tl">
                <div><span>Start</span><?= $da['start_date'] ? date('M d, Y', strtotime($da['start_date'])) : '<em>—</em>' ?></div>
                <div><span>End</span><?= $da['end_date'] ? date('M d, Y', strtotime($da['end_date'])) : '<em>—</em>' ?></div>
              </div>
            </td>
            <td>
              <?php if($da['status']!=='completed'): ?>
              <button class="update-btn" onclick='openUpdateStatus(<?= json_encode($da) ?>)'>
                <i class="fas fa-sync-alt" style="font-size:10px;"></i> Update
              </button>
              <?php else: ?>
              <span class="done-txt"><i class="fas fa-check-circle"></i>Done</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <div class="table-foot">
        <div class="foot-info">
          <?php if($totalRecords>0): ?>Showing <strong><?= $offset+1 ?>–<?= $offset+count($daList) ?></strong> of <strong><?= $totalRecords ?></strong> record<?= $totalRecords!==1?'s':'' ?> · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
          <?php else: ?>No records to show<?php endif; ?>
        </div>
        <div class="pager">
          <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $page<=1?'#':htmlspecialchars(pageUrl(1)) ?>" aria-label="First page"><i class="fas fa-angles-left" style="font-size:10px;"></i></a>
          <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $page<=1?'#':htmlspecialchars(pageUrl($page-1)) ?>" aria-label="Previous page"><i class="fas fa-chevron-left" style="font-size:10px;"></i></a>
          <?php foreach(pageWindow($page,$totalPages) as $p): ?>
            <?php if($p==='…'): ?><span class="pg-gap">…</span>
            <?php else: ?><a class="pg-btn <?= $p==$page?'active':'' ?>" href="<?= htmlspecialchars(pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
          <?php endforeach; ?>
          <a class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page>=$totalPages?'#':htmlspecialchars(pageUrl($page+1)) ?>" aria-label="Next page"><i class="fas fa-chevron-right" style="font-size:10px;"></i></a>
          <a class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page>=$totalPages?'#':htmlspecialchars(pageUrl($totalPages)) ?>" aria-label="Last page"><i class="fas fa-angles-right" style="font-size:10px;"></i></a>
        </div>
      </div>
    </div><!-- /table-wrap -->

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── UPDATE STATUS MODAL ── -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="da_id" id="daID">

      <div class="m-head">
        <div class="m-av" id="daAvatar"></div>
        <div>
          <div class="m-title" id="daStudentName"></div>
          <div class="m-sub" id="daStudentID"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="m-caption">Case</div>
        <div class="case-card">
          <div class="case-row">
            <span class="mini-badge" id="daCategory"></span>
            <span class="mini-badge" id="daCurrentStatus"></span>
          </div>
          <div class="case-viol" id="daViolation" style="margin-bottom:10px;"></div>
          <div class="case-sanction"><b>Sanction</b><span id="daSanction"></span></div>
        </div>

        <div class="m-caption">Progress</div>
        <div class="track" id="daTrack"></div>

        <div class="m-caption">Move status to</div>
        <div id="daChoices"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn primary"><i class="fas fa-sync-alt" style="font-size:11px;"></i> Update Status</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const cap = s => s.charAt(0).toUpperCase()+s.slice(1);
const STATUS_INFO = {
    ongoing:   { icon:'fa-spinner',      desc:'Sanction is now being carried out' },
    completed: { icon:'fa-check-double', desc:'Sanction has been fully served' }
};

function openUpdateStatus(da){
    document.getElementById('daID').value               = da.id;
    document.getElementById('daStudentID').textContent  = da.student_id;
    document.getElementById('daStudentName').textContent= da.full_name;
    document.getElementById('daViolation').textContent  = da.violation;
    document.getElementById('daSanction').textContent   = da.sanction || '—';

    // Avatar initials
    const ini = da.full_name.trim().split(/\s+/).slice(0,2).map(w=>w[0]).join('').toUpperCase();
    document.getElementById('daAvatar').textContent = ini || '?';

    // Category + current status badges
    const catEl = document.getElementById('daCategory');
    catEl.className = 'mini-badge '+da.category;
    catEl.textContent = cap(da.category);
    const stEl = document.getElementById('daCurrentStatus');
    stEl.className = 'mini-badge '+da.status;
    stEl.textContent = cap(da.status);

    // Progress track
    const flow = {pending:0, ongoing:1, completed:2};
    const curr = flow[da.status] ?? 0;
    const names = ['Pending','Ongoing','Completed'];
    let track = '';
    names.forEach((n,i)=>{
        const cls = i<curr ? 'done' : (i===curr ? 'current' : '');
        const inner = i<curr ? '<i class="fas fa-check"></i>' : (i+1);
        track += `<div class="t-node ${cls}"><span>${inner}</span>${n}</div>`;
        if(i<names.length-1) track += `<div class="t-line ${i<curr?'done':''}"></div>`;
    });
    document.getElementById('daTrack').innerHTML = track;

    // Forward-only choices (radio cards, first one pre-selected)
    let choices = '', first = true;
    Object.entries(flow).forEach(([s,v])=>{
        if(v > curr){
            const info = STATUS_INFO[s];
            choices += `
              <label class="choice">
                <input type="radio" name="new_status" value="${s}" ${first?'checked':''}>
                <span class="choice-box">
                  <span class="choice-ico ${s}"><i class="fas ${info.icon}"></i></span>
                  <span><div class="choice-t">${cap(s)}</div><div class="choice-d">${info.desc}</div></span>
                  <span class="choice-tick"><i class="fas fa-check"></i></span>
                </span>
              </label>`;
            first = false;
        }
    });
    document.getElementById('daChoices').innerHTML = choices;

    new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
}

/* ── NOTIFICATIONS ── */
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s){ const d=new Date(s); return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' · '+d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }

function renderNotifs(data){
    const badge  = document.getElementById('notifBadge');
    const pill   = document.getElementById('notifPill');
    const listEl = document.getElementById('notifList');
    const btn    = document.getElementById('bellBtn');
    const isOpen = btn?.closest('.dropdown')?.classList.contains('show');

    if(data.unread > 0){
        if(badge){ badge.textContent=data.unread; badge.style.display=''; }
        else if(btn){ const nb=document.createElement('span'); nb.id='notifBadge'; nb.className='notif-badge'; nb.textContent=data.unread; btn.appendChild(nb); }
        if(pill){ pill.textContent=data.unread+' new'; pill.classList.add('visible'); }
    } else {
        if(badge) badge.style.display='none';
        if(pill)  pill.classList.remove('visible');
    }
    if(!listEl||isOpen) return;
    if(!data.notifications.length){ listEl.innerHTML='<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You\'re all caught up</p></div>'; return; }
    listEl.innerHTML = data.notifications.map(n=>`
        <a class="notif-item${n.is_read==0?' unread':''}" href="${escHtml(n.link||'#')}" onclick="markRead(${n.id},this);return true;">
          <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
          <div class="notif-body-text">
            <div class="notif-msg">${escHtml(n.message)}</div>
            <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i>${fmtDate(n.created_at)}</div>
          </div>
          ${n.is_read==0?'<div class="notif-dot-ind"></div>':''}
        </a>`).join('');
}
function markRead(id,el){
    fetch('ajax/notifications.php?action=read&id='+id);
    el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove();
    const badge=document.getElementById('notifBadge'), pill=document.getElementById('notifPill');
    if(badge){ const n=parseInt(badge.textContent)-1; n<=0?badge.style.display='none':badge.textContent=n; }
    if(pill) { const n=parseInt(pill.textContent)-1;  n<=0?pill.classList.remove('visible'):pill.textContent=n+' new'; }
}
function markAllRead(){
    fetch('ajax/notifications.php?action=read_all');
    document.querySelectorAll('#notifList .notif-item').forEach(el=>{ el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove(); });
    document.getElementById('notifBadge')?.style && (document.getElementById('notifBadge').style.display='none');
    document.getElementById('notifPill')?.classList.remove('visible');
}
document.addEventListener('DOMContentLoaded', () => {
    fetch('ajax/notifications.php?action=poll').then(r=>r.ok?r.json():null).then(d=>{ if(d&&!d.error) renderNotifs(d); });
    setInterval(()=>{ fetch('ajax/notifications.php?action=poll').then(r=>r.ok?r.json():null).then(d=>{ if(d&&!d.error) renderNotifs(d); }); }, 15000);
});
</script>
</body>
</html>
