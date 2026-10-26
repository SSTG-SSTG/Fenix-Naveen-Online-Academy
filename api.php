<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$EXAMS = ['TNPSC Group 1','TNPSC Group 2','TNPSC Group 3','TNPSC Group 4','TNUSRB'];
$FILE = __DIR__ . '/data/questions.xml';
$LOCK = __DIR__ . '/data/.lock';
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function ulen($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('/./us', $s); }
function body() { $d = json_decode(file_get_contents('php://input'), true); return is_array($d) ? $d : []; }
function load($f) {
  if (!is_file($f)) return [];
  libxml_use_internal_errors(true);
  $x = simplexml_load_file($f); if (!$x) return [];
  $l = [];
  foreach ($x->q as $q) {
    $o = []; foreach ($q->o as $v) $o[] = (string)$v;
    $l[] = ['id'=>(int)$q['id'], 'exam'=>(string)$q['exam'], 'answer'=>(int)$q['answer'], 'text'=>(string)$q->text, 'options'=>$o];
  }
  return $l;
}
function save($f, $l) {
  $x = new SimpleXMLElement('<questions/>');
  foreach ($l as $q) {
    $n = $x->addChild('q');
    $n->addAttribute('id', $q['id']); $n->addAttribute('exam', $q['exam']); $n->addAttribute('answer', $q['answer']);
    $n->addChild('text', htmlspecialchars($q['text'], ENT_XML1));
    foreach ($q['options'] as $o) $n->addChild('o', htmlspecialchars($o, ENT_XML1));
  }
  $dom = dom_import_simplexml($x)->ownerDocument; $dom->encoding = 'UTF-8'; $dom->formatOutput = true;
  file_put_contents($f, $dom->saveXML(), LOCK_EX);
}
function locked($lock, $fn) { $h = fopen($lock, 'c'); flock($h, LOCK_EX); $r = $fn(); flock($h, LOCK_UN); fclose($h); return $r; }
function sign($s) { return hash_hmac('sha256', $s, SESSION_SECRET); }
function authed() {
  $p = explode('.', $_COOKIE['adm'] ?? '');
  return count($p) === 2 && (int)$p[0] > time() && hash_equals(sign($p[0]), $p[1]);
}
function need_auth() { if (!authed()) out(['error'=>'Login required'], 401); }

$r = $_GET['r'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) out(['error'=>'Bad request'], 400);

if ($r === 'questions') out(load($FILE));

if ($r === 'daily') { // a random question on every request
  $l = load($FILE); if (!$l) out(['error'=>'No questions'], 404);
  out($l[random_int(0, count($l) - 1)]);
}

if ($r === 'login') {
  if (ADMIN_PASSWORD === 'CHANGE-THIS-PASSWORD' || SESSION_SECRET === 'CHANGE-THIS-TO-LONG-RANDOM-TEXT') out(['error'=>'Set password in config.php first'], 500);
  $af = __DIR__ . '/data/attempts.json'; $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
  $res = locked($LOCK, function () use ($af, $ip) {
    $a = is_file($af) ? (json_decode(file_get_contents($af), true) ?: []) : [];
    foreach ($a as $k => $v) if (time() - $v[1] > 900) unset($a[$k]);
    if (($a[$ip][0] ?? 0) >= 5) { file_put_contents($af, json_encode($a)); return 'blocked'; }
    $pw = body()['password'] ?? '';
    if (is_string($pw) && hash_equals(hash('sha256', ADMIN_PASSWORD), hash('sha256', $pw))) { unset($a[$ip]); file_put_contents($af, json_encode($a)); return 'ok'; }
    $a[$ip] = [($a[$ip][0] ?? 0) + 1, $a[$ip][1] ?? time()]; file_put_contents($af, json_encode($a)); return 'bad';
  });
  if ($res === 'blocked') out(['error'=>'Too many attempts. Try after 15 minutes.'], 429);
  if ($res === 'bad') out(['error'=>'Wrong password'], 401);
  $e = time() + 43200;
  setcookie('adm', $e . '.' . sign((string)$e), ['expires'=>$e, 'path'=>'/', 'httponly'=>true, 'samesite'=>'Strict', 'secure'=>!empty($_SERVER['HTTPS'])]);
  out(['ok'=>true]);
}
if ($r === 'logout') { setcookie('adm', '', ['expires'=>time()-3600, 'path'=>'/', 'httponly'=>true]); out(['ok'=>true]); }
if ($r === 'me') { need_auth(); out(['ok'=>true, 'exams'=>$EXAMS]); }

