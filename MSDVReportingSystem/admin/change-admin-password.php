<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!password_verify($current, $row['password'])) {
        $err = 'Current password is incorrect.';
    } elseif ($new !== $confirm) {
        $err = 'New passwords do not match.';
    } elseif (strlen($new) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $_SESSION['user_id']]);
        $msg = 'Password changed successfully.';
    }
}
?>
     <?php require_once 'theme-head.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Change Password</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
body { background:var(--bg); min-height:100vh; font-family:'Plus Jakarta Sans',sans-serif; color:var(--text); display:flex; flex-direction:column; }

.topbar { background:var(--sidebar); height:60px; padding:0 28px; display:flex; align-items:center; gap:12px; border-bottom:3px solid var(--sidebar-red); color:#fff; flex-shrink:0; }
.topbar i { color:#c8cce0; }
.topbar h1 { font-size:18px; font-weight:600; letter-spacing:-0.2px; }

.wrap { flex:1; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
.card { width:100%; max-width:440px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 8px 32px rgba(0,0,0,0.06); overflow:hidden; }
.card-head { padding:22px 28px 18px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; gap:14px; }
.card-icon { width:42px; height:42px; border-radius:10px; background:var(--accent-light); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.card-head h2 { font-size:16px; font-weight:600; letter-spacing:-0.2px; }
.card-head p { font-size:12.5px; color:var(--text-3); margin-top:2px; }
.card-body { padding:22px 28px 26px; }

.alert { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:18px; display:flex; align-items:center; gap:10px; }
.alert.success { background:#f0fdf4; color:#15803d; }
.alert.error   { background:#fff0f0; color:#dc2626; }

.f-group { margin-bottom:16px; }
.f-label { display:block; font-size:12px; font-weight:600; color:var(--text-2); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
.f-wrap { position:relative; }
.f-input { width:100%; padding:10px 40px 10px 12px; border-radius:var(--r-sm); border:1px solid var(--border); background:#fff; color:var(--text); font-size:13.5px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; transition:border-color 0.15s, box-shadow 0.15s; }
.f-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.08); }
.f-input.bad  { border-color:#fca5a5; }
.f-input.good { border-color:#86efac; }
.eye { position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-3); width:34px; height:34px; cursor:pointer; border-radius:6px; font-size:13px; }
.eye:hover { color:var(--text-2); background:var(--bg); }
.f-hint { font-size:11.5px; margin-top:5px; color:var(--text-3); min-height:16px; }
.f-hint.bad  { color:#dc2626; }
.f-hint.good { color:#16a34a; }

.btn { display:flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:11px 18px; border-radius:var(--r-sm); font-size:13.5px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; border:1px solid; text-decoration:none; transition:all 0.15s; }
.btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); margin-top:6px; }
.btn.primary:hover { background:#1d4ed8; }
.btn.ghost { background:#fff; color:var(--text-2); border-color:var(--border); margin-top:10px; }
.btn.ghost:hover { background:var(--bg); }
</style>
</head>
<body>

<div class="topbar">
  <i class="fas fa-lock"></i>
  <h1>Change Password</h1>
</div>

<div class="wrap">
  <div class="card">
    <div class="card-head">
      <div class="card-icon"><i class="fas fa-key"></i></div>
      <div>
        <h2>Change Admin Password</h2>
        <p>Use at least 6 characters.</p>
      </div>
    </div>
    <div class="card-body">

      <?php if($msg): ?><div class="alert success"><i class="fas fa-check-circle"></i><?= $msg ?></div><?php endif; ?>
      <?php if($err): ?><div class="alert error"><i class="fas fa-exclamation-circle"></i><?= $err ?></div><?php endif; ?>

      <form method="POST" id="pwForm">
        <div class="f-group">
          <label class="f-label" for="cur">Current Password</label>
          <div class="f-wrap">
            <input type="password" name="current_password" id="cur" class="f-input" required autocomplete="current-password">
            <button type="button" class="eye" onclick="toggle('cur',this)" aria-label="Show password"><i class="fas fa-eye"></i></button>
          </div>
        </div>

        <div class="f-group">
          <label class="f-label" for="new">New Password</label>
          <div class="f-wrap">
            <input type="password" name="new_password" id="new" class="f-input" required autocomplete="new-password">
            <button type="button" class="eye" onclick="toggle('new',this)" aria-label="Show password"><i class="fas fa-eye"></i></button>
          </div>
          <div class="f-hint" id="newHint"></div>
        </div>

        <div class="f-group">
          <label class="f-label" for="conf">Confirm New Password</label>
          <div class="f-wrap">
            <input type="password" name="confirm_password" id="conf" class="f-input" required autocomplete="new-password">
            <button type="button" class="eye" onclick="toggle('conf',this)" aria-label="Show password"><i class="fas fa-eye"></i></button>
          </div>
          <div class="f-hint" id="confHint"></div>
        </div>

        <button type="submit" class="btn primary"><i class="fas fa-save" style="font-size:12px;"></i> Change Password</button>
        <a href="dashboard.php" class="btn ghost"><i class="fas fa-arrow-left" style="font-size:12px;"></i> Back to Dashboard</a>
      </form>

    </div>
  </div>
</div>

<script>
function toggle(id, btn){
    const input = document.getElementById(id);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}

const nw = document.getElementById('new'), cf = document.getElementById('conf');
const nh = document.getElementById('newHint'), ch = document.getElementById('confHint');

function checkNew(){
    if(!nw.value){ nh.textContent=''; nh.className='f-hint'; nw.className='f-input'; return; }
    const ok = nw.value.length >= 6;
    nh.textContent = ok ? 'Looks good' : 'At least 6 characters';
    nh.className = 'f-hint ' + (ok ? 'good' : 'bad');
    nw.className = 'f-input ' + (ok ? 'good' : 'bad');
}
function checkConf(){
    if(!cf.value){ ch.textContent=''; ch.className='f-hint'; cf.className='f-input'; return; }
    const ok = cf.value === nw.value;
    ch.textContent = ok ? 'Passwords match' : 'Passwords do not match';
    ch.className = 'f-hint ' + (ok ? 'good' : 'bad');
    cf.className = 'f-input ' + (ok ? 'good' : 'bad');
}
nw.addEventListener('input', () => { checkNew(); checkConf(); });
cf.addEventListener('input', checkConf);
</script>
</body>
</html>
