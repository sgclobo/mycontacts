<?php
// ════════════════════════════════════════════════════════════════
//  Hau Nia Kontaktu v2 — PHP/SQLite Personal Contacts App
// ════════════════════════════════════════════════════════════════

$db_path = __DIR__ . '/data/contacts.db';
if (!is_dir(__DIR__ . '/data'))   mkdir(__DIR__ . '/data',   0755, true);
if (!is_dir(__DIR__ . '/photos')) mkdir(__DIR__ . '/photos', 0755, true);

$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL;");

$db->exec("
  CREATE TABLE IF NOT EXISTS groups_list (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
  );
  CREATE TABLE IF NOT EXISTS contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    phone      TEXT NOT NULL,
    whatsapp   TEXT,
    email      TEXT,
    photo      TEXT,
    group_id   INTEGER REFERENCES groups_list(id) ON DELETE SET NULL,
    notes      TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  );
");

// ── Auto-migrate: add new columns if they don't exist yet ───────
$existing_cols = array_column(
  $db->query("PRAGMA table_info(contacts)")->fetchAll(PDO::FETCH_ASSOC),
  'name'
);
$migrations = [
  'whatsapp' => "ALTER TABLE contacts ADD COLUMN whatsapp TEXT",
  'email'    => "ALTER TABLE contacts ADD COLUMN email TEXT",
  'photo'    => "ALTER TABLE contacts ADD COLUMN photo TEXT",
  'notes'    => "ALTER TABLE contacts ADD COLUMN notes TEXT",
];
foreach ($migrations as $col => $sql) {
  if (!in_array($col, $existing_cols)) {
    $db->exec($sql);
  }
}