if ($r === 'add') {
  need_auth(); $b = body(); $a = $b['answer'] ?? null; $o = $b['options'] ?? null; $t = $b['text'] ?? null; $ex = $b['exam'] ?? null;
  $bad = !in_array($ex, $EXAMS, true) || !is_string($t) || trim($t) === '' || ulen($t) > 500 || !is_array($o) || count($o) !== 4
      || !is_numeric($a) || (int)$a < 0 || (int)$a > 3;
  if (!$bad) foreach ($o as $v) if (!is_string($v) || trim($v) === '' || ulen($v) > 200) $bad = true;
  if ($bad) out(['error'=>'Fill the exam, question, 4 options and the correct answer.'], 400);
  $id = locked($LOCK, function () use ($FILE, $ex, $t, $o, $a) {
    $l = load($FILE); $id = 1; foreach ($l as $q) $id = max($id, $q['id'] + 1);
    $l[] = ['id'=>$id, 'exam'=>$ex, 'answer'=>(int)$a, 'text'=>trim($t), 'options'=>array_map('trim', array_values($o))];
    save($FILE, $l); return $id;
  });
  out(['ok'=>true, 'id'=>$id]);
}
if ($r === 'delete') {
  need_auth(); $id = (int)($_GET['id'] ?? 0);
  $found = locked($LOCK, function () use ($FILE, $id) {
    $l = load($FILE); $n = array_values(array_filter($l, function ($q) use ($id) { return $q['id'] !== $id; }));
    if (count($n) === count($l)) return false; save($FILE, $n); return true;
  });
  $found ? out(['ok'=>true]) : out(['error'=>'Not found'], 404);
}
// ---------- Store items (courses / books) ----------
$SFILE = __DIR__ . '/data/store.xml';
function sload($f) {
  if (!is_file($f)) return [];
  libxml_use_internal_errors(true);
  $x = simplexml_load_file($f); if (!$x) return [];
  $l = [];
  foreach ($x->item as $i) $l[] = ['id'=>(int)$i['id'], 'type'=>(string)$i['type'], 'access'=>(string)$i['access'], 'price'=>(int)$i['price'], 'exam'=>(string)$i['exam'],
    'title'=>(string)$i->title, 'desc'=>(string)$i->desc, 'link'=>(string)$i->link, 'image'=>(string)$i->image];
  return $l;
}
function ssave($f, $l) {
  $x = new SimpleXMLElement('<items/>');
  foreach ($l as $i) {
    $n = $x->addChild('item');
    foreach (['id','type','access','price','exam'] as $k) $n->addAttribute($k, $i[$k]);
    foreach (['title','desc','link','image'] as $k) $n->addChild($k, htmlspecialchars($i[$k], ENT_XML1));
  }
  $dom = dom_import_simplexml($x)->ownerDocument; $dom->encoding = 'UTF-8'; $dom->formatOutput = true;
  file_put_contents($f, $dom->saveXML(), LOCK_EX);
}
function okurl($u) { return $u === '' || (strlen($u) <= 500 && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)); }

if ($r === 'store') out(sload($SFILE));

if ($r === 'item_save') {
  need_auth(); $b = body();
  $g = function ($k) use ($b) { return isset($b[$k]) && is_string($b[$k]) ? trim($b[$k]) : ''; };
  $type = $g('type'); $acc = $g('access'); $exam = $g('exam'); $title = $g('title'); $desc = $g('desc'); $link = $g('link'); $img = $g('image');
  $price = isset($b['price']) && is_numeric($b['price']) ? (int)$b['price'] : 0; $id = (int)($b['id'] ?? 0);
  $bad = !in_array($type, ['course','book'], true) || !in_array($acc, ['free','paid'], true) || !in_array($exam, array_merge($EXAMS, ['General']), true)
      || $title === '' || ulen($title) > 120 || ulen($desc) > 600 || !okurl($link) || !okurl($img)
      || ($acc === 'paid' && ($price < 1 || $price > 100000)) || ($acc === 'free' && $link === '');
  if ($bad) out(['error'=>'Check the fields: title, valid http(s) links, price for paid items, and a link for free items.'], 400);
  if ($acc === 'free') $price = 0;
  $res = locked($LOCK, function () use ($SFILE, $id, $type, $acc, $price, $exam, $title, $desc, $link, $img) {
    $l = sload($SFILE); $item = ['id'=>$id, 'type'=>$type, 'access'=>$acc, 'price'=>$price, 'exam'=>$exam, 'title'=>$title, 'desc'=>$desc, 'link'=>$link, 'image'=>$img];
    if ($id) { $f = false; foreach ($l as $k => $q) if ($q['id'] === $id) { $l[$k] = $item; $f = true; } if (!$f) return 0; }
    else { $id = 1; foreach ($l as $q) $id = max($id, $q['id'] + 1); $item['id'] = $id; $l[] = $item; }
    ssave($SFILE, $l); return $id;
  });
  $res ? out(['ok'=>true, 'id'=>$res]) : out(['error'=>'Not found'], 404);
}
if ($r === 'item_delete') {
  need_auth(); $id = (int)($_GET['id'] ?? 0);
  $found = locked($LOCK, function () use ($SFILE, $id) {
    $l = sload($SFILE); $n = array_values(array_filter($l, function ($q) use ($id) { return $q['id'] !== $id; }));
    if (count($n) === count($l)) return false; ssave($SFILE, $n); return true;
  });
  $found ? out(['ok'=>true]) : out(['error'=>'Not found'], 404);
}

out(['error'=>'Not found'], 404);
