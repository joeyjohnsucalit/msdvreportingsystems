<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

define('DELETE_PASSWORD','delete123');

// Staff type => [role value => label shown in the UI]
$STAFF_ROLES = [
    'teaching'     => ['teacher' => 'Instructor'],
    'non_teaching' => ['csu' => 'CSU', 'jassu' => 'JASSU', 'nurse' => 'Nurse'],
];
function roleLabel($role){
    return ['teacher'=>'Instructor','csu'=>'CSU','jassu'=>'JASSU','nurse'=>'Nurse','student'=>'Student'][$role] ?? ucfirst($role);
}
function staffTypeLabel($t){
    return ['teaching'=>'Teaching','non_teaching'=>'Non-Teaching'][$t] ?? '—';
}

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

/* ── ADD USER ── */
if(isset($_POST['action'])&&$_POST['action']==='add_user'){
    $full=trim($_POST['full_name']);$uname=trim($_POST['username']);$email=trim($_POST['email']);
    $pass=$_POST['password'];$pass2=$_POST['confirm_password'];
    $type=$_POST['staff_type']??'';$role=$_POST['role']??'';
    if(!isset($STAFF_ROLES[$type][$role])){$_SESSION['err']='Please select a valid staff type and role.';}
    elseif($pass!==$pass2){$_SESSION['err']='Passwords do not match.';}
    else{
        try{
            $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,staff_type,is_first_login) VALUES (?,?,?,?,?,?,1)")
                ->execute([$full,$uname,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$type]);
            $_SESSION['msg']='User added successfully.';
        }catch(Exception $e){$_SESSION['err']='Username already exists.';}
    }
    header('Location: user-management.php');exit;
}

/* ── EDIT USER ── */
if(isset($_POST['action'])&&$_POST['action']==='edit_user'){
    $uid=(int)$_POST['user_id'];$full=trim($_POST['full_name']);$uname=trim($_POST['username']);$email=trim($_POST['email']);
    $type=$_POST['staff_type']??'';$role=$_POST['role']??'';
    if(!isset($STAFF_ROLES[$type][$role])){$_SESSION['err']='Please select a valid staff type and role.';}
    else{
        $pdo->prepare("UPDATE users SET full_name=?,username=?,email=?,role=?,staff_type=? WHERE id=? AND role NOT IN ('admin','student')")
            ->execute([$full,$uname,$email,$role,$type,$uid]);
        $_SESSION['msg']='User updated.';
    }
    header('Location: user-management.php');exit;
}

/* ── CHANGE PASSWORD ── */
if(isset($_POST['action'])&&$_POST['action']==='change_password'){
    $uid=(int)$_POST['user_id'];$pass=$_POST['new_password'];$pass2=$_POST['confirm_new_password'];
    if($pass!==$pass2){$_SESSION['err']='Passwords do not match.';}
    else{$pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pass,PASSWORD_DEFAULT),$uid]);$_SESSION['msg']='Password changed successfully.';}
    header('Location: user-management.php');exit;
}

/* ── DELETE USER ── */
if(isset($_POST['action'])&&$_POST['action']==='delete_user'){
    $uid=(int)$_POST['user_id'];$pass=trim($_POST['del_password']);
    if($pass===DELETE_PASSWORD){$pdo->prepare("DELETE FROM users WHERE id=? AND role NOT IN ('admin','student')")->execute([$uid]);$_SESSION['msg']='User deleted.';}
    else{$_SESSION['err']='Incorrect deletion password.';}
    header('Location: user-management.php');exit;
}

$activeTab=$_GET['tab']??'staff';
$perPage=10;   // every page holds 10 rows (same as student-records.php)

