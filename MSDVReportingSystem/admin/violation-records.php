<?php
session_start();

// Use Philippine Standard Time (PHT, UTC+8) for violation timestamps.
date_default_timezone_set('Asia/Manila');

require_once '../db/connection.php';
require_once '../db/image_storage.php';

// Keep MySQL/TiDB date functions (NOW(), CURRENT_TIMESTAMP) in Philippine time
// for this database connection.
$pdo->exec("SET time_zone = '+08:00'");
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

define('DELETE_PASSWORD','delete123');

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

if(isset($_POST['action'])&&$_POST['action']==='delete_violation'){
    $vid=(int)$_POST['violation_id'];$pass=trim($_POST['del_password']);
    if($pass===DELETE_PASSWORD){
        $ip=$pdo->prepare("SELECT evidence_path,face_capture_path,signature_path FROM violations WHERE id=?");
        $ip->execute([$vid]);$imgPaths=$ip->fetch(PDO::FETCH_NUM) ?: [];
        $pdo->prepare("DELETE FROM disciplinary_actions WHERE violation_id=?")->execute([$vid]);
        $pdo->prepare("DELETE FROM appeals WHERE violation_id=?")->execute([$vid]);
        $pdo->prepare("DELETE FROM violations WHERE id=?")->execute([$vid]);
        foreach($imgPaths as $imgPath){ removeStoredImage($pdo, $imgPath ?: null); }
        $_SESSION['msg']='Violation deleted successfully.';
    }else{$_SESSION['err']='Incorrect deletion password.';}
    header('Location: violation-records.php');exit;
}

/* ───────────────────────────────────────────────
   FACE VERIFICATION + EVIDENCE HANDLERS
   Images live in the stored_images table (db/image_storage.php); a violation keeps
   a path like image.php?id=42. Old records may still have uploads/... file paths,
   which the helper functions also handle.
   ─────────────────────────────────────────────── */

// Save face verification result (match / no_match) and delete the face capture image
if(($_POST['action']??'')==='face_result'){
    header('Content-Type: application/json');
    $vid = (int)$_POST['violation_id'];
    $result = ($_POST['result']??'')==='match' ? 'match' : 'no_match';
    $q = $pdo->prepare("SELECT face_capture_path FROM violations WHERE id=?");
    $q->execute([$vid]); $row = $q->fetch();
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'Violation not found']); exit; }
    // Match OR not match: the captured face image is always deleted to save storage.
    // The result (match / no_match) stays saved so the admin can still see it.
    removeStoredImage($pdo, $row['face_capture_path']);
    $pdo->prepare("UPDATE violations SET face_match=?, face_capture_path=NULL, face_verified_at=NOW() WHERE id=?")->execute([$result,$vid]);
    echo json_encode(['ok'=>true]); exit;
}

// File type of the evidence (used to name the download)
if(isset($_GET['evidence_info'])){
    header('Content-Type: application/json');
    $q = $pdo->prepare("SELECT evidence_path FROM violations WHERE id=?");
    $q->execute([(int)$_GET['evidence_info']]); $row = $q->fetch();
    $img = $row ? loadStoredImage($pdo, $row['evidence_path'], false) : null;
    echo json_encode(['ok'=>(bool)$img, 'ext'=>$img ? storedImageExt($img['mime']) : 'jpg']); exit;
}

// Stream the evidence image so the browser can download it
if(isset($_GET['download_evidence'])){
    $q = $pdo->prepare("SELECT evidence_path FROM violations WHERE id=?");
    $q->execute([(int)$_GET['download_evidence']]); $row = $q->fetch();
    $img = $row ? loadStoredImage($pdo, $row['evidence_path'], true) : null;
    if(!$img || $img['data']===false || $img['data']===null){ http_response_code(404); exit; }
    header('Content-Type: '.$img['mime']);
    header('Content-Length: '.strlen($img['data']));
    echo $img['data']; exit;
}

// Delete the evidence image from the database (after download, or after the admin skips)
if(($_POST['action']??'')==='clear_evidence'){
    header('Content-Type: application/json');
    $vid = (int)$_POST['violation_id'];
    $q = $pdo->prepare("SELECT evidence_path FROM violations WHERE id=?");
    $q->execute([$vid]); $row = $q->fetch();
    if($row){
        removeStoredImage($pdo, $row['evidence_path']);
        $pdo->prepare("UPDATE violations SET evidence_path=NULL WHERE id=?")->execute([$vid]);
    }
    echo json_encode(['ok'=>true]); exit;
}

