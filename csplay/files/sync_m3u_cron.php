<?php
// /wwwdir/sync_m3u_cron.php
// Sincroniza periodicamente uma playlist M3U/M3U8 baseada em configurações salvas
// Lê m3u_sync_settings.json e aplica mudanças no banco (adiciona novos, atualiza categorias)

ini_set('display_errors', 0);
header('Content-Type: text/plain; charset=utf-8');

$CONFIG_FILE = '/home/xtreamcodes/iptv_xtream_codes/config';
$SETTINGS_FILE = '/home/xtreamcodes/iptv_xtream_codes/wwwdir/m3u_sync_settings.json';
$LOG_FILE = '/home/xtreamcodes/logs/sync_m3u.log';
$key = '5709650b0d7806074842c6de575025b1';
@mkdir('/home/xtreamcodes/logs', 0777, true);

function log_line($msg){
  global $LOG_FILE;
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($LOG_FILE, "[$ts] $msg\n", FILE_APPEND);
}

function xor_decode($data, $key){
  $raw = base64_decode($data);
  $out = '';
  for($i=0;$i<strlen($raw);$i++){
    $out .= chr(ord($raw[$i]) ^ ord($key[$i % strlen($key)]));
  }
  return $out;
}

function get_db(){
  global $CONFIG_FILE, $key;
  if (!file_exists($CONFIG_FILE)) die('Config não encontrado.');
  $enc = trim(file_get_contents($CONFIG_FILE));
  $json = json_decode(xor_decode($enc, $key), true);
  $mysqli = new mysqli($json['host'], $json['db_user'], $json['db_pass'], $json['db_name'], intval($json['db_port']));
  if ($mysqli->connect_errno){ die('Erro DB: '.$mysqli->connect_error); }
  $mysqli->set_charset('utf8');
  return $mysqli;
}

function fetch_text($url){
  $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: XtreamUI-Sync\r\n"]]);
  $txt = @file_get_contents($url, false, $ctx);
  if ($txt === false) return '';
  return $txt;
}

function parse_m3u($text){
  $lines = preg_split('/\r?\n/', $text);
  $items = [];
  $current = null;
  foreach($lines as $line){
    $line = trim($line);
    if ($line === '' || $line[0] === '#'){
      if (stripos($line, '#EXTINF:') === 0){
        $current = ['meta' => $line, 'url' => null];
      }
      continue;
    }
    if ($current){
      $current['url'] = $line;
      $items[] = $current;
      $current = null;
    }
  }
  return $items;
}

function extinf_meta($meta){
  $out = ['name' => 'Sem Nome', 'group' => 'Sem Categoria'];
  if (preg_match('/group-title\s*=\s*"([^"]+)"/i', $meta, $m)){
    $out['group'] = $m[1];
  }
  if (preg_match('/#EXTINF:[^,]*,(.*)$/', $meta, $m)){
    $out['name'] = trim($m[1]);
  }
  return $out;
}

function ensure_category($db, $name){
  $stmt = $db->prepare('SELECT id FROM categories WHERE category_name = ? LIMIT 1');
  $stmt->bind_param('s', $name);
  $stmt->execute();
  $stmt->bind_result($id);
  if ($stmt->fetch()){ $stmt->close(); return intval($id); }
  $stmt->close();
  $stmt = $db->prepare('INSERT INTO categories (category_name) VALUES (?)');
  $stmt->bind_param('s', $name);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return intval($id);
}

function upsert_channel($db, $name, $url, $category_id){
  // tenta encontrar pelo nome
  $stmt = $db->prepare('SELECT id FROM streams WHERE stream_display_name = ? LIMIT 1');
  $stmt->bind_param('s', $name);
  $stmt->execute();
  $stmt->bind_result($sid);
  if ($stmt->fetch()){
    $stmt->close();
    // atualiza fonte e categoria
    $src = json_encode([$url]);
    $stmt = $db->prepare('UPDATE streams SET stream_source = ?, category_id = ? WHERE id = ?');
    $stmt->bind_param('sii', $src, $category_id, $sid);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
  $stmt->close();
  $src = json_encode([$url]);
  $stmt = $db->prepare('INSERT INTO streams (stream_display_name, type, stream_source, category_id, added, status) VALUES (?, "live", ?, ?, UNIX_TIMESTAMP(), 1)');
  $stmt->bind_param('ssii', $name, $src, $category_id, $dummy);
  $dummy = 1;
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function remove_missing_streams($db, $names){
  // remove streams cujo nome não está na lista atual
  $in = implode(',', array_map(function($n){ return "'".addslashes($n)."'"; }, $names));
  if ($in === '') return;
  $sql = "DELETE FROM streams WHERE stream_display_name NOT IN ($in)";
  $db->query($sql);
}

function auto_organize($db){
  // Exemplo simples: ordena categorias alfabeticamente (se UI usa ORDER BY category_name) e garante nomes únicos
  $db->query("UPDATE categories SET category_name = TRIM(category_name)");
  // Remover categorias vazias opcionalmente (exemplo; comente se não deseja)
  // $db->query("DELETE c FROM categories c LEFT JOIN streams s ON s.category_id = c.id WHERE s.id IS NULL");
}

if (!file_exists($SETTINGS_FILE)){
  log_line('Settings não encontrados.');
  exit(0);
}
$set = json_decode(file_get_contents($SETTINGS_FILE), true);
if (!$set || empty($set['enabled']) || empty($set['url'])){
  log_line('Sincronização desativada ou URL vazio.');
  exit(0);
}
$url = $set['url'];
$remove_missing = !empty($set['remove_missing']);
$do_organize = !empty($set['auto_organize']);

log_line('Sync iniciado: '.$url);
$text = fetch_text($url);
if ($text === ''){
  log_line('Falha ao baixar playlist.');
  exit(1);
}
$items = parse_m3u($text);
$db = get_db();
$db->begin_transaction();
try{
  $names = [];
  foreach($items as $it){
    if (empty($it['url'])) continue;
    $meta = extinf_meta($it['meta']);
    $names[] = $meta['name'];
    $cat_id = ensure_category($db, $meta['group']);
    upsert_channel($db, $meta['name'], $it['url'], $cat_id);
  }
  if ($remove_missing) remove_missing_streams($db, $names);
  if ($do_organize) auto_organize($db);
  $db->commit();
  log_line('Sync OK: itens='.count($items));
} catch (Exception $e){
  $db->rollback();
  log_line('Erro: '.$e->getMessage());
  echo "Erro: ".$e->getMessage();
  exit(1);
}

echo "Sincronização concluída.";