/* ── STAFF: only one page is loaded from the database at a time ── */
$searchStaff=trim($_GET['search_staff']??'');$filterRole=$_GET['role']??'';
$whereStaff="WHERE role NOT IN ('admin','student')";$paramsStaff=[];
if($searchStaff){$whereStaff.=" AND (full_name LIKE ? OR username LIKE ?)";$paramsStaff[]="%$searchStaff%";$paramsStaff[]="%$searchStaff%";}
if($filterRole&&$filterRole!=='student'){$whereStaff.=" AND role=?";$paramsStaff[]=$filterRole;}
$cs=$pdo->prepare("SELECT COUNT(*) FROM users $whereStaff");$cs->execute($paramsStaff);
$staffTotal=(int)$cs->fetchColumn();
$staffPages=max(1,(int)ceil($staffTotal/$perPage));
$pgStaff=min(max(1,(int)($_GET['pg_staff']??1)),$staffPages);
$staffOffset=($pgStaff-1)*$perPage;
$staffStmt=$pdo->prepare("SELECT * FROM users $whereStaff ORDER BY role,full_name LIMIT $perPage OFFSET $staffOffset");$staffStmt->execute($paramsStaff);$staffUsers=$staffStmt->fetchAll();

/* ── STUDENT ACCOUNTS ── */
$searchStu=trim($_GET['search_stu']??'');$whereStu="WHERE u.role='student'";$paramsStu=[];
if($searchStu){$whereStu.=" AND (u.full_name LIKE ? OR u.username LIKE ? OR s.student_id LIKE ?)";$paramsStu[]="%$searchStu%";$paramsStu[]="%$searchStu%";$paramsStu[]="%$searchStu%";}
$cu=$pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN students s ON s.user_id=u.id $whereStu");$cu->execute($paramsStu);
$stuTotal=(int)$cu->fetchColumn();
$stuPages=max(1,(int)ceil($stuTotal/$perPage));
$pgStu=min(max(1,(int)($_GET['pg_stu']??1)),$stuPages);
$stuOffset=($pgStu-1)*$perPage;
$stuStmt=$pdo->prepare("SELECT u.*,s.student_id as sid FROM users u LEFT JOIN students s ON s.user_id=u.id $whereStu ORDER BY u.full_name LIMIT $perPage OFFSET $stuOffset");$stuStmt->execute($paramsStu);$studentUsers=$stuStmt->fetchAll();

// Link to another page while keeping the current search and filters
function pagerUrl($tab,$param,$p){ $q=$_GET; $q['tab']=$tab; $q[$param]=$p; return '?'.http_build_query($q); }

// Page numbers to show: 1 … 4 5 [6] 7 8 … 20
function pageWindow($cur,$total){
    $pages=[1,$total];
    for($i=$cur-2;$i<=$cur+2;$i++){ if($i>1&&$i<$total) $pages[]=$i; }
    $pages=array_values(array_unique($pages)); sort($pages);
    $out=[];$prev=0;
    foreach($pages as $p){ if($p-$prev>1) $out[]='…'; $out[]=$p; $prev=$p; }
    return $out;
}

// Table footer: "Showing x–y of z · Page a of b" + first/prev/numbers/next/last
function renderPager($tab,$param,$page,$pages,$total,$offset,$shown,$noun){ ?>
      <div class="table-foot">
        <div class="foot-info">
          <?php if($total>0): ?>Showing <strong><?= $offset+1 ?>–<?= $offset+$shown ?></strong> of <strong><?= $total ?></strong> <?= $noun ?><?= $total!==1?'s':'' ?> · Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong>
          <?php else: ?>No <?= $noun ?>s to show<?php endif; ?>
        </div>
        <div class="pager">
          <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $page<=1?'#':htmlspecialchars(pagerUrl($tab,$param,1)) ?>" aria-label="First page"><i class="fas fa-angles-left" style="font-size:10px;"></i></a>
          <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $page<=1?'#':htmlspecialchars(pagerUrl($tab,$param,$page-1)) ?>" aria-label="Previous page"><i class="fas fa-chevron-left" style="font-size:10px;"></i></a>
          <?php foreach(pageWindow($page,$pages) as $p): ?>
            <?php if($p==='…'): ?><span class="pg-gap">…</span>
            <?php else: ?><a class="pg-btn <?= $p==$page?'active':'' ?>" href="<?= htmlspecialchars(pagerUrl($tab,$param,$p)) ?>"><?= $p ?></a><?php endif; ?>
          <?php endforeach; ?>
          <a class="pg-btn <?= $page>=$pages?'disabled':'' ?>" href="<?= $page>=$pages?'#':htmlspecialchars(pagerUrl($tab,$param,$page+1)) ?>" aria-label="Next page"><i class="fas fa-chevron-right" style="font-size:10px;"></i></a>
          <a class="pg-btn <?= $page>=$pages?'disabled':'' ?>" href="<?= $page>=$pages?'#':htmlspecialchars(pagerUrl($tab,$param,$pages)) ?>" aria-label="Last page"><i class="fas fa-angles-right" style="font-size:10px;"></i></a>
        </div>
      </div>
<?php }
?>
<?php require_once 'theme-head.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | User Management</title>
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