$search=trim($_GET['search']??'');$filterCat=$_GET['category']??'';$filterStatus=$_GET['status']??'';
$where="WHERE 1=1";$params=[];
if($search){$where.=" AND (s.full_name LIKE ? OR v.student_id LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($filterCat){$where.=" AND v.category=?";$params[]=$filterCat;}
if($filterStatus){$where.=" AND v.status=?";$params[]=$filterStatus;}

// ── Pagination: only one page of violations is loaded at a time (20 per page) ──
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$joins = "FROM violations v
  JOIN students s ON v.student_id=s.student_id
  JOIN courses c ON s.course_id=c.id
  JOIN departments d ON s.department_id=d.id";

$countStmt=$pdo->prepare("SELECT COUNT(*) $joins $where");
$countStmt->execute($params);
$totalViolations=(int)$countStmt->fetchColumn();
$totalPages=max(1,(int)ceil($totalViolations/$perPage));

// Coming from a notification (?highlight=ID): open the page that holds that violation
$hl=(int)($_GET['highlight']??0);
if($hl && !isset($_GET['page'])){
    $hq=$pdo->prepare("SELECT date_submitted FROM violations WHERE id=?");
    $hq->execute([$hl]); $hd=$hq->fetchColumn();
    if($hd){
        $posStmt=$pdo->prepare("SELECT COUNT(*) $joins $where AND (v.date_submitted > ? OR (v.date_submitted = ? AND v.id > ?))");
        $posStmt->execute(array_merge($params,[$hd,$hd,$hl]));
        $page=intdiv((int)$posStmt->fetchColumn(),$perPage)+1;
    }
}
if($page>$totalPages) $page=$totalPages;
$offset=($page-1)*$perPage;   // integers, so they are safe to put in the query

// Link to another page while keeping the current search and filters
function pageUrl($p){ $q=$_GET; $q['page']=$p; return '?'.http_build_query($q); }

// Page numbers to show: 1 … 4 5 [6] 7 8 … 20
function pageWindow($cur,$total){
    $pages=[1,$total];
    for($i=$cur-2;$i<=$cur+2;$i++){ if($i>1&&$i<$total) $pages[]=$i; }
    $pages=array_values(array_unique($pages)); sort($pages);
    $out=[];$prev=0;
    foreach($pages as $p){ if($p-$prev>1) $out[]='…'; $out[]=$p; $prev=$p; }
    return $out;
}

/* LEFT JOIN users so violations still show when the reporter account was deleted.
   reporter_staff_type = Teaching / Non-Teaching (users.staff_type)
   s.profile_photo_path = photo the student uploaded in the enrollment Google Form (image.php?id=N) */
$stmt=$pdo->prepare("SELECT v.*,s.full_name,s.profile_photo_path,c.name as course_name,d.name as dept_name,
    COALESCE(u.full_name,'Deleted user') as reporter_name,
    COALESCE(u.role,'—') as reporter_role,
    u.staff_type as reporter_staff_type,
    da.sanction,da.status as da_status
  FROM violations v
  JOIN students s ON v.student_id=s.student_id
  JOIN courses c ON s.course_id=c.id
  JOIN departments d ON s.department_id=d.id
  LEFT JOIN users u ON v.reporter_id=u.id
  LEFT JOIN disciplinary_actions da ON da.violation_id=v.id
  $where ORDER BY v.date_submitted DESC, v.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);$violations=$stmt->fetchAll();

$highlight=(int)($_GET['highlight']??0);
?>
<?php require_once 'theme-head.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Violation Records</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

/* User chip */
.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); padding:5px 12px 5px 6px; border-radius:20px; }
.user-avatar { width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:#fff; flex-shrink:0; }
.user-name { font-size:12.5px; color:#c8cce0; font-weight:500; }

/* ── CONTENT ── */
.content { margin-top:60px; padding:28px 32px 40px; flex:1; }

@keyframes fadeUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
@media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }

/* Alert banners */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:16px; display:flex; align-items:center; gap:10px; border:none; animation:fadeUp .3s ease both; }
.alert-banner.success { background:#f0fdf4; color:#15803d; }
.alert-banner.error   { background:#fff0f0; color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* ── FILTER BAR ── */
.filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:14px 16px; margin-bottom:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; box-shadow:0 1px 2px rgba(16,24,40,.04); animation:fadeUp .35s ease both; }
.search-wrap { position:relative; flex:1 1 280px; min-width:200px; }
.search-wrap i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:12px; pointer-events:none; }
.filter-input, .filter-select {
    font-size:13px; padding:8px 12px; border-radius:10px;
    border:1px solid var(--border); background:#fff; color:var(--text);
    font-family:'Plus Jakarta Sans',sans-serif; outline:none;
    transition:border-color .15s, box-shadow .15s; height:40px;
}
.filter-input:focus, .filter-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.09); }
.search-wrap .filter-input { width:100%; padding-left:36px; }
.filter-select { appearance:none; -webkit-appearance:none; padding-right:30px; cursor:pointer; min-width:140px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 11px center; background-color:#fff;
}
.filter-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 18px; border-radius:10px; font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all .15s; height:40px; border:1px solid; text-decoration:none; }
.filter-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.filter-btn.primary:hover { filter:brightness(.92); transform:translateY(-1px); }
.filter-btn.secondary { background:#fff; color:var(--text-2); border-color:var(--border); }
.filter-btn.secondary:hover { background:var(--bg); color:var(--text); }

/* Count */
.count-badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--text-2); background:var(--surface); border:1px solid var(--border); padding:5px 12px; border-radius:20px; margin-bottom:12px; }
.count-badge strong { color:var(--text); font-weight:700; }

