<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$error = $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    try {
        verify_csrf();
        if (login_user(trim((string) $_POST['email']), (string) $_POST['password'])) {
            header('Location: /admin/');
            exit;
        }
        $error = 'Unable to sign in with those credentials.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    verify_csrf();
    audit('logout', 'user', $_SESSION['user_id'] ?? null, null, null);
    session_unset(); session_destroy();
    header('Location: /admin/');
    exit;
}

$user = current_user();
if (!$user):
    $hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin sign in</title><link rel="stylesheet" href="/assets/css/admin.css"><link rel="stylesheet" href="/assets/css/admin-brand.css"><link rel="stylesheet" href="/assets/css/admin-polish.css"></head>
<body class="login-page"><main class="login-shell"><a class="admin-brand" href="/"><img src="/assets/tch-logo.png" alt="TCH"><strong>Organization Admin</strong></a><section class="login-card"><span class="eyebrow">Protected workspace</span><h1>Welcome back.</h1><p>Sign in to manage people and reporting lines.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$hasUsers): ?><div class="alert error">No administrator exists. Run the documented bootstrap command with deployment secrets.</div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="login" value="1"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Sign in</button></form></section></main></body></html>
<?php exit; endif;

$page = $_GET['page'] ?? 'dashboard';
if (!empty($user['force_password_change']) && $page !== 'users') {
    $page = 'users';
    $notice = 'Change your temporary password before continuing.';
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if (!empty($user['force_password_change']) && $action !== 'change_password' && $action !== 'logout') {
            throw new RuntimeException('Change your temporary password before continuing.');
        } elseif ($action === 'save_person') {
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            save_person($_POST, $id, $_FILES['photo'] ?? null);
            $notice = $id ? 'Person updated.' : 'Person added.';
        } elseif ($action === 'archive_person') {
            $id = (int) $_POST['id'];
            $before = db()->query("SELECT * FROM personnel WHERE id=$id")->fetch();
            db()->prepare("UPDATE personnel SET status='archived', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
            audit('archive', 'personnel', $id, $before ?: null, ['status' => 'archived']);
            $notice = 'Person archived.';
        } elseif ($action === 'move_person') {
            $id=(int)$_POST['id'];$managerId=($_POST['manager_id']??'')===''?null:(int)$_POST['manager_id'];
            if($managerId===$id||($managerId&&creates_cycle($id,$managerId)))throw new InvalidArgumentException('That move would create a circular hierarchy.');
            $stmt=db()->prepare('SELECT * FROM personnel WHERE id=?');$stmt->execute([$id]);$before=$stmt->fetch();if(!$before)throw new InvalidArgumentException('Person not found.');
            if($managerId){$stmt->execute([$managerId]);if(!$stmt->fetch())throw new InvalidArgumentException('Manager not found.');}
            db()->prepare('UPDATE personnel SET manager_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$managerId,$id]);audit('move','personnel',$id,$before,['manager_id'=>$managerId]);$notice='Reporting line updated.';
        } elseif ($action === 'save_department') {
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $name = trim((string) $_POST['name']); $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#0f766e';
            if (!$name) throw new InvalidArgumentException('Department name is required.');
            if ($id) db()->prepare('UPDATE departments SET name=?,color=?,description=?,display_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$color,trim((string)$_POST['description']),(int)$_POST['display_order'],$id]);
            else { db()->prepare('INSERT INTO departments(name,color,description,display_order) VALUES(?,?,?,?)')->execute([$name,$color,trim((string)$_POST['description']),(int)$_POST['display_order']]); $id=(int)db()->lastInsertId(); }
            audit('save', 'department', $id, null, ['name'=>$name,'color'=>$color]); $notice='Department saved.';
        } elseif ($action === 'save_user') {
            $name=trim((string)$_POST['name']);$email=trim((string)$_POST['email']);$password=(string)($_POST['password']??'');
            if (!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) throw new InvalidArgumentException('Provide a name, valid email, and password of at least 12 characters.');
            db()->prepare('INSERT INTO users(name,email,password_hash,force_password_change) VALUES(?,?,?,1)')->execute([$name,$email,password_hash($password,PASSWORD_ARGON2ID)]);
            $id=(int)db()->lastInsertId();audit('create','user',$id,null,['name'=>$name,'email'=>$email]);$notice='Administrator created.';
        } elseif ($action === 'toggle_user') {
            $id=(int)$_POST['id'];if($id===(int)$user['id'])throw new InvalidArgumentException('You cannot disable your own account.');
            $status=$_POST['status']==='active'?'active':'disabled';db()->prepare('UPDATE users SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$id]);audit('status','user',$id,null,['status'=>$status]);$notice='Administrator status updated.';
        } elseif ($action === 'change_password') {
            $password=(string)$_POST['password'];if(strlen($password)<12)throw new InvalidArgumentException('Password must contain at least 12 characters.');
            db()->prepare('UPDATE users SET password_hash=?,force_password_change=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password,PASSWORD_ARGON2ID),$user['id']]);audit('password','user',(int)$user['id'],null,null);$notice='Password updated.';
        } elseif ($action === 'settings') {
            $allowed=['organization_name','organization_tagline','primary_color','accent_color','show_email','show_phone'];
            $stmt=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP');
            foreach($allowed as $key)$stmt->execute([$key,(string)($_POST[$key]??'0')]);audit('update','settings',null,null,null);$notice='Organization settings saved.';
        } elseif ($action === 'preview_csv') {
            if (($_FILES['csv']['error']??1)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Choose a valid CSV file.');
            if (($_FILES['csv']['size']??0)>2*1024*1024)throw new InvalidArgumentException('CSV must be smaller than 2 MB.');
            $handle=fopen($_FILES['csv']['tmp_name'],'rb');$header=fgetcsv($handle);$required=['name','title','manager_id'];
            if(!$header||array_diff($required,$header))throw new InvalidArgumentException('CSV must contain name, title, and manager_id columns.');
            $rows=[];while(($row=fgetcsv($handle))!==false){if(count($rows)>=1000)throw new InvalidArgumentException('CSV is limited to 1,000 rows.');$rows[]=array_combine($header,$row);}fclose($handle);
            foreach($rows as $row)validate_person($row);
            $_SESSION['csv_preview']=$rows;$notice=count($rows).' validated rows ready to import.';$page='import';
        } elseif ($action === 'import_confirm') {
            $rows=$_SESSION['csv_preview']??[];if(!$rows)throw new InvalidArgumentException('Preview a CSV before importing.');
            db()->beginTransaction();try{foreach($rows as $row)save_person($row);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            unset($_SESSION['csv_preview']);audit('import','personnel',null,null,['rows'=>count($rows)]);$notice=count($rows).' people imported.';
        } elseif ($action === 'backup') {
            $file=create_backup();header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($file).'"');readfile($file);exit;
        }
    }
} catch (Throwable $e) { if(db()->inTransaction())db()->rollBack(); $error=$e->getMessage(); }

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="personnel.csv"');$out=fopen('php://output','wb');
    fputcsv($out,['id','name','title','department_id','location','email','phone','bio','status','manager_id','display_order','is_cherry_global','photo_path']);
    foreach(personnel(true) as $p)fputcsv($out,array_map(fn($k)=>$p[$k]??'', ['id','name','title','department_id','location','email','phone','bio','status','manager_id','display_order','is_cherry_global','photo_path']));
    audit('export','personnel',null,null,null);exit;
}
$people=personnel(true);$departments=departments();$config=settings();$peopleById=[];
foreach($people as $person)$peopleById[$person['id']]=$person;
$active=count(array_filter($people,fn($p)=>$p['status']==='active'));$archived=count($people)-$active;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Organization Admin</title><link rel="stylesheet" href="/assets/css/admin.css"><link rel="stylesheet" href="/assets/css/admin-extra.css"><link rel="stylesheet" href="/assets/css/admin-brand.css"><link rel="stylesheet" href="/assets/css/admin-polish.css"></head><body>
<aside class="sidebar"><a class="admin-brand" href="/admin/"><img src="/assets/tch-logo.png" alt="TCH"><strong>Organization Admin</strong></a><nav>
<?php foreach(['dashboard'=>'Overview','people'=>'People','hierarchy'=>'Hierarchy','departments'=>'Departments','import'=>'Import & backup','users'=>'Administrators','audit'=>'Audit history','settings'=>'Settings'] as $key=>$label): ?><a class="<?= $page===$key?'active':'' ?>" href="?page=<?= $key ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<a href="/" class="view-site">View public chart ↗</a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="logout" value="1"><button class="link-button">Sign out</button></form></aside>
<main class="admin-main"><header class="admin-header"><div><span class="eyebrow">Secure administration</span><h1><?= e(ucfirst($page)) ?></h1></div><div class="user-pill"><?= e($user['name']) ?></div></header>
<?php if($error):?><div class="alert error"><?= e($error) ?></div><?php endif;?><?php if($notice):?><div class="alert success"><?= e($notice) ?></div><?php endif;?>
<?php if($page==='dashboard'):?><section class="metrics"><article><span>Active people</span><strong><?= $active ?></strong></article><article><span>Departments</span><strong><?= count($departments) ?></strong></article><article><span>Archived</span><strong><?= $archived ?></strong></article><article><span>Administrators</span><strong><?= (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn() ?></strong></article></section><section class="panel"><h2>Hierarchy health</h2><p>The canonical organization structure is stored in SQLite with validated reporting lines, transaction-safe writes, and audit history.</p><a class="primary inline" href="?page=people">Manage people</a></section>
<?php elseif($page==='people'):?><section class="split"><div class="panel"><div class="panel-heading"><div><h2>Personnel directory</h2><p><?= count($people) ?> records</p></div><input id="tableSearch" type="search" placeholder="Search people…"></div><div class="table-wrap"><table><thead><tr><th>Person</th><th>Department</th><th>Manager</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($people as $p):?><tr data-search="<?= e(strtolower($p['name'].' '.$p['title'].' '.$p['department'])) ?>"><td><strong><?= e($p['name']) ?></strong><small><?= e($p['title']) ?></small></td><td><?= e($p['department']?:'—') ?></td><td><?= e($peopleById[$p['manager_id']]['name']??'Top level') ?></td><td><span class="status <?= e($p['status']) ?>"><?= e($p['status']) ?></span></td><td><a href="?page=people&edit=<?= $p['id'] ?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div>
<?php $edit=null;if(isset($_GET['edit']))foreach($people as $p)if($p['id']==(int)$_GET['edit'])$edit=$p;?><div class="panel sticky"><h2><?= $edit?'Edit person':'Add person' ?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_person"><input type="hidden" name="id" value="<?= e($edit['id']??'') ?>"><label>Name<input name="name" required value="<?= e($edit['name']??'') ?>"></label><label>Title<input name="title" value="<?= e($edit['title']??'') ?>"></label><label>Department<select name="department_id"><option value="">None</option><?php foreach($departments as $d):?><option value="<?= $d['id'] ?>" <?= ($edit['department_id']??null)==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach;?></select></label><label>Reports to<select name="manager_id"><option value="">Top level</option><?php foreach($people as $m):if(($edit['id']??null)==$m['id'])continue;?><option value="<?= $m['id'] ?>" <?= ($edit['manager_id']??null)==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option><?php endforeach;?></select></label><div class="form-grid"><label>Location<input name="location" value="<?= e($edit['location']??'') ?>"></label><label>Status<select name="status"><option value="active">Active</option><option value="archived" <?= ($edit['status']??'')==='archived'?'selected':'' ?>>Archived</option></select></label></div><div class="form-grid"><label>Email<input type="email" name="email" value="<?= e($edit['email']??'') ?>"></label><label>Phone<input name="phone" value="<?= e($edit['phone']??'') ?>"></label></div><label>Bio<textarea name="bio"><?= e($edit['bio']??'') ?></textarea></label><label>Photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><label class="check"><input type="checkbox" name="is_cherry_global" value="1" <?= !empty($edit['is_cherry_global'])?'checked':'' ?>> Cherry Global employee</label><button class="primary">Save person</button></form><?php if($edit&&$edit['status']==='active'):?><form method="post" class="danger-zone"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="archive_person"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><button class="danger">Archive person</button></form><?php endif;?></div></section>
<?php elseif($page==='hierarchy'):?><section class="panel"><h2>Visual reporting structure</h2><p>Move a person under a new manager. Circular relationships are rejected before saving.</p><div class="hierarchy-admin"><?php $renderTree=function($nodes)use(&$renderTree,$people){echo'<ul>';foreach($nodes as $node){echo'<li><article><strong>'.e($node['name']).'</strong><small>'.e($node['title']).'</small><form method="post"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="move_person"><input type="hidden" name="id" value="'.(int)$node['id'].'"><select name="manager_id"><option value="">Top level</option>';foreach($people as $m){if($m['id']==$node['id'])continue;echo'<option value="'.(int)$m['id'].'">'.e($m['name']).'</option>';}echo'</select><button class="secondary">Move under</button></form></article>';if($node['children'])$renderTree($node['children']);echo'</li>';}echo'</ul>';};$renderTree(hierarchy_data());?></div></section>
<?php elseif($page==='departments'):?><section class="split"><div class="panel"><h2>Departments</h2><?php foreach($departments as $d):?><div class="department-row"><i style="background:<?= e($d['color']) ?>"></i><strong><?= e($d['name']) ?></strong><span><?= e($d['description']) ?></span></div><?php endforeach;?></div><div class="panel sticky"><h2>Add department</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_department"><label>Name<input name="name" required></label><label>Color<input type="color" name="color" value="#0f766e"></label><label>Description<textarea name="description"></textarea></label><label>Display order<input type="number" name="display_order" value="0"></label><button class="primary">Save department</button></form></div></section>
<?php elseif($page==='import'):?><section class="split"><div class="panel"><h2>CSV import</h2><p>Required columns: <code>name</code>, <code>title</code>, <code>manager_id</code>. Uploads are validated before a transaction-based commit.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="preview_csv"><label>CSV file<input type="file" name="csv" accept=".csv,text/csv" required></label><button class="primary">Validate and preview</button></form><?php if(!empty($_SESSION['csv_preview'])):?><div class="csv-preview"><h3>Preview: <?= count($_SESSION['csv_preview']) ?> rows</h3><div class="table-wrap"><table><thead><tr><th>Name</th><th>Title</th><th>Manager ID</th></tr></thead><tbody><?php foreach(array_slice($_SESSION['csv_preview'],0,10) as $row):?><tr><td><?= e($row['name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['manager_id']) ?></td></tr><?php endforeach;?></tbody></table></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_confirm"><button class="primary">Import validated rows</button></form></div><?php endif;?></div><div class="panel"><h2>Export and backups</h2><p>Download portable data or create a consistent full database snapshot.</p><a class="primary inline" href="?page=import&export=csv">Export CSV</a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="backup"><button class="secondary">Download SQLite backup</button></form></div></section>
<?php elseif($page==='users'):?><section class="split"><div class="panel"><h2>Administrators</h2><?php foreach(db()->query('SELECT id,name,email,status,last_login_at FROM users ORDER BY name') as $u):?><div class="user-row"><div><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?> · <?= e($u['status']) ?></small></div><?php if($u['id']!=$user['id']):?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="status" value="<?= $u['status']==='active'?'disabled':'active' ?>"><button class="secondary"><?= $u['status']==='active'?'Disable':'Enable' ?></button></form><?php endif;?></div><?php endforeach;?></div><div><section class="panel"><h2>Add administrator</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_user"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Temporary password<input type="password" name="password" minlength="12" required></label><button class="primary">Create administrator</button></form></section><section class="panel"><h2>Change my password</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password"><label>New password<input type="password" name="password" minlength="12" required></label><button class="secondary">Update password</button></form></section></div></section>
<?php elseif($page==='audit'):?><section class="panel"><h2>Recent activity</h2><div class="table-wrap"><table><thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Target</th><th>IP</th></tr></thead><tbody><?php foreach(db()->query('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200') as $a):?><tr><td><?= e($a['created_at']) ?></td><td><?= e($a['user_name']?:'System') ?></td><td><?= e($a['action']) ?></td><td><?= e($a['target_type'].' #'.($a['target_id']??'—')) ?></td><td><?= e($a['ip_address']) ?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($page==='settings'):?><section class="panel narrow"><h2>Organization settings</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings"><label>Organization name<input name="organization_name" value="<?= e($config['organization_name']) ?>"></label><label>Tagline<input name="organization_tagline" value="<?= e($config['organization_tagline']) ?>"></label><div class="form-grid"><label>Primary color<input type="color" name="primary_color" value="<?= e($config['primary_color']) ?>"></label><label>Accent color<input type="color" name="accent_color" value="<?= e($config['accent_color']) ?>"></label></div><label class="check"><input type="checkbox" name="show_email" value="1" <?= $config['show_email']==='1'?'checked':'' ?>> Show email publicly</label><label class="check"><input type="checkbox" name="show_phone" value="1" <?= $config['show_phone']==='1'?'checked':'' ?>> Show phone publicly</label><button class="primary">Save settings</button></form></section><?php endif;?>
</main><script src="/assets/js/admin.js"></script></body></html>