/* ── SIDEBAR (unchanged) ── */
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

/* ── NAVBAR (unchanged) ── */
.navbar { background:var(--sidebar); padding:0 28px; height:60px; position:fixed; left:260px; right:0; top:0; display:flex; align-items:center; justify-content:space-between; border-bottom:3px solid var(--sidebar-red); z-index:1000; }
.navbar h1 { color:#fff; font-size:18px; font-weight:600; letter-spacing:-0.2px; }
.navbar-right { display:flex; align-items:center; gap:12px; }
.bell-wrap { position:relative; }
.bell-btn { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); color:#fff; width:36px; height:36px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; position:relative; transition:background 0.15s; }
.bell-btn:hover { background:rgba(255,255,255,0.13); }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e53e3e; color:#fff; font-size:9px; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; border:2px solid var(--sidebar); pointer-events:none; }

/* ── NOTIFICATION DROPDOWN (unchanged) ── */
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

.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); padding:5px 12px 5px 6px; border-radius:20px; }
.user-avatar { width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:#fff; flex-shrink:0; }
.user-name { font-size:12.5px; color:#c8cce0; font-weight:500; }

/* ═══════════════════════════════════════════
   CONTENT AREA — REDESIGNED
   ═══════════════════════════════════════════ */
.content { margin-top:60px; padding:32px 36px 48px; flex:1; max-width:1400px; width:100%; }

/* Page header */
.page-top { display:flex; align-items:center; justify-content:flex-end; margin-bottom:20px; flex-wrap:wrap; gap:14px; }
.page-top h2 { font-size:24px; font-weight:600; color:var(--text); letter-spacing:-0.5px; margin:0 0 4px; }
.page-top p  { font-size:13px; color:var(--text-2); margin:0; }

.btn-add { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; border:none; padding:10px 20px; border-radius:999px; font-size:13px; font-weight:600; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:transform .12s, box-shadow .2s, filter .15s; white-space:nowrap; box-shadow:0 6px 16px -6px color-mix(in srgb, var(--accent) 70%, transparent); }
.btn-add:hover { filter:brightness(1.08); color:#fff; box-shadow:0 8px 20px -6px color-mix(in srgb, var(--accent) 80%, transparent); }
.btn-add:active { transform:scale(0.97); }
.btn-add .plus { width:18px; height:18px; border-radius:50%; background:rgba(255,255,255,.22); display:inline-flex; align-items:center; justify-content:center; font-size:9px; }

/* Alerts */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:18px; display:flex; align-items:center; gap:10px; border:1px solid; border-left-width:4px; }
.alert-banner.success { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; border-left-color:#16a34a; }
.alert-banner.error   { background:#fff5f5; color:#dc2626; border-color:#fecaca; border-left-color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* Segmented tabs */
.toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.toolbar .tab-bar { margin-bottom:0; }
.tab-bar { display:inline-flex; gap:4px; padding:4px; background:color-mix(in srgb, var(--text) 6%, var(--surface)); border-radius:999px; margin-bottom:20px; }
.tab-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; font-size:13px; font-weight:500; color:var(--text-2); background:transparent; border:none; border-radius:999px; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all .18s; }
.tab-btn:hover { color:var(--text); }
.tab-btn.active { background:var(--surface); color:var(--accent); font-weight:600; box-shadow:0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06); }
.tab-pill { background:color-mix(in srgb, var(--text) 8%, transparent); color:var(--text-2); font-size:10.5px; font-weight:600; padding:1px 8px; border-radius:20px; }
.tab-btn.active .tab-pill { background:color-mix(in srgb, var(--accent) 14%, transparent); color:var(--accent); }

/* Filter bar */
.filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
.search-box { position:relative; flex:1 1 280px; max-width:380px; }
.search-box i { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-size:12px; color:var(--text-3); pointer-events:none; }
.filter-input, .filter-select {
    font-size:13px; padding:8px 14px; border-radius:999px;
    border:1px solid var(--border); background:var(--surface); color:var(--text);
    font-family:'Plus Jakarta Sans',sans-serif; outline:none;
    transition:border-color .15s, box-shadow .15s; height:40px;
}
.filter-input { width:100%; padding-left:38px; }
.filter-input:focus, .filter-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 14%, transparent); }
.filter-select { appearance:none; -webkit-appearance:none; padding-right:32px; cursor:pointer; min-width:140px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 14px center; }
.filter-btn { display:inline-flex; align-items:center; gap:6px; padding:0 18px; border-radius:999px; font-size:13px; font-weight:600; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all .15s; height:40px; border:1px solid; text-decoration:none; }
.filter-btn.primary { background:var(--text); color:var(--surface); border-color:var(--text); }
.filter-btn.primary:hover { opacity:.85; }
.filter-btn.secondary { background:transparent; color:var(--text-2); border-color:transparent; }
.filter-btn.secondary:hover { background:color-mix(in srgb, var(--text) 6%, transparent); color:var(--text); }

