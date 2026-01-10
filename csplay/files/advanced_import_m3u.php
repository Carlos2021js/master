<?php
// /wwwdir/advanced_import_m3u.php
// Importador de playlists via link M3U/M3U8 para Xtream UI
// Este script cria categorias e canais a partir de um link fornecido
// Uso: acessar pelo painel (menu Avançado → Importar via link)

ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

// Config: caminho do config encriptado e DB
$CONFIG_FILE = '/home/xtreamcodes/iptv_xtream_codes/config';
$key = '5709650b0d7806074842c6de575025b1';

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
  $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: XtreamUI-Importer\r\n"]]);
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
        // Ex: #EXTINF:-1 tvg-id="..." tvg-name="..." group-title="Categoria", Nome do Canal
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
  // group-title="..."
  if (preg_match('/group-title\s*=\s*"([^"]+)"/i', $meta, $m)){
    $out['group'] = $m[1];
  }
  // Nome do canal após a vírgula
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

function create_channel($db, $name, $url, $category_id){
  // Tabela e campos podem variar entre forks; usando um padrão comum
  $stmt = $db->prepare('INSERT INTO streams (stream_display_name, type, stream_source, category_id, added, status) VALUES (?, "live", ?, ?, UNIX_TIMESTAMP(), 1)');
  $src = json_encode([$url]);
  $stmt->bind_param('ssii', $name, $src, $category_id, $status);
}

function create_channel_safe($db, $name, $url, $category_id){
  // Evitar duplicatas pelo nome + url
  $stmt = $db->prepare('SELECT id FROM streams WHERE stream_display_name = ? LIMIT 1');
  $stmt->bind_param('s', $name);
  $stmt->execute();
  $stmt->bind_result($sid);
  if ($stmt->fetch()){ $stmt->close(); return false; }
  $stmt->close();
  $src = json_encode([$url]);
  $stmt = $db->prepare('INSERT INTO streams (stream_display_name, type, stream_source, category_id, added, status) VALUES (?, "live", ?, ?, UNIX_TIMESTAMP(), 1)');
  $stmt->bind_param('ssii', $name, $src, $category_id, $dummy);
  $dummy = 1;
  if (!$stmt->execute()){
    error_log('Falha ao inserir stream: '.$stmt->error);
    $stmt->close();
    return false;
  }
  $stmt->close();
  return true;
}

$err = '';
$ok = false;
$imported = 0;
$skipped = 0;
$log_file = '/home/xtreamcodes/logs/import_m3u.log';
@mkdir('/home/xtreamcodes/logs', 0777, true);
function log_line($msg){
  global $log_file;
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($log_file, "[$ts] $msg\n", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
  $link = isset($_POST['m3u_link']) ? trim($_POST['m3u_link']) : '';
  if ($link === '' || !preg_match('/^https?:\/\//i', $link)){
    $err = 'Informe um link M3U/M3U8 válido (http/https).';
    log_line('Link inválido: '.$link);
  } else {
    log_line('Início import: '.$link);
    $text = fetch_text($link);
    if ($text === ''){
      $err = 'Não foi possível baixar o link. Verifique URL/acesso.';
    } else {
      // salvar settings de sync
      $sync_enabled = !empty($_POST['sync_enabled']);
      $interval_minutes = isset($_POST['interval_minutes']) ? max(5, intval($_POST['interval_minutes'])) : 30;
      $remove_missing = !empty($_POST['remove_missing']);
      $auto_organize = !empty($_POST['auto_organize']);
      $settings = [
        'enabled' => $sync_enabled,
        'url' => $link,
        'interval_minutes' => $interval_minutes,
        'remove_missing' => $remove_missing,
        'auto_organize' => $auto_organize
      ];
      @file_put_contents('/home/xtreamcodes/iptv_xtream_codes/wwwdir/m3u_sync_settings.json', json_encode($settings, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

      $items = parse_m3u($text);
      $db = get_db();
      // Checar existência de tabelas essenciais
      $check = $db->query("SHOW TABLES LIKE 'streams'");
      if ($check->num_rows === 0){ $err = 'Tabela streams não encontrada.'; log_line($err); }
      $check = $db->query("SHOW TABLES LIKE 'categories'");
      if ($err === '' && $check->num_rows === 0){ $err = 'Tabela categories não encontrada.'; log_line($err); }
      if ($err !== ''){ $ok = false; }
      else {
      $db->begin_transaction();
      try{
        foreach($items as $it){
          if (!isset($it['url']) || $it['url'] === ''){ $skipped++; continue; }
          $meta = extinf_meta($it['meta']);
          $cat_id = ensure_category($db, $meta['group']);
          if (create_channel_safe($db, $meta['name'], $it['url'], $cat_id)) $imported++; else $skipped++;
        }
        $db->commit();
        $ok = true;
        log_line('Sucesso: inseridos='.$imported.' ignorados='.$skipped);
      } catch (Exception $e){
        $db->rollback();
        $err = 'Erro na importação: '.$e->getMessage();
        log_line('Erro: '.$err);
      }
    }
  }
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Importar via Link M3U/M3U8 (Avançado)</title>
  <style>
    body{font-family:Arial, sans-serif; margin:20px;}
    .box{border:1px solid #ddd; border-radius:8px; padding:12px; max-width:720px;}
    label{display:block; margin:8px 0;}
    input[type=text]{width:100%; padding:8px;}
    .msg{margin-top:12px;}
    .ok{color:#0a7;}
    .err{color:#c00;}
  </style>
</head>
<body>
  <h1>Avançado → Importar via Link M3U/M3U8</h1>
  <p><a href="/advanced_import_m3u.php">Menu: Avançado → Importar via link</a></p>
  <div class="box">
    <form method="post">
      <label>Link da playlist (M3U/M3U8):
        <input type="text" name="m3u_link" placeholder="https://exemplo.com/playlist.m3u8" />
      </label>
      <button type="submit">Importar e Organizar</button>
    </form>
    <div class="msg">
      <?php if($ok){ echo '<p class="ok">Importação concluída. Inseridos: '.intval($imported).' | Ignorados: '.intval($skipped).'</p>'; } ?>
      <?php if($err){ echo '<p class="err">'.htmlspecialchars($err).'</p>'; } ?>
    </div>
    <p>Observação: categorias são criadas a partir de <code>group-title</code> do EXTINF. Itens sem categoria vão para "Sem Categoria".</p>
  </div>
</body>
</html>