/* ── TABLE ── */
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; box-shadow:0 1px 2px rgba(16,24,40,.04); animation:fadeUp .45s ease both; }
.table-scroll { overflow-x:auto; }
.data-table { width:100%; min-width:900px; border-collapse:separate; border-spacing:0; font-size:13px; }
.data-table thead th { padding:13px 18px; font-size:10.5px; font-weight:600; letter-spacing:.7px; text-transform:uppercase; color:var(--text-3); background:var(--bg); white-space:nowrap; text-align:left; border-bottom:1px solid var(--border); }
.data-table tbody td { padding:13px 18px; color:var(--text); vertical-align:middle; border-bottom:1px solid var(--border-soft); transition:background .12s; }
.data-table tbody tr:last-child td { border-bottom:none; }
.data-table tbody tr:hover td { background:color-mix(in srgb, var(--accent) 9%, var(--surface)); }
.data-table tbody tr.highlight td { background:color-mix(in srgb, #f59e0b 14%, var(--surface)); }
.data-table td.sid { font-weight:600; font-family:monospace; font-size:12.5px; color:var(--accent); letter-spacing:.2px; white-space:nowrap; }
.data-table td.name { font-weight:600; }
.data-table td.muted { color:var(--text-2); }
.data-table td.viol { color:var(--text-2); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.data-table td.date { color:var(--text-2); white-space:nowrap; }
.data-table td.time { color:var(--text-3); white-space:nowrap; font-size:12.5px; }
.data-table td.actions { text-align:right; white-space:nowrap; }
.empty-row td { padding:56px 16px !important; text-align:center; color:var(--text-3); font-size:13px; }

/* Highlight the matching violation with a border only */
tr.violation-highlight > td {
    background-color: transparent !important;
    border-top: 1px solid #0030b3 !important;
    border-bottom: 1px solid #0030b3 !important;
}
tr.violation-highlight > td:first-child { border-left: 1px solid #0030b3 !important; }
tr.violation-highlight > td:last-child  { border-right: 1px solid #0030b3 !important; }

/* ── TABLE FOOTER + PAGINATION (colors come from the theme variables) ── */
.table-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px; background:var(--bg); border-top:1px solid var(--border); }
.foot-info { font-size:12.5px; color:var(--text-3); }
.foot-info strong { color:var(--text); font-weight:600; }
.pager { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pg-btn { min-width:36px; height:36px; padding:0 11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text-2); font-size:13px; font-weight:600; text-decoration:none; transition:all .15s; }
.pg-btn:hover { background:var(--accent-light); color:var(--accent); border-color:var(--accent); transform:translateY(-1px); }
.pg-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 4px 12px color-mix(in srgb, var(--accent) 35%, transparent); pointer-events:none; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-gap { color:var(--text-3); padding:0 4px; font-size:13px; }

/* Badges */
.mini-badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.data-table .mini-badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; margin-right:6px; }
.mini-badge.minor     { background:#eff4ff; color:#2563eb; }
.mini-badge.major     { background:#fff0f0; color:#dc2626; }
.mini-badge.completed { background:#f0fdf4; color:#16a34a; }
.mini-badge.ongoing   { background:#fffbeb; color:#d97706; }
.mini-badge.pending   { background:#f4f5f7; color:#6b7280; }
.mini-badge.teaching     { background:#eff4ff; color:#2563eb; }
.mini-badge.non_teaching { background:#f5f3ff; color:#7c3aed; }
.mini-badge.match     { background:#f0fdf4; color:#16a34a; }
.mini-badge.no_match  { background:#fff0f0; color:#dc2626; }

/* Action btn */
.view-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:var(--r-sm); font-size:12px; font-weight:500; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all .15s; }
.view-btn:hover { background:var(--accent-light); color:var(--accent); border-color:var(--accent); transform:translateY(-1px); }

/* ── MODALS ── */
.modal-content { border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 20px 56px rgba(0,0,0,0.16); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; }
.modal-header { padding:18px 24px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.modal-header .modal-title { font-size:16px; font-weight:600; color:var(--text); letter-spacing:-0.2px; display:flex; align-items:center; gap:10px; }
.modal-header .modal-title .mt-ic { width:32px; height:32px; border-radius:10px; background:var(--accent-light); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.modal-header.danger-head .modal-title .mt-ic { background:#fee2e2; color:#dc2626; }
.modal-header.danger-head { background:#fff0f0; }
.modal-header.danger-head .modal-title { color:#dc2626; }
.modal-header .btn-close { opacity:0.4; }
.modal-header .btn-close:hover { opacity:0.7; }
.modal-body { padding:22px 24px; }
.modal-footer { padding:14px 24px; border-top:1px solid var(--border-soft); display:flex; justify-content:flex-end; gap:8px; background:#fafbfc; }

/* Form elements inside modals */
.f-group { margin-bottom:16px; }
.f-group:last-child { margin-bottom:0; }
.f-label { display:block; font-size:12px; font-weight:600; color:var(--text-2); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
.f-input { width:100%; padding:10px 12px; border-radius:var(--r-sm); border:1px solid var(--border); background:#fff; color:var(--text); font-size:13.5px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; transition:border-color 0.15s, box-shadow .15s; box-sizing:border-box; }
.f-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.09); }

/* Modal buttons */
.m-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; border:1px solid; }
.m-btn:hover { transform:translateY(-1px); }
.m-btn.ghost  { background:#fff; color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:var(--bg); }
.m-btn.danger { background:#dc2626; color:#fff; border-color:#dc2626; }
.m-btn.danger:hover { background:#b91c1c; }
.m-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.primary:hover { background:#1d4ed8; }
.m-btn.success { background:#16a34a; color:#fff; border-color:#16a34a; }
.m-btn.success:hover { background:#15803d; }
.m-btn:disabled { opacity:0.6; cursor:not-allowed; transform:none; }

/* View modal info grid (1px gap lines between cells, works with column spans) */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border); border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; margin-bottom:22px; }
.info-cell { padding:12px 16px; background:var(--surface); }
.info-key { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-3); margin-bottom:4px; }
.info-val { font-size:13.5px; color:var(--text); font-weight:500; line-height:1.45; }
.info-val.sid-val { font-family:monospace; color:var(--accent); letter-spacing:0.5px; }

/* View modal section label */
.sec-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-3); margin-bottom:10px; }
.att-label { font-size:11px; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }

/* ── VIOLATION DETAILS (view modal) ── */
.vv-hero { display:flex; align-items:center; gap:16px; padding:16px 18px; border:1px solid var(--border); border-radius:var(--r-md); background:var(--accent-light); margin-bottom:16px; flex-wrap:wrap; }
.vv-ini { width:56px; height:56px; border-radius:50%; background:var(--accent); color:#fff; font-size:19px; font-weight:600; letter-spacing:.5px; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 6px 16px rgba(37,99,235,.25); }
.vv-hero-main { flex:1; min-width:200px; }
.vv-name { font-size:18px; font-weight:600; letter-spacing:-0.3px; line-height:1.25; }
.vv-id { font-family:monospace; font-size:12.5px; color:var(--accent); letter-spacing:.4px; margin:2px 0 8px; }
.vv-tags { display:flex; gap:6px; flex-wrap:wrap; }
.vv-when { text-align:right; }
.vv-when .info-val { font-size:13px; }
.vv-grid { display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-bottom:22px; }
.vv-card { border:1px solid var(--border); border-radius:var(--r-md); padding:16px 18px; background:var(--surface); }
.vv-card .sec-lbl { display:flex; align-items:center; gap:7px; }
.vv-title { font-size:15px; font-weight:600; color:var(--text); line-height:1.4; margin-bottom:6px; }
.vv-desc { font-size:13px; color:var(--text-2); line-height:1.65; margin:0; white-space:pre-wrap; }
.vv-foot { margin-top:14px; padding-top:12px; border-top:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.vv-foot .info-val { font-size:13px; }
.vv-list { display:flex; flex-direction:column; gap:12px; }
.vv-row { display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:13px; }
.vv-row .k { color:var(--text-3); font-size:11.5px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.vv-row .v { color:var(--text); font-weight:500; text-align:right; }
.att-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.att-card { border:1px solid var(--border); border-radius:var(--r-md); padding:12px; background:var(--surface); }
.att-card .att-label { margin-bottom:8px; }
.att-card .media-box { min-height:120px; }
.danger-icon { width:56px; height:56px; border-radius:50%; background:#fff0f0; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:22px; margin:0 auto 14px; }

/* Evidence / image areas */
.media-box { border:1px dashed #d5d9e0; border-radius:var(--r-md); padding:12px; background:#fafbfc; min-height:110px; display:flex; align-items:center; justify-content:center; }
.media-box img { max-height:180px; max-width:100%; border-radius:var(--r-sm); object-fit:contain; }
.media-box .no-media { font-size:12px; color:var(--text-3); text-align:center; }

/* Face verification side-by-side */
.face-compare { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.face-compare .media-box { min-height:240px; }
.face-compare .media-box img { max-height:240px; }

@media (max-width: 768px) {
    .content { padding:20px 16px 32px; }
    .filter-select { flex:1 1 140px; }
    .face-compare { grid-template-columns:1fr; }
    .vv-grid { grid-template-columns:1fr; }
    .att-grid { grid-template-columns:1fr 1fr; }
    .vv-when { text-align:left; }
    .table-foot { justify-content:center; text-align:center; padding:10px 12px; }
    .foot-info  { width:100%; }
    .pager      { justify-content:center; }
    .pg-btn     { min-width:32px; height:32px; padding:0 8px; font-size:12px; }
}
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
      <li><a href="violation-records.php" class="active"><i class="fas fa-history"></i><span>Violation Records</span></a></li>
      <li><a href="student-appeals.php"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>
      <h3>Discipline</h3>
      <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
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
    <h1>Violation Records</h1>
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
    <div class="alert-banner success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_SESSION['msg']) ?><?php unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['err'])): ?>
    <div class="alert-banner error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($_SESSION['err']) ?><?php unset($_SESSION['err']); ?></div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" name="search" class="filter-input" placeholder="Search by name or ID…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <select name="category" class="filter-select">
        <option value="">All Types</option>
        <option value="minor"  <?=$filterCat==='minor' ?'selected':''?>>Minor</option>
        <option value="major"  <?=$filterCat==='major' ?'selected':''?>>Major</option>
      </select>
      <select name="status" class="filter-select">
        <option value="">All Status</option>
        <option value="pending"   <?=$filterStatus==='pending'  ?'selected':''?>>Pending</option>
        <option value="ongoing"   <?=$filterStatus==='ongoing'  ?'selected':''?>>Ongoing</option>
        <option value="completed" <?=$filterStatus==='completed'?'selected':''?>>Completed</option>
      </select>
      <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
      <a href="violation-records.php" class="filter-btn secondary"><i class="fas fa-rotate-left" style="font-size:11px;"></i> Reset</a>
    </form>

    <!-- Count -->
    <div class="count-badge"><strong><?= $totalViolations ?></strong> violation<?= $totalViolations!==1?'s':'' ?> found</div>

    <!-- Table -->
    <div class="table-wrap">
      <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Full Name</th>
            <th>Type</th>
            <th>Violation</th>
            <th>Date</th>
            <th>Time</th>
            <th>Status</th>
            <th style="width:90px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($violations)): ?>
          <tr class="empty-row"><td colspan="8"><i class="fas fa-list" style="font-size:22px;display:block;margin-bottom:8px;"></i>No violations found</td></tr>
          <?php else: foreach($violations as $v):
            $rowHighlight=($highlight&&$highlight==$v['id'])?'highlight':'';
          ?>
          <tr class="<?= $rowHighlight ?>" id="row-<?= $v['id'] ?>">
            <td class="sid"><?= htmlspecialchars($v['student_id']) ?></td>
            <td class="name"><?= htmlspecialchars($v['full_name']) ?></td>
            <td><span class="mini-badge <?= $v['category'] ?>"><?= ucfirst($v['category']) ?></span></td>
            <td class="viol" title="<?= htmlspecialchars($v['violation']) ?>"><?= htmlspecialchars($v['violation']) ?></td>
            <td class="date"><?= date('M d, Y',strtotime($v['date_submitted'])) ?></td>
            <td class="time"><?= date('h:i A',strtotime($v['date_submitted'])) ?></td>
            <td><span class="mini-badge <?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
            <td class="actions"><button class="view-btn" onclick='openViolationView(<?= json_encode($v, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG) ?>)'><i class="fas fa-eye" style="font-size:11px;"></i> View</button></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <div class="table-foot">
        <div class="foot-info">
          <?php if($totalViolations>0): ?>Showing <strong><?= $offset+1 ?>–<?= $offset+count($violations) ?></strong> of <strong><?= $totalViolations ?></strong> violation<?= $totalViolations!==1?'s':'' ?> · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
          <?php else: ?>No violations to show<?php endif; ?>
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
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── VIEW VIOLATION MODAL ── -->
<div class="modal fade" id="viewViolationModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><span class="mt-ic"><i class="fas fa-file-circle-exclamation"></i></span>Violation Details</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="vvBody">
        <div style="text-align:center;padding:30px;color:var(--text-3);">Loading…</div>
      </div>
      <div class="modal-footer">
        <button class="m-btn primary" id="vvVerifyBtn" style="display:none"><i class="fas fa-user-check" style="font-size:11px;"></i> Verify Face</button>
        <button class="m-btn danger" id="vvDeleteBtn"><i class="fas fa-trash" style="font-size:11px;"></i> Delete</button>
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── DELETE VIOLATION MODAL ── -->
<div class="modal fade" id="delViolModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="delete_violation">
      <input type="hidden" name="violation_id" id="delViolID">
      <div class="modal-header danger-head">
        <span class="modal-title"><span class="mt-ic"><i class="fas fa-trash"></i></span>Delete Violation</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="danger-icon"><i class="fas fa-trash-can"></i></div>
        <p style="font-size:13.5px;color:var(--text-2);margin-bottom:18px;line-height:1.6;text-align:center;">This will permanently remove the violation record. This action cannot be undone.</p>
        <div class="f-group" style="margin:0;">
          <label class="f-label">Deletion Password</label>
          <input type="password" name="del_password" class="f-input" placeholder="Enter deletion password…" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn danger"><i class="fas fa-trash" style="font-size:11px;"></i> Confirm Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- ── FACE VERIFY MODAL ── -->
<div class="modal fade" id="faceModal" data-bs-backdrop="static" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><span class="mt-ic"><i class="fas fa-user-check"></i></span>Verify Face</span>
        <button type="button" class="btn-close" onclick="backToView('faceModal')"></button>
      </div>
      <div class="modal-body">
        <div class="face-compare">
          <div><div class="sec-lbl">Face Capture (Violation)</div><div class="media-box" id="fvCapture"></div></div>
          <div><div class="sec-lbl">Profile Photo (Enrollment)</div><div class="media-box" id="fvProfile"></div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="m-btn danger"  id="fvNoMatch" onclick="submitFace('no_match')"><i class="fas fa-times" style="font-size:11px;"></i> Not Match</button>
        <button class="m-btn success" id="fvMatch"   onclick="submitFace('match')"><i class="fas fa-check" style="font-size:11px;"></i> Match</button>
      </div>
    </div>
  </div>
</div>

<!-- ── DOWNLOAD EVIDENCE MODAL ── -->
<div class="modal fade" id="evidenceModal" data-bs-backdrop="static" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><span class="modal-title"><span class="mt-ic"><i class="fas fa-download"></i></span>Download Evidence</span></div>
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-2);line-height:1.6;margin-bottom:10px;">
          Download the evidence to free up server storage. It will be saved as:</p>
        <div class="f-input" id="evFileName" style="font-family:monospace;background:var(--bg);"></div>
      </div>
      <div class="modal-footer">
        <button class="m-btn ghost" onclick="swapModal('evidenceModal','skipConfirmModal')">No</button>
        <button class="m-btn primary" id="evDownloadBtn" onclick="downloadEvidence()"><i class="fas fa-download" style="font-size:11px;"></i> Download Evidence</button>
      </div>
    </div>
  </div>
</div>

<!-- ── CONFIRM SKIP DOWNLOAD MODAL ── -->
<div class="modal fade" id="skipConfirmModal" data-bs-backdrop="static" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header danger-head"><span class="modal-title"><span class="mt-ic"><i class="fas fa-exclamation-triangle"></i></span>Are you sure?</span></div>
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-2);line-height:1.6;margin:0;">
          Are you sure you don't want to download the evidence? It will be permanently removed from the server.</p>
      </div>
      <div class="modal-footer">
        <button class="m-btn ghost"  onclick="swapModal('skipConfirmModal','evidenceModal')">No, go back</button>
        <button class="m-btn danger" onclick="skipEvidence()">Yes, don't download</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if($highlight): ?>
document.addEventListener('DOMContentLoaded',function(){
    const row=document.getElementById('row-<?= $highlight ?>');
    if(row) row.scrollIntoView({behavior:'smooth',block:'center'});
});
<?php endif; ?>

const ROLE_LABELS       = { teacher:'Instructor', csu:'CSU', jassu:'JASSU', nurse:'Nurse' };
const STAFF_TYPE_LABELS = { teaching:'Teaching', non_teaching:'Non-Teaching' };

function initialsOf(name){
    const w = String(name||'').replace(/,/g,' ').split(/\s+/).filter(Boolean);
    return (((w[0]||'')[0]||'') + ((w[1]||'')[0]||'')).toUpperCase() || '?';
}

/* Renders the violation details into the View modal (does not show the modal) */
function renderViolation(v){
    const evidence = v.evidence_path
        ? `<div class="media-box"><img src="../${escHtml(v.evidence_path)}" alt="Evidence"></div>`
        : `<div class="media-box"><span class="no-media"><i class="fas fa-image" style="margin-right:6px;"></i>No evidence uploaded</span></div>`;
    const face = v.face_capture_path
        ? `<div class="media-box"><img src="../${escHtml(v.face_capture_path)}" alt="Face Capture"></div>`
        : `<div class="media-box"><span class="no-media"><i class="fas fa-user" style="margin-right:6px;"></i>No face capture</span></div>`;
    const sig = v.signature_path
        ? `<div class="media-box"><img src="../${escHtml(v.signature_path)}" alt="Signature" style="max-height:80px;"></div>`
        : `<div class="media-box"><span class="no-media"><i class="fas fa-signature" style="margin-right:6px;"></i>No signature</span></div>`;
    const profile = v.profile_photo_path
        ? `<div class="media-box"><img src="../${escHtml(v.profile_photo_path)}" alt="Profile"></div>`
        : `<div class="media-box"><span class="no-media"><i class="fas fa-user-circle" style="margin-right:6px;"></i>No profile photo</span></div>`;

    // Face verification result
    const faceBadge = v.face_match==='match'
        ? '<span class="mini-badge match">Face matched</span>'
        : v.face_match==='no_match'
        ? '<span class="mini-badge no_match">Face did not match</span>'
        : '<span class="mini-badge pending">Not verified</span>';

    // Reporter info
    const roleLabel = ROLE_LABELS[v.reporter_role] || v.reporter_role || '—';
    const staffType = v.reporter_staff_type
        ? `<span class="mini-badge ${escHtml(v.reporter_staff_type)}">${escHtml(STAFF_TYPE_LABELS[v.reporter_staff_type] || v.reporter_staff_type)}</span>`
        : '—';

    document.getElementById('vvBody').innerHTML = `
        <div class="vv-hero">
          <div class="vv-ini">${escHtml(initialsOf(v.full_name))}</div>
          <div class="vv-hero-main">
            <div class="vv-name">${escHtml(v.full_name)}</div>
            <div class="vv-id">${escHtml(v.student_id)}</div>
            <div class="vv-tags">
              <span class="mini-badge ${v.category}">${v.category.charAt(0).toUpperCase()+v.category.slice(1)}</span>
              <span class="mini-badge ${v.status}">${v.status.charAt(0).toUpperCase()+v.status.slice(1)}</span>
              ${faceBadge}
            </div>
          </div>
          <div class="vv-when">
            <div class="info-key">Date Submitted</div>
            <div class="info-val">${escHtml(v.date_submitted)}</div>
          </div>
        </div>

        <div class="vv-grid">
          <div class="vv-card">
            <div class="sec-lbl"><i class="fas fa-triangle-exclamation"></i>Violation</div>
            <div class="vv-title">${escHtml(v.violation)}</div>
            <p class="vv-desc">${escHtml(v.description||'No description provided.')}</p>
            <div class="vv-foot">
              <div class="info-key" style="margin:0;">Sanction</div>
              <div class="info-val">${escHtml(v.sanction||'—')}</div>
            </div>
          </div>
          <div class="vv-card">
            <div class="sec-lbl"><i class="fas fa-user-tie"></i>Reported By</div>
            <div class="vv-list">
              <div class="vv-row"><span class="k">Name</span><span class="v">${escHtml(v.reporter_name)}</span></div>
              <div class="vv-row"><span class="k">Role</span><span class="v">${escHtml(roleLabel)}</span></div>
              <div class="vv-row"><span class="k">Staff Type</span><span class="v">${staffType}</span></div>
            </div>
          </div>
        </div>

        <div class="sec-lbl">Attachments</div>
        <div class="att-grid">
          <div class="att-card"><div class="att-label">Evidence</div>${evidence}</div>
          <div class="att-card"><div class="att-label">Face Capture</div>${face}</div>
          <div class="att-card"><div class="att-label">E-Signature</div>${sig}</div>
          <div class="att-card"><div class="att-label">Profile Photo</div>${profile}</div>
        </div>`;

    document.getElementById('vvDeleteBtn').onclick = () =>
        swapModal('viewViolationModal','delViolModal',()=>{ document.getElementById('delViolID').value = v.id; });

    // Show "Verify Face" only if not verified yet and a face capture still exists
    const vb = document.getElementById('vvVerifyBtn');
    vb.style.display = (!v.face_match && v.face_capture_path) ? '' : 'none';
    vb.onclick = openFaceVerify;
}

/* ── Face verification + evidence flow ── */
const STATE = {};   // keeps updated violation data so re-opening View isn't stale
let CUR = null;

function openViolationView(v){
    if(STATE[v.id]) v = STATE[v.id]; else STATE[v.id] = v;
    CUR = v;
    renderViolation(v);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewViolationModal')).show();
}

// Hide one modal, then show another (waits for the hide animation)
function swapModal(fromId, toId, cb){
    const from = document.getElementById(fromId), to = document.getElementById(toId);
    const inst = bootstrap.Modal.getInstance(from);
    const go = () => { cb && cb(); bootstrap.Modal.getOrCreateInstance(to).show(); };
    if(!inst || !from.classList.contains('show')) return go();
    from.addEventListener('hidden.bs.modal', go, {once:true});
    inst.hide();
}
function backToView(fromId){ swapModal(fromId, 'viewViolationModal', ()=>renderViolation(CUR)); }

function setImg(id, path, emptyText){
    document.getElementById(id).innerHTML = path
        ? `<img src="../${escHtml(path)}" alt="">`
        : `<span class="no-media">${emptyText}</span>`;
}

function openFaceVerify(){
    setImg('fvCapture', CUR.face_capture_path, 'No face capture');
    setImg('fvProfile', CUR.profile_photo_path, 'No profile photo uploaded');
    document.getElementById('fvMatch').disabled = false;
    document.getElementById('fvNoMatch').disabled = false;
    swapModal('viewViolationModal', 'faceModal');
}

async function postAction(data){
    const fd = new FormData();
    Object.entries(data).forEach(([k,val]) => fd.append(k,val));
    const r = await fetch('violation-records.php', {method:'POST', body:fd});
    return r.json();
}

async function submitFace(result){
    document.getElementById('fvMatch').disabled = true;
    document.getElementById('fvNoMatch').disabled = true;
    try{
        const d = await postAction({action:'face_result', violation_id:CUR.id, result});
        if(!d.ok) throw new Error(d.error || 'Failed to save');
        CUR.face_match = result;
        CUR.face_capture_path = null;   // file is deleted server-side for both match and not match
        if(CUR.evidence_path){
            try{
                const info = await (await fetch('violation-records.php?evidence_info=' + CUR.id)).json();
                CUR.evidence_ext = info.ext || 'jpg';
            }catch(e){ CUR.evidence_ext = 'jpg'; }
            document.getElementById('evFileName').textContent = evidenceFileName(CUR);
            swapModal('faceModal', 'evidenceModal');
        } else {
            backToView('faceModal');
        }
    }catch(err){
        alert(err.message);
        document.getElementById('fvMatch').disabled = false;
        document.getElementById('fvNoMatch').disabled = false;
    }
}

// StudentID_ViolationName.ext   (ext comes from the server: CUR.evidence_ext)
function evidenceFileName(v){
    const ext  = v.evidence_ext || 'jpg';
    const slug = String(v.violation).replace(/[^a-z0-9]+/gi,'_').replace(/^_+|_+$/g,'');
    return `${v.student_id}_${slug}.${ext}`;
}

async function downloadEvidence(){
    const btn = document.getElementById('evDownloadBtn'); btn.disabled = true;
    try{
        const r = await fetch('violation-records.php?download_evidence=' + CUR.id);
        if(!r.ok) throw new Error('Evidence file not found on the server.');
        const blob = await r.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = evidenceFileName(CUR);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(()=>URL.revokeObjectURL(a.href), 3000);
        await clearEvidence();            // only runs after the download succeeded
        backToView('evidenceModal');
    }catch(err){ alert(err.message); }
    btn.disabled = false;
}

async function clearEvidence(){
    await postAction({action:'clear_evidence', violation_id:CUR.id});
    CUR.evidence_path = null;
}
async function skipEvidence(){
    await clearEvidence();
    backToView('skipConfirmModal');
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

document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const violationId = params.get('highlight');

    if (!violationId || !/^\d+$/.test(violationId)) return;

    const row = document.getElementById('row-' + violationId);

    if (row) {
        row.classList.add('violation-highlight');
        row.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }
});
</script>
</body>
</html>