/* Table card */
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.table-scroll { overflow-x:auto; }
.data-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.data-table thead th { padding:14px 20px; font-size:11px; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--text-3); background:transparent; border-bottom:1px solid var(--border); white-space:nowrap; text-align:left; }
.data-table tbody td { padding:14px 20px; color:var(--text); vertical-align:middle; border-bottom:1px solid var(--border-soft); }
.data-table tbody tr:last-child td { border-bottom:none; }
.data-table tbody tr { transition:background .12s; }
.data-table tbody tr:hover { background:color-mix(in srgb, var(--accent) 4%, var(--surface)); }
.data-table td.muted { color:var(--text-2); }
.data-table td.sid { font-weight:600; font-family:monospace; font-size:12.5px; color:var(--accent); }
.empty-row td { padding:56px 16px !important; text-align:center; color:var(--text-3); font-size:13px; }
.empty-row i { font-size:28px; display:block; margin-bottom:10px; opacity:.6; }

/* Table footer + pagination (same as student-records) */
.table-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px; background:var(--bg); border-top:1px solid var(--border); }
.foot-info { font-size:12.5px; color:var(--text-3); }
.foot-info strong { color:var(--text); font-weight:600; }
.pager { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pg-btn { min-width:36px; height:36px; padding:0 11px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text-2); font-size:13px; font-weight:600; text-decoration:none; transition:all .15s; }
.pg-btn:hover { background:var(--accent-light); color:var(--accent); border-color:var(--accent); transform:translateY(-1px); }
.pg-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 4px 12px color-mix(in srgb, var(--accent) 35%, transparent); pointer-events:none; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-gap { color:var(--text-3); padding:0 4px; font-size:13px; }

/* Person cell (avatar + name) */
.person { display:flex; align-items:center; gap:12px; }
.p-name { font-weight:600; color:var(--text); line-height:1.3; }
.p-sub  { font-size:11.5px; color:var(--text-3); line-height:1.3; }