// ── API ─────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action !== '') {
  header('Content-Type: application/json');
  function jsonOut($d){ echo json_encode($d); exit; }
  function err($m){ http_response_code(400); jsonOut(['ok'=>false,'error'=>$m]); }

  // GET
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'contacts') {
      $gid = $_GET['group'] ?? '';
      if ($gid !== '') {
        $st = $db->prepare("SELECT c.*,g.name AS group_name FROM contacts c LEFT JOIN groups_list g ON c.group_id=g.id WHERE c.group_id=? ORDER BY c.name COLLATE NOCASE");
        $st->execute([$gid]);
      } else {
        $st = $db->query("SELECT c.*,g.name AS group_name FROM contacts c LEFT JOIN groups_list g ON c.group_id=g.id ORDER BY c.name COLLATE NOCASE");
      }
      jsonOut(['ok'=>true,'contacts'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'groups') {
      jsonOut(['ok'=>true,'groups'=>$db->query("SELECT * FROM groups_list ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'contact') {
      $id = (int)($_GET['id'] ?? 0);
      $st = $db->prepare("SELECT c.*,g.name AS group_name FROM contacts c LEFT JOIN groups_list g ON c.group_id=g.id WHERE c.id=?");
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) err('La hetan kontaktu.');
      jsonOut(['ok'=>true,'contact'=>$row]);
    }
  }

  // POST
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Photo upload (multipart) — must be handled before reading php://input
    if ($action === 'upload_photo') {
      if (empty($_FILES['photo'])) err('Laiha foto.');
      $file = $_FILES['photo'];
      $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
      if (!in_array($file['type'], $allowed)) err('Tipu foto la suporta.');
      if ($file['size'] > 5 * 1024 * 1024) err('Foto boot liu (max 5 MB).');
      $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg');
      $name = uniqid('photo_', true) . '.' . $ext;
      $dest = __DIR__ . '/photos/' . $name;
      if (!move_uploaded_file($file['tmp_name'], $dest)) err('Sala hodi salva foto.');
      jsonOut(['ok'=>true,'photo'=>$name]); // exits here
    }

    // All other POST actions send JSON body
    $raw = file_get_contents('php://input');
    $b   = json_decode($raw, true);
    if (!is_array($b)) err('Pedidu invalidu (JSON la valid).');

    if ($action === 'add_contact') {
      $name     = trim($b['name']     ?? '');
      $phone    = trim($b['phone']    ?? '');
      $whatsapp = trim($b['whatsapp'] ?? '');
      $email    = trim($b['email']    ?? '');
      $photo    = trim($b['photo']    ?? '');
      $notes    = trim($b['notes']    ?? '');
      $gid      = !empty($b['group_id']) ? (int)$b['group_id'] : null;
      if (!$name || !$phone) err('Naran no numeru obrigatoriu.');
      try {
        $st = $db->prepare("INSERT INTO contacts (name,phone,whatsapp,email,photo,group_id,notes) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$name,$phone,$whatsapp,$email,$photo,$gid,$notes]);
        jsonOut(['ok'=>true,'id'=>$db->lastInsertId()]);
      } catch (Exception $e) { err('DB error: ' . $e->getMessage()); }
    }

    if ($action === 'edit_contact') {
      $id       = (int)($b['id']      ?? 0);
      $name     = trim($b['name']     ?? '');
      $phone    = trim($b['phone']    ?? '');
      $whatsapp = trim($b['whatsapp'] ?? '');
      $email    = trim($b['email']    ?? '');
      $photo    = trim($b['photo']    ?? '');
      $notes    = trim($b['notes']    ?? '');
      $gid      = !empty($b['group_id']) ? (int)$b['group_id'] : null;
      if (!$id || !$name || !$phone) err('Dados invalidu.');
      $db->prepare("UPDATE contacts SET name=?,phone=?,whatsapp=?,email=?,photo=?,group_id=?,notes=? WHERE id=?")
         ->execute([$name,$phone,$whatsapp,$email,$photo,$gid,$notes,$id]);
      jsonOut(['ok'=>true]);
    }

    if ($action === 'delete_contact') {
      $id = (int)($b['id'] ?? 0);
      if (!$id) err('ID invalidu.');
      // Remove photo file
      $row = $db->prepare("SELECT photo FROM contacts WHERE id=?");
      $row->execute([$id]);
      $c = $row->fetch();
      if ($c && $c['photo']) @unlink(__DIR__ . '/photos/' . $c['photo']);
      $db->prepare("DELETE FROM contacts WHERE id=?")->execute([$id]);
      jsonOut(['ok'=>true]);
    }

    if ($action === 'add_group') {
      $name = trim($b['name'] ?? '');
      if (!$name) err('Naran grupu obrigatoriu.');
      try {
        $db->prepare("INSERT INTO groups_list (name) VALUES (?)")->execute([$name]);
        jsonOut(['ok'=>true,'id'=>$db->lastInsertId()]);
      } catch (Exception $e) { err("Grupu '{$name}' iha ona."); }
    }

    if ($action === 'delete_group') {
      $id = (int)($b['id'] ?? 0);
      if (!$id) err('ID invalidu.');
      $db->prepare("DELETE FROM groups_list WHERE id=?")->execute([$id]);
      jsonOut(['ok'=>true]);
    }
  }

  err('Aksaun la rekonhesidu.');
  exit;
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hau Nia Kontaktu</title>
  <link rel="icon" type="image/png" href="photos/drsl.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

  <style>
    :root {
      --ink: #0c1426;
      --ink2: #374162;
      --muted: #8b96b0;
      --bg: #f0f3fa;
      --surface: #ffffff;
      --border: rgba(15,30,80,0.08);
      --accent: #1a56db;
      --accent-light: #e8f0ff;
      --accent2: #0ea5e9;
      --green: #059669;
      --green-light: #d1fae5;
      --wa: #25d366;
      --wa-light: #dcfce7;
      --danger: #dc2626;
      --danger-light: #fef2f2;
      --warn: #d97706;
      --warn-light: #fff7ed;
      --radius: 16px;
      --radius-sm: 10px;
      --shadow-sm: 0 1px 4px rgba(0,0,0,.05), 0 2px 10px rgba(26,86,219,.06);
      --shadow: 0 4px 24px rgba(26,86,219,.1), 0 1px 4px rgba(0,0,0,.04);
      --shadow-lg: 0 12px 48px rgba(26,86,219,.16), 0 2px 8px rgba(0,0,0,.06);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Outfit', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; }

    /* ── HEADER ── */
    header {
      background: var(--surface); border-bottom: 1px solid var(--border);
      padding: .85rem 1.5rem; position: sticky; top: 0; z-index: 300;
      box-shadow: 0 1px 0 var(--border), 0 2px 12px rgba(0,0,0,.04);
    }
    .header-inner { max-width: 720px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .logo-wrap { display: flex; align-items: center; gap: .7rem; }
    .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.1rem; flex-shrink: 0; box-shadow: 0 3px 10px rgba(26,86,219,.35); }
    .logo-title { font-size: 1.05rem; font-weight: 800; line-height: 1.1; letter-spacing: -.01em; }
    .logo-sub   { font-size: .66rem; color: var(--muted); font-weight: 500; letter-spacing: .04em; }
    .badge-count { background: var(--accent-light); color: var(--accent); font-size: .73rem; font-weight: 700; padding: .22rem .7rem; border-radius: 20px; white-space: nowrap; }

    /* ── PAGE ── */
    .page-wrap { max-width: 720px; margin: 0 auto; padding: 1.5rem 1rem 6rem; }

    /* ── TOOLBAR ── */
    .toolbar { display: flex; gap: .6rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
    .search-wrap { position: relative; flex: 1; min-width: 180px; }
    .search-wrap .bi { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .85rem; pointer-events: none; }
    .search-input { width: 100%; background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .62rem .9rem .62rem 2.4rem; font-size: .88rem; font-family: inherit; color: var(--ink); outline: none; transition: border-color .15s, box-shadow .15s; }
    .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(26,86,219,.1); }
    .search-input::placeholder { color: var(--muted); }

    .btn-pill { background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .58rem .9rem; font-size: .82rem; font-weight: 600; color: var(--ink2); cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: .38rem; font-family: inherit; transition: all .15s; }
    .btn-pill:hover { background: var(--accent-light); border-color: var(--accent); color: var(--accent); }

    .btn-primary-pill { background: linear-gradient(135deg, var(--accent), #1e6bff); border: none; border-radius: var(--radius-sm); padding: .6rem 1.1rem; font-size: .87rem; font-weight: 700; color: #fff; cursor: pointer; display: flex; align-items: center; gap: .4rem; font-family: inherit; transition: all .15s; white-space: nowrap; box-shadow: 0 2px 10px rgba(26,86,219,.3); }
    .btn-primary-pill:hover { box-shadow: 0 4px 16px rgba(26,86,219,.4); transform: translateY(-1px); }
    .btn-primary-pill:active { transform: scale(.97); }

    /* ── GROUP TABS ── */
    .group-tabs { display: flex; gap: .4rem; overflow-x: auto; margin-bottom: 1.2rem; padding-bottom: 3px; scrollbar-width: none; }
    .group-tabs::-webkit-scrollbar { display: none; }
    .gtab { background: var(--surface); border: 1.5px solid var(--border); border-radius: 20px; padding: .28rem .85rem; font-size: .78rem; font-weight: 600; color: var(--ink2); cursor: pointer; white-space: nowrap; font-family: inherit; transition: all .15s; display: flex; align-items: center; gap: .3rem; }
    .gtab:hover { border-color: var(--accent); color: var(--accent); }
    .gtab.active { background: var(--accent); border-color: var(--accent); color: #fff; }

    /* ── SECTION LABEL ── */
    .sec-label { font-size: .68rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; color: var(--muted); margin-bottom: .7rem; padding-left: .1rem; }

    /* ── CONTACT CARDS ── */
    .contacts-grid { display: flex; flex-direction: column; gap: .55rem; }
    .c-card { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1.5px solid var(--border); padding: .8rem 1rem; display: flex; align-items: center; gap: .85rem; animation: fadeUp .25s ease both; transition: transform .15s, box-shadow .15s; }
    .c-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    /* Avatar / photo thumbnail */
    .c-thumb { width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 800; color: #fff; background: var(--accent); border: 2px solid var(--border); }
    .c-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .av0{background:linear-gradient(135deg,#1a56db,#0ea5e9)}
    .av1{background:linear-gradient(135deg,#e5002b,#f97316)}
    .av2{background:linear-gradient(135deg,#059669,#10b981)}
    .av3{background:linear-gradient(135deg,#d97706,#fbbf24)}
    .av4{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .av5{background:linear-gradient(135deg,#0891b2,#06b6d4)}
    .av6{background:linear-gradient(135deg,#db2777,#f472b6)}
    .av7{background:linear-gradient(135deg,#65a30d,#a3e635)}
    .av8{background:linear-gradient(135deg,#9333ea,#c084fc)}
    .av9{background:linear-gradient(135deg,#0f766e,#2dd4bf)}

    .c-info { flex: 1; min-width: 0; }
    .c-name-link { font-size: .92rem; font-weight: 700; color: var(--ink); text-decoration: none; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; transition: color .15s; }
    .c-name-link:hover { color: var(--accent); }
    .c-phone-row { display: flex; align-items: center; gap: .4rem; margin-top: .12rem; flex-wrap: wrap; }
    .c-phone { font-family: 'DM Mono', monospace; font-size: .79rem; color: var(--ink2); letter-spacing: .03em; }
    .c-group-tag { display: inline-flex; align-items: center; margin-top: .2rem; background: var(--accent-light); color: var(--accent); font-size: .62rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; padding: .1rem .45rem; border-radius: 8px; }

    .c-actions { display: flex; gap: .32rem; flex-shrink: 0; align-items: center; }
    .btn-ic { width: 33px; height: 33px; border: none; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: .82rem; cursor: pointer; transition: all .15s; flex-shrink: 0; }
    .btn-ic:active { transform: scale(.88); }
    .btn-copy-ic  { background: var(--accent-light); color: var(--accent); }
    .btn-copy-ic:hover  { background: #d0e0ff; }
    .btn-copy-ic.copied { background: var(--green-light); color: var(--green); }
    .btn-view-ic  { background: #f0fdf4; color: var(--green); }
    .btn-view-ic:hover  { background: var(--green-light); }
    .btn-edit-ic  { background: var(--warn-light); color: var(--warn); }
    .btn-edit-ic:hover  { background: #fde8c8; }
    .btn-del-ic   { background: var(--danger-light); color: var(--danger); }
    .btn-del-ic:hover   { background: #fecaca; }

    /* ── EMPTY ── */
    .empty { text-align: center; padding: 3.5rem 1rem 2rem; color: var(--muted); }
    .empty-icon  { font-size: 2.5rem; opacity: .3; margin-bottom: .75rem; }
    .empty-title { font-size: .92rem; font-weight: 700; color: var(--ink2); margin-bottom: .3rem; }
    .empty-sub   { font-size: .79rem; }

    /* ── MODALS base ── */
    .modal-content { border-radius: 20px; border: none; box-shadow: var(--shadow-lg); overflow: hidden; }
    .modal-header  { border-bottom: 1px solid var(--border); padding: 1.1rem 1.4rem .95rem; background: linear-gradient(135deg, #f7faff 0%, #fff 100%); }
    .modal-title   { font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: .5rem; }
    .modal-body    { padding: 1.4rem; max-height: 75vh; overflow-y: auto; }
    .modal-footer  { border-top: 1px solid var(--border); padding: 1rem 1.4rem; }

    .field-label { font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: .32rem; }
    .field-input { width: 100%; background: var(--bg); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .62rem .9rem; font-size: .9rem; font-family: inherit; color: var(--ink); outline: none; transition: border-color .15s, box-shadow .15s; margin-bottom: .85rem; }
    .field-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(26,86,219,.1); background: #fff; }
    .field-input::placeholder { color: var(--muted); }
    select.field-input { cursor: pointer; }
    textarea.field-input { resize: vertical; min-height: 72px; }

    .btn-modal-save   { background: linear-gradient(135deg, var(--accent), #1e6bff); color: #fff; border: none; border-radius: var(--radius-sm); padding: .65rem 1.4rem; font-size: .88rem; font-weight: 700; font-family: inherit; cursor: pointer; transition: all .15s; box-shadow: 0 2px 8px rgba(26,86,219,.3); }
    .btn-modal-save:hover { box-shadow: 0 4px 14px rgba(26,86,219,.4); transform: translateY(-1px); }
    .btn-modal-cancel { background: transparent; color: var(--ink2); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .63rem 1.1rem; font-size: .88rem; font-weight: 600; font-family: inherit; cursor: pointer; transition: all .15s; }
    .btn-modal-cancel:hover { background: var(--bg); }

    /* ── PHOTO UPLOAD ── */
    .photo-upload-area {
      border: 2px dashed var(--border); border-radius: var(--radius-sm);
      padding: 1.2rem; text-align: center; cursor: pointer;
      transition: all .15s; margin-bottom: .85rem; background: var(--bg);
      position: relative;
    }
    .photo-upload-area:hover { border-color: var(--accent); background: var(--accent-light); }
    .photo-upload-area.has-photo { border-style: solid; border-color: var(--accent); padding: .5rem; }
    .photo-preview { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; display: block; margin: 0 auto .6rem; border: 3px solid var(--accent); }
    .photo-upload-hint { font-size: .8rem; color: var(--muted); font-weight: 500; }
    .photo-upload-hint strong { color: var(--accent); }
    .photo-btns { display: flex; gap: .5rem; justify-content: center; margin-top: .6rem; flex-wrap: wrap; }
    .btn-photo-action { font-size: .75rem; font-weight: 600; border: 1.5px solid var(--border); background: var(--surface); border-radius: 8px; padding: .3rem .7rem; cursor: pointer; font-family: inherit; color: var(--ink2); display: flex; align-items: center; gap: .3rem; transition: all .15s; }
    .btn-photo-action:hover { border-color: var(--accent); color: var(--accent); }
    .btn-photo-action.cam { border-color: var(--accent2); color: var(--accent2); }
    .btn-photo-action.cam:hover { background: #e0f7fe; }
    #photoFileInput, #photoCamInput { display: none; }

    /* ── VIEW CONTACT MODAL ── */
    .view-hero {
      background: linear-gradient(160deg, var(--accent) 0%, var(--accent2) 100%);
      padding: 2rem 1.5rem 3.5rem; text-align: center; margin: -1.4rem -1.4rem 0; border-radius: 0;
      position: relative;
    }
    .view-avatar {
      width: 90px; height: 90px; border-radius: 50%; border: 4px solid rgba(255,255,255,.5);
      object-fit: cover; margin: 0 auto .75rem; display: block; background: rgba(255,255,255,.2);
    }
    .view-avatar-initials {
      width: 90px; height: 90px; border-radius: 50%; border: 4px solid rgba(255,255,255,.4);
      margin: 0 auto .75rem; display: flex; align-items: center; justify-content: center;
      font-size: 2rem; font-weight: 800; color: #fff; background: rgba(255,255,255,.15);
    }
    .view-name  { font-size: 1.4rem; font-weight: 800; color: #fff; margin-bottom: .2rem; }
    .view-group { display: inline-block; background: rgba(255,255,255,.2); color: rgba(255,255,255,.9); font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: .18rem .6rem; border-radius: 10px; }

    .view-body { padding: 0 1.4rem 1.4rem; }
    .view-actions {
      display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap;
      margin-top: -1.6rem; margin-bottom: 1.5rem; position: relative; z-index: 2;
    }
    .btn-action-pill {
      background: var(--surface); border: 2px solid var(--border); border-radius: 14px;
      padding: .6rem 1rem; display: flex; flex-direction: column; align-items: center; gap: .25rem;
      font-family: inherit; cursor: pointer; transition: all .15s; min-width: 68px; box-shadow: var(--shadow-sm);
    }
    .btn-action-pill .icon { font-size: 1.15rem; }
    .btn-action-pill .label { font-size: .62rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
    .btn-action-pill.call  { border-color: #d1fae5; } .btn-action-pill.call:hover  { background: #d1fae5; }
    .btn-action-pill.wa    { border-color: #dcfce7; } .btn-action-pill.wa:hover    { background: #dcfce7; }
    .btn-action-pill.mail  { border-color: #dbeafe; } .btn-action-pill.mail:hover  { background: #dbeafe; }
    .btn-action-pill.edit  { border-color: var(--warn-light); } .btn-action-pill.edit:hover  { background: var(--warn-light); }

    .view-detail-row {
      display: flex; align-items: flex-start; gap: .8rem;
      padding: .75rem 0; border-bottom: 1px solid var(--border);
    }
    .view-detail-row:last-child { border-bottom: none; }
    .vd-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: .88rem; flex-shrink: 0; }
    .vd-icon.ph  { background: #d1fae5; color: var(--green); }
    .vd-icon.wa  { background: #dcfce7; color: var(--wa); }
    .vd-icon.em  { background: #dbeafe; color: var(--accent); }
    .vd-icon.nt  { background: #fef9c3; color: #854d0e; }
    .vd-label { font-size: .66rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); margin-bottom: .12rem; }
    .vd-value { font-size: .92rem; font-weight: 600; color: var(--ink); word-break: break-all; }
    .vd-value a { color: inherit; text-decoration: none; }
    .vd-value a:hover { color: var(--accent); }
    .vd-copy { background: var(--accent-light); color: var(--accent); border: none; border-radius: 7px; padding: .2rem .55rem; font-size: .7rem; font-weight: 700; cursor: pointer; margin-left: .5rem; font-family: inherit; transition: all .15s; }
    .vd-copy:hover { background: #d0e0ff; }

    /* ── GROUP MANAGER ── */
    .group-row { display: flex; align-items: center; gap: .6rem; background: var(--bg); border-radius: 10px; padding: .55rem .85rem; border: 1.5px solid var(--border); }

    /* ── EXPORT BAR ── */
    .export-bar { max-width: 720px; margin: 1.2rem auto 0; padding: 0 1rem; display: flex; gap: .6rem; }
    .btn-exp { flex: 1; background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .5rem .75rem; font-size: .76rem; font-weight: 600; color: var(--ink2); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .4rem; font-family: inherit; transition: all .15s; }
    .btn-exp:hover { background: var(--accent-light); border-color: var(--accent); color: var(--accent); }

    /* ── TOAST ── */
    .toast-container { position: fixed; bottom: 1.4rem; left: 50%; transform: translateX(-50%); z-index: 9999; }

    /* ── DIVIDER ── */
    .form-divider { display: flex; align-items: center; gap: .6rem; margin: .4rem 0 .85rem; color: var(--muted); font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }
    .form-divider::before, .form-divider::after { content:''; flex: 1; height: 1px; background: var(--border); }

    /* ── SPINNER ── */
    .spinner-wrap { text-align: center; padding: 2.5rem; color: var(--muted); }

    @media (max-width: 480px) {
      .btn-primary-pill span { display: none; }
      .c-actions .btn-edit-ic { display: none; } /* show only in view modal on small screens */
    }
  </style>
</head>
<body>

<!-- HEADER -->
<header>
  <div class="header-inner">
    <div class="logo-wrap">
      <div class="logo-icon"><i class="bi bi-person-lines-fill"></i></div>
      <div>
        <div class="logo-title">Hau Nia Kontaktu</div>
        <div class="logo-sub">Backup Kontaktu Pesoál</div>
      </div>
    </div>
    <div class="badge-count" id="badgeCount">…</div>
  </div>
</header>

<div class="page-wrap">

  <!-- Toolbar -->
  <div class="toolbar">
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input class="search-input" type="text" id="searchInput" placeholder="Buka naran, numeru, email…" oninput="renderContacts()">
    </div>
    <button class="btn-pill" onclick="openGroupModal()">
      <i class="bi bi-folder-plus"></i> Grupu
    </button>
    <a class="btn-pill" style="text-decoration: none;" href="edtl.html">
      <i class="bi bi-lightning-charge"></i> EDTL
    </a>
    <button class="btn-primary-pill" onclick="openAddModal()">
      <i class="bi bi-person-plus-fill"></i> <span>Foun</span>
    </button>
  </div>

  <!-- Group tabs -->
  <div class="group-tabs" id="groupTabs">
    <button class="gtab active" onclick="filterGroup('',this)"><i class="bi bi-people-fill"></i> Hotu</button>
  </div>

  <!-- List -->
  <div class="sec-label" id="secLabel">Kontaktu hotu</div>
  <div class="contacts-grid" id="contactsGrid">
    <div class="spinner-wrap"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
  </div>
</div>

<!-- Export bar -->
<div class="export-bar">
  <button class="btn-exp" onclick="exportCSV()"><i class="bi bi-filetype-csv"></i> Exporta CSV</button>
  <button class="btn-exp" onclick="exportTXT()"><i class="bi bi-file-text"></i> Exporta TXT</button>
</div>


<!-- ════ MODAL: ADD / EDIT CONTACT ════ -->
<div class="modal fade" id="contactModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title" id="contactModalTitle"><i class="bi bi-person-plus text-primary"></i> Aumenta Kontaktu Foun</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editId">
        <input type="hidden" id="currentPhoto">

        <!-- Photo upload -->
        <label class="field-label">Foto Perfil</label>
        <div class="photo-upload-area" id="photoUploadArea" onclick="document.getElementById('photoFileInput').click()">
          <div id="photoPlaceholder">
            <i class="bi bi-person-circle" style="font-size:2rem;color:var(--muted);display:block;margin-bottom:.5rem"></i>
            <div class="photo-upload-hint">Klik atu <strong>hili foto</strong></div>
          </div>
          <img id="photoPreviewImg" class="photo-preview" src="" alt="" style="display:none">
          <div class="photo-btns" onclick="event.stopPropagation()">
            <button class="btn-photo-action" onclick="document.getElementById('photoFileInput').click()"><i class="bi bi-image"></i> Galeria</button>
            <button class="btn-photo-action cam" onclick="document.getElementById('photoCamInput').click()"><i class="bi bi-camera"></i> Kamera</button>
            <button class="btn-photo-action" id="btnRemovePhoto" onclick="removePhoto()" style="display:none;color:var(--danger);border-color:#fecaca"><i class="bi bi-x-circle"></i> Hasai</button>
          </div>
        </div>
        <input type="file" id="photoFileInput" accept="image/*" onchange="handlePhotoFile(this)">
        <input type="file" id="photoCamInput"  accept="image/*" capture="environment" onchange="handlePhotoFile(this)">

        <!-- Fields -->
        <div class="form-divider">Informasaun Báziku</div>

        <label class="field-label">Naran Kompletu <span style="color:var(--danger)">*</span></label>
        <input class="field-input" type="text" id="inputName" placeholder="ex. João da Silva">

        <label class="field-label">Numeru Telefone <span style="color:var(--danger)">*</span></label>
        <input class="field-input" type="tel" id="inputPhone" placeholder="ex. +670 7723 4567">

        <div class="form-divider">Kontaktu Adisionál</div>

        <label class="field-label"><i class="bi bi-whatsapp" style="color:var(--wa)"></i> WhatsApp</label>
        <input class="field-input" type="tel" id="inputWhatsapp" placeholder="ex. +670 7723 4567 (se diferente)">

        <label class="field-label"><i class="bi bi-envelope" style="color:var(--accent)"></i> Email</label>
        <input class="field-input" type="email" id="inputEmail" placeholder="ex. joao@example.com">

        <div class="form-divider">Klasifikasaun</div>

        <label class="field-label">Grupu</label>
        <select class="field-input" id="inputGroup">
          <option value="">— La iha grupu —</option>
        </select>

        <label class="field-label">Nota</label>
        <textarea class="field-input" id="inputNotes" placeholder="Informasaun adisionál…" style="margin-bottom:0"></textarea>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-modal-cancel" data-bs-dismiss="modal">Kansela</button>
        <button class="btn-modal-save" onclick="saveContact()"><i class="bi bi-floppy me-1"></i> Salva Kontaktu</button>
      </div>
    </div>
  </div>
</div>


<!-- ════ MODAL: VIEW CONTACT ════ -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="position:absolute;top:0;right:0;border:none;background:transparent;z-index:10;padding:.6rem">
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:0">
        <!-- Hero -->
        <div class="view-hero" id="viewHero">
          <div id="viewAvatarWrap"></div>
          <div class="view-name"  id="viewName">—</div>
          <div id="viewGroupTag"></div>
        </div>

        <!-- Action pills -->
        <div class="view-actions" id="viewActions"></div>

        <!-- Detail rows -->
        <div class="view-body" id="viewDetails"></div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-modal-cancel" data-bs-dismiss="modal">Taka</button>
        <button class="btn-modal-save" id="viewEditBtn" style="background:linear-gradient(135deg,var(--warn),#fb923c);" onclick="switchToEdit()">
          <i class="bi bi-pencil me-1"></i> Edita
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ════ MODAL: GROUP MANAGER ════ -->
<div class="modal fade" id="groupModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-folder2-open text-primary"></i> Jere Grupu</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="field-label">Naran Grupu Foun</label>
        <div style="display:flex;gap:.6rem;margin-bottom:1rem">
          <input class="field-input" type="text" id="inputGroupName" placeholder="ex. Familia, Servisu, Doktor…" style="margin:0;flex:1">
          <button class="btn-modal-save" style="flex-shrink:0;padding:.65rem 1rem" onclick="addGroup()"><i class="bi bi-plus-lg"></i> Aumenta</button>
        </div>
        <div class="sec-label">Grupu ezistente</div>
        <div id="groupList" style="display:flex;flex-direction:column;gap:.45rem;max-height:260px;overflow-y:auto"></div>
      </div>
    </div>
  </div>
</div>


<!-- Toast -->
<div class="toast-container">
  <div id="mainToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body fw-semibold" id="toastMsg">Feito!</div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ══ State ═══════════════════════════════════════════════════════
let allContacts = [], allGroups = [], activeGroup = '', viewingId = null;
const PHOTO_BASE = 'photos/';

const contactModal = new bootstrap.Modal(document.getElementById('contactModal'));
const viewModal    = new bootstrap.Modal(document.getElementById('viewModal'));
const groupModal   = new bootstrap.Modal(document.getElementById('groupModal'));

// ══ API ══════════════════════════════════════════════════════════
const API = async (action, method='GET', body=null) => {
  const opts = {method, headers:{'Content-Type':'application/json'}};
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(`index.php?action=${action}`, opts);
  const text = await r.text();
  try { return JSON.parse(text); }
  catch(e) {
    console.error('API [' + action + '] error:', text);
    return {ok: false, error: 'Erru servidor. Haree console (F12).'};
  }
};

async function init() { await loadGroups(); await loadContacts(); }

async function loadContacts() {
  const res = await API('contacts');
  allContacts = res.contacts || [];
  renderContacts();
}

async function loadGroups() {
  const res = await API('groups');
  allGroups = res.groups || [];
  renderGroupTabs();
  renderGroupSelect();
}

// ══ Render contacts ══════════════════════════════════════════════
function renderContacts() {
  const q = document.getElementById('searchInput').value.toLowerCase().trim();
  let list = [...allContacts];
  if (activeGroup !== '') list = list.filter(c => String(c.group_id) === String(activeGroup));
  if (q) list = list.filter(c =>
    c.name.toLowerCase().includes(q) ||
    c.phone.includes(q) ||
    (c.email || '').toLowerCase().includes(q) ||
    (c.whatsapp || '').includes(q)
  );

  document.getElementById('badgeCount').textContent = allContacts.length + ' kontaktu';
  document.getElementById('secLabel').textContent = q
    ? `${list.length} rezultadu ba "${q}"`
    : `Kontaktu hotu (${list.length})`;

  const grid = document.getElementById('contactsGrid');
  if (!list.length) {
    grid.innerHTML = `<div class="empty">
      <div class="empty-icon"><i class="bi bi-person-slash"></i></div>
      <div class="empty-title">${q || activeGroup !== '' ? 'La hetan kontaktu' : 'Seidauk iha kontaktu'}</div>
      <div class="empty-sub">${q ? 'Tenta ho lia seluk.' : 'Klik <strong>Foun</strong> atu aumenta.'}</div>
    </div>`;
    return;
  }

  grid.innerHTML = list.map((c, i) => {
    const thumb = c.photo
      ? `<div class="c-thumb"><img src="${PHOTO_BASE}${esc(c.photo)}" alt="" loading="lazy" onerror="this.parentElement.innerHTML='${inits(c.name)}'"></div>`
      : `<div class="c-thumb ${avClass(c.name)}">${inits(c.name)}</div>`;

    return `<div class="c-card" style="animation-delay:${Math.min(i*.03,.4)}s">
      ${thumb}
      <div class="c-info">
        <a class="c-name-link" href="tel:${escA(c.phone.replace(/\s/g,''))}" title="Liga ba ${esc(c.name)}">${esc(c.name)}</a>
        <div class="c-phone-row">
          <span class="c-phone">${esc(c.phone)}</span>
          ${c.whatsapp ? `<span style="color:var(--wa);font-size:.7rem"><i class="bi bi-whatsapp"></i></span>` : ''}
          ${c.email    ? `<span style="color:var(--accent);font-size:.7rem"><i class="bi bi-envelope"></i></span>` : ''}
        </div>
        ${c.group_name ? `<div class="c-group-tag"><i class="bi bi-folder me-1" style="font-size:.55rem"></i>${esc(c.group_name)}</div>` : ''}
      </div>
      <div class="c-actions">
        <button class="btn-ic btn-copy-ic" title="Kopia numeru" onclick="copyPhone('${escA(c.phone)}',this)"><i class="bi bi-clipboard"></i></button>
        <button class="btn-ic btn-view-ic"  title="View"   onclick="openViewModal(${c.id})"><i class="bi bi-eye"></i></button>
        <button class="btn-ic btn-edit-ic"  title="Edita"  onclick="openEditModal(${c.id})"><i class="bi bi-pencil"></i></button>
        <button class="btn-ic btn-del-ic"   title="Hasai"  onclick="deleteContact(${c.id},'${escA(c.name)}')"><i class="bi bi-trash3"></i></button>
      </div>
    </div>`;
  }).join('');
}

// ══ Group tabs ═══════════════════════════════════════════════════
function renderGroupTabs() {
  const all  = `<button class="gtab ${activeGroup===''?'active':''}" onclick="filterGroup('',this)"><i class="bi bi-people-fill"></i> Hotu</button>`;
  const rest = allGroups.map(g =>
    `<button class="gtab ${String(activeGroup)===String(g.id)?'active':''}" onclick="filterGroup(${g.id},this)"><i class="bi bi-folder"></i> ${esc(g.name)}</button>`
  ).join('');
  document.getElementById('groupTabs').innerHTML = all + rest;
}

function filterGroup(id, btn) {
  activeGroup = id === '' ? '' : id;
  document.querySelectorAll('.gtab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderContacts();
}

function renderGroupSelect() {
  const sel = document.getElementById('inputGroup');
  const cur = sel.value;
  sel.innerHTML = '<option value="">— La iha grupu —</option>' +
    allGroups.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('');
  sel.value = cur;
}

function renderGroupList() {
  const el = document.getElementById('groupList');
  if (!allGroups.length) {
    el.innerHTML = '<div style="color:var(--muted);font-size:.8rem;text-align:center;padding:.75rem">Seidauk iha grupu.</div>';
    return;
  }
  el.innerHTML = allGroups.map(g => `
    <div class="group-row">
      <i class="bi bi-folder" style="color:var(--accent);font-size:.85rem"></i>
      <span style="flex:1;font-size:.88rem;font-weight:600">${esc(g.name)}</span>
      <button class="btn-ic btn-del-ic" style="width:29px;height:29px;font-size:.75rem" onclick="deleteGroup(${g.id},'${escA(g.name)}')">
        <i class="bi bi-trash3"></i>
      </button>
    </div>`).join('');
}

// ══ View Contact Modal ════════════════════════════════════════════
function openViewModal(id) {
  const c = allContacts.find(x => x.id == id);
  if (!c) return;
  viewingId = id;

  // Hero avatar
  const avatarHTML = c.photo
    ? `<img class="view-avatar" src="${PHOTO_BASE}${esc(c.photo)}" alt="">`
    : `<div class="view-avatar-initials ${avClass(c.name)}">${inits(c.name)}</div>`;
  document.getElementById('viewAvatarWrap').innerHTML = avatarHTML;
  document.getElementById('viewName').textContent = c.name;
  document.getElementById('viewGroupTag').innerHTML = c.group_name
    ? `<span class="view-group"><i class="bi bi-folder me-1"></i>${esc(c.group_name)}</span>` : '';

  // Action pills
  let actions = `<button class="btn-action-pill call" onclick="window.location='tel:${escA(c.phone.replace(/\s/g,''))}'">
    <span class="icon" style="color:var(--green)"><i class="bi bi-telephone-fill"></i></span>
    <span class="label">Liga</span>
  </button>`;
  if (c.whatsapp) {
    const waNum = c.whatsapp.replace(/[\s+\-()]/g,'');
    actions += `<button class="btn-action-pill wa" onclick="window.open('https://wa.me/${waNum}','_blank')">
      <span class="icon" style="color:var(--wa)"><i class="bi bi-whatsapp"></i></span>
      <span class="label">WhatsApp</span>
    </button>`;
  }
  if (c.email) {
    actions += `<button class="btn-action-pill mail" onclick="window.location='mailto:${escA(c.email)}'">
      <span class="icon" style="color:var(--accent)"><i class="bi bi-envelope-fill"></i></span>
      <span class="label">Email</span>
    </button>`;
  }
  actions += `<button class="btn-action-pill edit" onclick="viewModal.hide();setTimeout(()=>openEditModal(${c.id}),200)">
    <span class="icon" style="color:var(--warn)"><i class="bi bi-pencil-fill"></i></span>
    <span class="label">Edita</span>
  </button>`;
  document.getElementById('viewActions').innerHTML = actions;

  // Detail rows
  let details = `<div class="view-detail-row">
    <div class="vd-icon ph"><i class="bi bi-telephone-fill"></i></div>
    <div style="flex:1">
      <div class="vd-label">Telefone</div>
      <div class="vd-value"><a href="tel:${escA(c.phone.replace(/\s/g,''))}">${esc(c.phone)}</a>
        <button class="vd-copy" onclick="copyText('${escA(c.phone)}',this)">Kopia</button>
      </div>
    </div>
  </div>`;

  if (c.whatsapp) {
    const waNum = c.whatsapp.replace(/[\s+\-()]/g,'');
    details += `<div class="view-detail-row">
      <div class="vd-icon wa"><i class="bi bi-whatsapp"></i></div>
      <div style="flex:1">
        <div class="vd-label">WhatsApp</div>
        <div class="vd-value"><a href="https://wa.me/${waNum}" target="_blank">${esc(c.whatsapp)}</a>
          <button class="vd-copy" onclick="copyText('${escA(c.whatsapp)}',this)">Kopia</button>
        </div>
      </div>
    </div>`;
  }

  if (c.email) {
    details += `<div class="view-detail-row">
      <div class="vd-icon em"><i class="bi bi-envelope-fill"></i></div>
      <div style="flex:1">
        <div class="vd-label">Email</div>
        <div class="vd-value"><a href="mailto:${escA(c.email)}">${esc(c.email)}</a>
          <button class="vd-copy" onclick="copyText('${escA(c.email)}',this)">Kopia</button>
        </div>
      </div>
    </div>`;
  }

  if (c.notes) {
    details += `<div class="view-detail-row">
      <div class="vd-icon nt"><i class="bi bi-sticky-fill"></i></div>
      <div style="flex:1">
        <div class="vd-label">Nota</div>
        <div class="vd-value" style="font-weight:400;white-space:pre-line">${esc(c.notes)}</div>
      </div>
    </div>`;
  }

  details += `<div style="padding-top:.6rem;color:var(--muted);font-size:.7rem;text-align:center">Aumentadu: ${esc(c.created_at||'—')}</div>`;
  document.getElementById('viewDetails').innerHTML = details;

  viewModal.show();
}

function switchToEdit() {
  viewModal.hide();
  setTimeout(() => openEditModal(viewingId), 220);
}

// ══ Add / Edit modals ════════════════════════════════════════════
function resetPhotoUI(photoName) {
  const area = document.getElementById('photoUploadArea');
  const img  = document.getElementById('photoPreviewImg');
  const ph   = document.getElementById('photoPlaceholder');
  const rem  = document.getElementById('btnRemovePhoto');
  document.getElementById('currentPhoto').value = photoName || '';
  if (photoName) {
    img.src = PHOTO_BASE + photoName;
    img.style.display = 'block';
    ph.style.display  = 'none';
    rem.style.display = '';
    area.classList.add('has-photo');
  } else {
    img.src = '';
    img.style.display = 'none';
    ph.style.display  = '';
    rem.style.display = 'none';
    area.classList.remove('has-photo');
  }
}

function openAddModal() {
  document.getElementById('contactModalTitle').innerHTML = '<i class="bi bi-person-plus text-primary"></i> Aumenta Kontaktu Foun';
  ['editId','inputName','inputPhone','inputWhatsapp','inputEmail','inputNotes'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('inputGroup').value = '';
  resetPhotoUI('');
  contactModal.show();
  setTimeout(() => document.getElementById('inputName').focus(), 320);
}

function openEditModal(id) {
  const c = allContacts.find(x => x.id == id);
  if (!c) return;
  document.getElementById('contactModalTitle').innerHTML = '<i class="bi bi-pencil text-warning"></i> Edita Kontaktu';
  document.getElementById('editId').value      = c.id;
  document.getElementById('inputName').value   = c.name;
  document.getElementById('inputPhone').value  = c.phone;
  document.getElementById('inputWhatsapp').value = c.whatsapp || '';
  document.getElementById('inputEmail').value  = c.email || '';
  document.getElementById('inputNotes').value  = c.notes || '';
  document.getElementById('inputGroup').value  = c.group_id || '';
  resetPhotoUI(c.photo || '');
  contactModal.show();
  setTimeout(() => document.getElementById('inputName').focus(), 320);
}

// ══ Photo handling ════════════════════════════════════════════════
async function handlePhotoFile(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  input.value = '';

  const fd = new FormData();
  fd.append('photo', file);
  try {
    const res = await fetch('index.php?action=upload_photo', {method:'POST', body:fd});
    const data = await res.json();
    if (!data.ok) { toast('⚠️ ' + data.error, 'text-bg-warning'); return; }
    resetPhotoUI(data.photo);
    toast('<i class="bi bi-image me-1"></i> Foto salva!', 'text-bg-success');
  } catch(e) {
    toast('⚠️ Sala hodi upload foto.', 'text-bg-warning');
  }
}

function removePhoto() {
  resetPhotoUI('');
}

// ══ Save contact ══════════════════════════════════════════════════
async function saveContact() {
  const id       = document.getElementById('editId').value;
  const name     = document.getElementById('inputName').value.trim();
  const phone    = document.getElementById('inputPhone').value.trim();
  const whatsapp = document.getElementById('inputWhatsapp').value.trim();
  const email    = document.getElementById('inputEmail').value.trim();
  const notes    = document.getElementById('inputNotes').value.trim();
  const photo    = document.getElementById('currentPhoto').value;
  const group_id = document.getElementById('inputGroup').value || null;

  if (!name || !phone) { toast('⚠️ Naran no numeru obrigatoriu!', 'text-bg-warning'); return; }

  const payload = {name, phone, whatsapp, email, notes, photo, group_id};
  if (id) payload.id = id;
  const res = await API(id ? 'edit_contact' : 'add_contact', 'POST', payload);
  if (!res.ok) { toast('⚠️ ' + res.error, 'text-bg-warning'); return; }

  contactModal.hide();
  await loadContacts();
  toast(id
    ? '<i class="bi bi-check-circle me-1"></i> Kontaktu atualizado!'
    : '<i class="bi bi-person-check me-1"></i> Kontaktu salva!', 'text-bg-success');
}

// ══ Delete contact ════════════════════════════════════════════════
async function deleteContact(id, name) {
  if (!confirm(`Hasai kontaktu "${name}"?`)) return;
  await API('delete_contact', 'POST', {id});
  await loadContacts();
  toast('<i class="bi bi-trash3 me-1"></i> Hasai ona.', 'text-bg-danger');
}

// ══ Groups ════════════════════════════════════════════════════════
async function addGroup() {
  const name = document.getElementById('inputGroupName').value.trim();
  if (!name) return;
  const res = await API('add_group', 'POST', {name});
  if (!res.ok) { toast(res.error, 'text-bg-warning'); return; }
  document.getElementById('inputGroupName').value = '';
  await loadGroups(); renderGroupList();
  toast('<i class="bi bi-folder-check me-1"></i> Grupu aumentadu!', 'text-bg-success');
}

async function deleteGroup(id, name) {
  if (!confirm(`Hasai grupu "${name}"?`)) return;
  await API('delete_group', 'POST', {id});
  if (String(activeGroup) === String(id)) activeGroup = '';
  await loadGroups(); await loadContacts(); renderGroupList();
  toast('<i class="bi bi-folder-x me-1"></i> Grupu hasai ona.', 'text-bg-danger');
}

function openGroupModal() {
  document.getElementById('inputGroupName').value = '';
  renderGroupList(); groupModal.show();
  setTimeout(() => document.getElementById('inputGroupName').focus(), 320);
}

// ══ Copy ══════════════════════════════════════════════════════════
async function copyPhone(phone, btn) {
  await copyText(phone, btn);
  toast('<i class="bi bi-clipboard-check me-1"></i> Numeru kopiadu!', 'text-bg-success');
}

async function copyText(text, btn) {
  try { await navigator.clipboard.writeText(text); }
  catch {
    const t = Object.assign(document.createElement('textarea'), {value:text});
    t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select();
    document.execCommand('copy'); document.body.removeChild(t);
  }
  if (btn) {
    const orig = btn.innerHTML;
    btn.classList.add('copied'); btn.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = orig; }, 2000);
  }
}

// ══ Export ════════════════════════════════════════════════════════
function exportCSV() {
  if (!allContacts.length) { toast('⚠️ Laiha kontaktu.','text-bg-warning'); return; }
  const rows = [['Naran','Telefone','WhatsApp','Email','Grupu','Nota'],
    ...allContacts.map(c=>[c.name,c.phone,c.whatsapp||'',c.email||'',c.group_name||'',c.notes||''])];
  dl('kontaktu.csv', rows.map(r=>r.map(v=>`"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n'), 'text/csv');
  toast('<i class="bi bi-filetype-csv me-1"></i> CSV ba download!', 'text-bg-success');
}

function exportTXT() {
  if (!allContacts.length) { toast('⚠️ Laiha kontaktu.','text-bg-warning'); return; }
  const txt = [...allContacts].sort((a,b)=>a.name.localeCompare(b.name)).map(c =>
    `${c.name}\n  Tel: ${c.phone}${c.whatsapp?'\n  WA:  '+c.whatsapp:''}${c.email?'\n  Email: '+c.email:''}${c.group_name?'\n  Grupu: '+c.group_name:''}${c.notes?'\n  Nota: '+c.notes:''}`
  ).join('\n\n');
  dl('kontaktu.txt', txt, 'text/plain');
  toast('<i class="bi bi-file-text me-1"></i> TXT ba download!', 'text-bg-success');
}

function dl(name, content, mime) {
  Object.assign(document.createElement('a'), {
    href: URL.createObjectURL(new Blob([content],{type:mime})), download: name
  }).click();
}

// ══ Toast ═════════════════════════════════════════════════════════
function toast(msg, cls='text-bg-success') {
  const el = document.getElementById('mainToast');
  el.className = `toast align-items-center border-0 ${cls}`;
  document.getElementById('toastMsg').innerHTML = msg;
  bootstrap.Toast.getOrCreateInstance(el, {delay:2500}).show();
}

// ══ Helpers ═══════════════════════════════════════════════════════
function inits(n) { return n.trim().split(/\s+/).map(w=>w[0]).slice(0,2).join('').toUpperCase(); }
function avClass(n) { let h=0; for (let c of n) h=(h*31+c.charCodeAt(0))&0xffff; return 'av'+(h%10); }
function esc(s)  { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escA(s) { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;'); }

// ══ Keyboard ══════════════════════════════════════════════════════
document.getElementById('inputPhone').addEventListener('keydown', e => { if(e.key==='Enter') document.getElementById('inputWhatsapp').focus(); });
document.getElementById('inputWhatsapp').addEventListener('keydown', e => { if(e.key==='Enter') document.getElementById('inputEmail').focus(); });
document.getElementById('inputEmail').addEventListener('keydown', e => { if(e.key==='Enter') saveContact(); });
document.getElementById('inputGroupName').addEventListener('keydown', e => { if(e.key==='Enter') addGroup(); });

// ══ Init ══════════════════════════════════════════════════════════
init();
</script>
</body>
</html>