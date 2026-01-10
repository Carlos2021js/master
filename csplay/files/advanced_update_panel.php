<?php
// /wwwdir/advanced_update_panel.php
// Atualiza o painel a partir de um link do Dropbox (zip da pasta). Baixa, valida e aplica.
// Uso: Avançado → Atualizar painel

ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

$LOG_FILE = '/home/xtreamcodes/logs/update_panel.log';
@mkdir('/home/xtreamcodes/logs', 0777, true);
function log_line($msg){
  global $LOG_FILE;
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($LOG_FILE, "[$ts] $msg\n", FILE_APPEND);
}

function fetch_file($url, $dest){
  $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: XtreamUI-Panel-Update\r\n"]]);
  $data = @file_get_contents($url, false, $ctx);
  if ($data === false) return false;
  return @file_put_contents($dest, $data) !== false;
}

function download_dropbox_zip($url, $dest){
  // Tenta forçar download: se contiver dl=0, troca para dl=1
  if (strpos($url, 'dl=0') !== false){
    $url = str_replace('dl=0', 'dl=1', $url);
  }
  return fetch_file($url, $dest);
}

function validate_zip($path){
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return false;
  // procura arquivos esperados do painel (admin/ ou wwwdir/)
  $found_admin = false;
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'];
    if (stripos($name, 'admin/') !== false || stripos($name, 'wwwdir/') !== false){
      $found_admin = true; break;
    }
  }
  $zip->close();
  return $found_admin;
}

function extract_zip_to($zip_path, $to_dir){
  $zip = new ZipArchive();
  if ($zip->open($zip_path) !== true) return false;
  $ok = $zip->extractTo($to_dir);
  $zip->close();
  return $ok;
}

function apply_update($src_dir){
  // Copia conteúdo extraído para iptv_xtream_codes
  $target = '/home/xtreamcodes/iptv_xtream_codes';
  // Segurança: não apaga tudo; apenas substitui admin e wwwdir se existirem
  $paths = ['admin', 'wwwdir', 'pytools'];
  foreach($paths as $p){
    $from = rtrim($src_dir, '/') . '/' . $p;
    $to = $target . '/' . $p;
    if (is_dir($from)){
      // backup simples
      @mkdir($target . '/.backup', 0777, true);
      @system('cp -rf '.escapeshellarg($to).' '.escapeshellarg($target.'/.backup/'. $p .'_'.date('YmdHis')));
      // copia novo conteúdo
      @system('cp -rf '.escapeshellarg($from).'/* '.escapeshellarg($to).'/');
    }
  }
  // permissões e owner
  @system('chown -R xtreamcodes:xtreamcodes /home/xtreamcodes');
  @system('chmod -R 0777 /home/xtreamcodes');
  return true;
}

$err = '';
$ok = false;
$log = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
  $link = isset($_POST['dropbox_link']) ? trim($_POST['dropbox_link']) : '';
  if ($link === ''){
    $err = 'Informe o link do Dropbox.';
  } else {
    log_line('Solicitado update via: '.$link);
    $tmp_zip = '/tmp/panel_update.zip';
    if (!download_dropbox_zip($link, $tmp_zip)){
      $err = 'Falha ao baixar ZIP do Dropbox.';
      log_line($err);
    } else if (!validate_zip($tmp_zip)){
      $err = 'ZIP inválido ou sem estrutura esperada (admin/wwwdir).';
      log_line($err);
    } else {
      $tmp_dir = '/tmp/panel_update';
      @system('rm -rf '.escapeshellarg($tmp_dir));
      @mkdir($tmp_dir, 0777, true);
      if (!extract_zip_to($tmp_zip, $tmp_dir)){
        $err = 'Falha ao extrair ZIP.';
        log_line($err);
      } else {
        if (apply_update($tmp_dir)){
          $ok = true;
          $log = 'Atualização aplicada com sucesso.';
          log_line($log);
          // Reinicia serviços do painel
          @system('sudo /home/xtreamcodes/iptv_xtream_codes/start_services.sh');
        } else {
          $err = 'Falha ao aplicar atualização.';
          log_line($err);
        }
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
  <title>Avançado → Atualizar Painel (Dropbox)</title>
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
  <h1>Avançado → Atualizar Painel via Dropbox</h1>
  <p>Use seu link compartilhado do Dropbox (sugestão: com <code>dl=1</code> para baixar direto).</p>
  <div class="box">
    <form method="post">
      <label>Link do Dropbox (ZIP da pasta do painel):
        <input type="text" name="dropbox_link" placeholder="cole aqui seu link do Dropbox" />
      </label>
      <button type="submit">Baixar, Validar e Atualizar</button>
    </form>
    <div class="msg">
      <?php if($ok){ echo '<p class="ok">'.$log.'</p>'; } ?>
      <?php if($err){ echo '<p class="err">'.htmlspecialchars($err).'</p>'; } ?>
    </div>
    <p>Logs: <code>/home/xtreamcodes/logs/update_panel.log</code></p>
  </div>
</body>
</html>