/* Role badge */
.role-badge { display:inline-flex; align-items:center; gap:6px; padding:3px 11px 3px 9px; border-radius:20px; font-size:11.5px; font-weight:600; background:color-mix(in srgb, currentColor 11%, transparent); }
.role-badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
.role-badge.teacher { color:#2563eb; }
.role-badge.csu     { color:#16a34a; }
.role-badge.jassu   { color:#d97706; }
.role-badge.nurse   { color:#be185d; }
.role-badge.student { color:#6b7280; }

.type-chip { font-size:12px; color:var(--text-2); }

/* Icon action buttons */
.row-actions { display:flex; gap:6px; justify-content:flex-end; }
.act-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; width:34px; height:34px; border-radius:10px; font-size:12px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; border:1px solid var(--border); background:var(--surface); color:var(--text-2); transition:all .15s; }
.act-btn.wide { width:auto; padding:0 14px; }
.act-btn.edit:hover   { background:#fffbeb; color:#b45309; border-color:#fcd34d; }
.act-btn.pass:hover   { background:color-mix(in srgb, var(--accent) 10%, var(--surface)); color:var(--accent); border-color:color-mix(in srgb, var(--accent) 35%, var(--border)); }
.act-btn.danger:hover { background:#fff1f1; color:#dc2626; border-color:#fecaca; }

/* ── MODALS ── */
.modal-content { border:none; border-radius:20px; box-shadow:0 24px 64px rgba(0,0,0,.18); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; background:var(--surface); color:var(--text); }
.modal-backdrop.show { opacity:.45; }
.modal-header { padding:22px 26px 6px; border:none; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.m-title-wrap { display:flex; align-items:center; gap:12px; }
.m-icon { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:15px; background:color-mix(in srgb, var(--accent) 12%, transparent); color:var(--accent); flex-shrink:0; }
.m-icon.warn   { background:#fffbeb; color:#b45309; }
.m-icon.danger { background:#fff1f1; color:#dc2626; }
.modal-header .modal-title { font-size:17px; font-weight:600; color:var(--text); letter-spacing:-.2px; }
.modal-header .m-sub { font-size:12px; color:var(--text-3); margin-top:1px; }
.modal-header .btn-close { opacity:.35; }
.modal-header .btn-close:hover { opacity:.7; }
.modal-body { padding:16px 26px 8px; }
.modal-footer { padding:16px 26px 22px; border:none; display:flex; justify-content:flex-end; gap:8px; background:transparent; }

.f-group { margin-bottom:14px; }
.f-group:last-child { margin-bottom:0; }
.f-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.f-label { display:block; font-size:12px; font-weight:600; color:var(--text-2); margin-bottom:6px; }
.f-input, .f-select {
    width:100%; padding:10px 14px; border-radius:10px;
    border:1px solid var(--border); background:color-mix(in srgb, var(--text) 2.5%, var(--surface)); color:var(--text);
    font-size:13.5px; font-family:'Plus Jakarta Sans',sans-serif;
    outline:none; transition:border-color .15s, box-shadow .15s, background .15s;
}
.f-input:focus, .f-select:focus { border-color:var(--accent); background:var(--surface); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 14%, transparent); }
.f-select { appearance:none; -webkit-appearance:none; cursor:pointer; padding-right:34px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 14px center; }

.m-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 20px; border-radius:999px; font-size:13px; font-weight:600; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all .15s; border:1px solid; }
.m-btn.ghost   { background:transparent; color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:color-mix(in srgb, var(--text) 5%, transparent); color:var(--text); }
.m-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.primary:hover { filter:brightness(1.1); }
.m-btn.warn    { background:#f59e0b; color:#fff; border-color:#f59e0b; }
.m-btn.warn:hover { background:#d97706; border-color:#d97706; }
.m-btn.danger  { background:#dc2626; color:#fff; border-color:#dc2626; }
.m-btn.danger:hover { background:#b91c1c; }
.m-btn.info-btn { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.info-btn:hover { filter:brightness(1.1); }

.danger-note { display:flex; gap:10px; background:#fff5f5; border:1px solid #fecaca; color:#b91c1c; border-radius:12px; padding:12px 14px; font-size:12.5px; line-height:1.55; margin-bottom:16px; }
.danger-note i { margin-top:2px; }

@media (max-width:640px){ .f-row{ grid-template-columns:1fr; } .content{ padding:24px 16px; }
  .table-foot{ justify-content:center; text-align:center; padding:10px 12px; } .foot-info{ width:100%; } .pager{ justify-content:center; } .pg-btn{ min-width:32px; height:32px; padding:0 8px; font-size:12px; } }
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
      <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
      <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
      <h3>Backup &amp; Reports</h3>
      <li><a href="data-backup.php"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>
      <h3>System</h3>
      <li><a href="user-management.php" class="active"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
         <li><a href="theme-settings.php"><i class="fas fa-palette"></i><span>Theme Settings</span></a></li>
      <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
    </ul>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <!-- NAVBAR -->
  <nav class="navbar">
    <h1>User Management</h1>
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

    <!-- Tabs + Add button (one row) -->
    <div class="toolbar">
    <div class="tab-bar">
      <button class="tab-btn <?= $activeTab==='staff'?'active':'' ?>" id="staffTabBtn" onclick="switchTab('staff')">
        <i class="fas fa-id-badge" style="font-size:12px;"></i> Staff
        <span class="tab-pill"><?= $staffTotal ?></span>
      </button>
      <button class="tab-btn <?= $activeTab==='student'?'active':'' ?>" id="studentTabBtn" onclick="switchTab('student')">
        <i class="fas fa-user-graduate" style="font-size:12px;"></i> Student Accounts
        <span class="tab-pill"><?= $stuTotal ?></span>
      </button>
    </div>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <span class="plus"><i class="fas fa-plus"></i></span> Add Staff User
      </button>
    </div>

    <!-- STAFF PANEL -->
    <div id="staffPanel" style="display:<?= $activeTab==='staff'?'block':'none' ?>;">
      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="staff">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search_staff" class="filter-input" placeholder="Search name or username…" value="<?= htmlspecialchars($searchStaff) ?>">
        </div>
        <select name="role" class="filter-select">
          <option value="">All Roles</option>
          <option value="teacher" <?=$filterRole==='teacher'?'selected':''?>>Instructor</option>
          <option value="csu"     <?=$filterRole==='csu'    ?'selected':''?>>CSU</option>
          <option value="jassu"   <?=$filterRole==='jassu'  ?'selected':''?>>JASSU</option>
          <option value="nurse"   <?=$filterRole==='nurse'  ?'selected':''?>>Nurse</option>
        </select>
        <button type="submit" class="filter-btn primary">Search</button>
        <a href="user-management.php?tab=staff" class="filter-btn secondary">Reset</a>
      </form>
      <div class="table-wrap">
        <div class="table-scroll"><table class="data-table">
          <thead>
            <tr><th>Full Name</th><th>Username</th><th>Email</th><th>Staff Type</th><th>Role</th><th style="width:140px;"></th></tr>
          </thead>
          <tbody>
            <?php if(empty($staffUsers)): ?>
            <tr class="empty-row"><td colspan="6"><i class="fas fa-users"></i>No staff users found.</td></tr>
            <?php else: foreach($staffUsers as $u): ?>
            <tr>
              <td>
                <div class="person">
                  <div class="p-name"><?= htmlspecialchars($u['full_name']) ?></div>
                </div>
              </td>
              <td class="muted"><?= htmlspecialchars($u['username']) ?></td>
              <td class="muted"><?= htmlspecialchars($u['email']??'—') ?></td>
              <td><span class="type-chip"><?= staffTypeLabel($u['staff_type'] ?? null) ?></span></td>
              <td><span class="role-badge <?= htmlspecialchars($u['role']) ?>"><?= roleLabel($u['role']) ?></span></td>
              <td>
                <div class="row-actions">
                  <button class="act-btn edit"   title="Edit"            onclick='openEditUser(<?= json_encode($u, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-pencil-alt"></i></button>
                  <button class="act-btn pass"   title="Change password" onclick='openChangePass(<?= $u["id"] ?>)'><i class="fas fa-key"></i></button>
                  <button class="act-btn danger" title="Delete"          onclick='openDeleteUser(<?= $u["id"] ?>)'><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <?php renderPager('staff','pg_staff',$pgStaff,$staffPages,$staffTotal,$staffOffset,count($staffUsers),'user'); ?>
      </div>
    </div>

    <!-- STUDENT PANEL -->
    <div id="studentPanel" style="display:<?= $activeTab==='student'?'block':'none' ?>;">

      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="student">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search_stu" class="filter-input" placeholder="Search name, username or student ID…" value="<?= htmlspecialchars($searchStu) ?>">
        </div>
        <button type="submit" class="filter-btn primary">Search</button>
        <a href="user-management.php?tab=student" class="filter-btn secondary">Reset</a>
      </form>
      <div class="table-wrap">
        <div class="table-scroll"><table class="data-table">
          <thead>
            <tr><th>Student ID</th><th>Full Name</th><th>Username</th><th>Role</th><th style="width:190px;"></th></tr>
          </thead>
          <tbody>
            <?php if(empty($studentUsers)): ?>
            <tr class="empty-row"><td colspan="5"><i class="fas fa-user-graduate"></i>No student accounts found.</td></tr>
            <?php else: foreach($studentUsers as $u): ?>
            <tr>
              <td class="sid"><?= htmlspecialchars($u['sid']??'—') ?></td>
              <td class="name"><div class="p-name"><?= htmlspecialchars($u['full_name']) ?></div></td>
              <td class="muted"><?= htmlspecialchars($u['username']) ?></td>
              <td><span class="role-badge student">Student</span></td>
              <td>
                <div class="row-actions">
                  <button class="act-btn pass wide" onclick='openChangePass(<?= $u["id"] ?>)'><i class="fas fa-key" style="font-size:11px;"></i> Change Password</button>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <?php renderPager('student','pg_stu',$pgStu,$stuPages,$stuTotal,$stuOffset,count($studentUsers),'student'); ?>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── ADD USER MODAL ── -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="add_user">
      <div class="modal-header">
        <div class="m-title-wrap">
          <div class="m-icon"><i class="fas fa-user-plus"></i></div>
          <div><div class="modal-title">Add Staff User</div><div class="m-sub">Create a new staff account</div></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">Full Name</label><input type="text" name="full_name" class="f-input" required></div>
        <div class="f-row">
          <div class="f-group"><label class="f-label">Username</label><input type="text" name="username" class="f-input" required></div>
          <div class="f-group"><label class="f-label">Email</label><input type="email" name="email" class="f-input"></div>
        </div>
        <div class="f-row">
          <div class="f-group"><label class="f-label">Password</label><input type="password" name="password" class="f-input" required></div>
          <div class="f-group"><label class="f-label">Confirm Password</label><input type="password" name="confirm_password" class="f-input" required></div>
        </div>
        <div class="f-group">
          <label class="f-label">Staff Type</label>
          <select name="staff_type" id="addType" class="f-select" required onchange="populateRoles('addType','addRole')">
            <option value="">Select staff type…</option>
            <option value="teaching">Teaching Staff</option>
            <option value="non_teaching">Non-Teaching Staff</option>
          </select>
        </div>
        <div class="f-group" id="addRoleGroup" style="display:none;">
          <label class="f-label">Role</label>
          <select name="role" id="addRole" class="f-select"></select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn primary"><i class="fas fa-plus" style="font-size:11px;"></i> Save User</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="editUID">
      <div class="modal-header">
        <div class="m-title-wrap">
          <div class="m-icon warn"><i class="fas fa-pen"></i></div>
          <div><div class="modal-title">Edit User</div><div class="m-sub">Update account details</div></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">Full Name</label><input type="text" name="full_name" id="euName" class="f-input" required></div>
        <div class="f-row">
          <div class="f-group"><label class="f-label">Username</label><input type="text" name="username" id="euUsername" class="f-input" required></div>
          <div class="f-group"><label class="f-label">Email</label><input type="email" name="email" id="euEmail" class="f-input"></div>
        </div>
        <div class="f-group">
          <label class="f-label">Staff Type</label>
          <select name="staff_type" id="euType" class="f-select" required onchange="populateRoles('euType','euRole')">
            <option value="teaching">Teaching Staff</option>
            <option value="non_teaching">Non-Teaching Staff</option>
          </select>
        </div>
        <div class="f-group" id="euRoleGroup">
          <label class="f-label">Role</label>
          <select name="role" id="euRole" class="f-select"></select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn warn"><i class="fas fa-save" style="font-size:11px;"></i> Update</button>
      </div>
    </form>
  </div>
</div>

<!-- ── CHANGE PASSWORD MODAL ── -->
<div class="modal fade" id="changePassModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="cpUID">
      <div class="modal-header">
        <div class="m-title-wrap">
          <div class="m-icon"><i class="fas fa-key"></i></div>
          <div><div class="modal-title">Change Password</div><div class="m-sub">Set a new password for this account</div></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">New Password</label><input type="password" name="new_password" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Confirm New Password</label><input type="password" name="confirm_new_password" class="f-input" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn info-btn"><i class="fas fa-key" style="font-size:11px;"></i> Change Password</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE USER MODAL ── -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="user_id" id="delUID">
      <div class="modal-header">
        <div class="m-title-wrap">
          <div class="m-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
          <div><div class="modal-title">Delete Staff User</div><div class="m-sub">This can't be undone</div></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="danger-note"><i class="fas fa-info-circle"></i><span>This will permanently remove the staff account. This action cannot be undone.</span></div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching (no Bootstrap tabs — pure JS to stay consistent)
function switchTab(tab){
    document.getElementById('staffPanel').style.display   = tab==='staff'   ? 'block' : 'none';
    document.getElementById('studentPanel').style.display = tab==='student' ? 'block' : 'none';
    document.getElementById('staffTabBtn').classList.toggle('active',   tab==='staff');
    document.getElementById('studentTabBtn').classList.toggle('active', tab==='student');
}
// Restore tab from URL on load
(function(){
    const tab = new URLSearchParams(window.location.search).get('tab');
    if(tab==='student') switchTab('student');
})();

// ── STAFF TYPE → ROLE DROPDOWN ──
const STAFF_ROLES = <?= json_encode($STAFF_ROLES) ?>;

function populateRoles(typeId, roleId, selected){
    const type   = document.getElementById(typeId).value;
    const roleEl = document.getElementById(roleId);
    const group  = roleEl.closest('.f-group');
    roleEl.innerHTML = '';
    if(!type || !STAFF_ROLES[type]){ group.style.display='none'; roleEl.required=false; return; }
    const roles = STAFF_ROLES[type];
    const keys  = Object.keys(roles);
    if(keys.length > 1){
        roleEl.add(new Option('Select role…',''));          // several choices → force a pick
    }
    keys.forEach(k => roleEl.add(new Option(roles[k], k))); // teaching → just "Instructor"
    if(selected) roleEl.value = selected;
    roleEl.required = true;
    group.style.display = 'block';
}

// Reset the Add modal every time it closes
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function(){
    this.querySelector('form').reset();
    populateRoles('addType','addRole');
});

function openEditUser(u){
    document.getElementById('editUID').value    = u.id;
    document.getElementById('euName').value     = u.full_name;
    document.getElementById('euUsername').value = u.username;
    document.getElementById('euEmail').value    = u.email ?? '';
    document.getElementById('euType').value     = u.staff_type || (u.role==='teacher' ? 'teaching' : 'non_teaching');
    populateRoles('euType','euRole',u.role);
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
function openChangePass(uid){ document.getElementById('cpUID').value = uid; new bootstrap.Modal(document.getElementById('changePassModal')).show(); }
function openDeleteUser(uid){ document.getElementById('delUID').value = uid; new bootstrap.Modal(document.getElementById('deleteUserModal')).show(); }

// ── NOTIFICATIONS ──
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
