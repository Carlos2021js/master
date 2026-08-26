<?php
/**
 * CS PLAYER - Atualizador remoto resiliente por manifesto JSON (PHP 7.4+)
 * Dropbox principal, GitHub reserva. Não altera o banco de dados.
 */
include __DIR__ . '/session.php';
include __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($rPermissions) || empty($rPermissions['is_admin'])) {
    http_response_code(403);
    echo json_encode(array('ok'=>false,'message'=>'Acesso restrito ao administrador.'));
    exit;
}
if (!hasPermissions('adv', 'settings') && !hasPermissions('adv', 'database')) {
    http_response_code(403);
    echo json_encode(array('ok'=>false,'message'=>'Sem permissão para atualizar o CS PLAYER.'));
    exit;
}

const CS_PLAYER_UPDATE_MANIFEST = 'https://www.dropbox.com/scl/fi/4scts71fqjhffh7310pta/update.json?rlkey=7wherup4r2sudy1lgsoqqqrj4&st=dpgrn4li&dl=1';
const CS_PLAYER_UPDATE_MANIFEST_FALLBACK = 'https://raw.githubusercontent.com/Carlos2021js/master/main/update.json';
const CS_PLAYER_ROOT = '/home/xtreamcodes/iptv_xtream_codes';
const CS_PLAYER_UPDATER_UA = 'CS-PLAYER-Updater/5.2.0-unlimited';

/**
 * O atualizador pode copiar milhares de arquivos e validar o pacote inteiro.
 * Não existe limite artificial de execução no PHP; os limites de rede continuam
 * controlados para evitar conexões presas indefinidamente.
 */
function csu_unlimited_runtime() {
    if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('max_input_time', '0');
    @ini_set('default_socket_timeout', '0');
}
csu_unlimited_runtime();

function csu_json($data, $status) {
    http_response_code((int)$status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function csu_allowed_url($url) {
    if (!is_string($url) || stripos($url, 'https://') !== 0 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return in_array($host, array('www.dropbox.com','dropbox.com','dl.dropboxusercontent.com','raw.githubusercontent.com','github.com'), true);
}
function csu_disabled_functions() {
    $raw = trim((string)ini_get('disable_functions'));
    return $raw === '' ? array() : array_map('trim', explode(',', $raw));
}
function csu_function_enabled($name) {
    return function_exists($name) && !in_array($name, csu_disabled_functions(), true);
}
function csu_run_command($command, &$output, &$code) {
    $output = array(); $code = 127;
    if (csu_function_enabled('exec')) {
        exec($command . ' 2>&1', $output, $code);
        return true;
    }
    if (csu_function_enabled('proc_open')) {
        $desc = array(1=>array('pipe','w'), 2=>array('pipe','w'));
        $pipes = array();
        $proc = @proc_open($command, $desc, $pipes);
        if (is_resource($proc)) {
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code = proc_close($proc);
            $text = trim((string)$stdout . "\n" . (string)$stderr);
            $output = $text === '' ? array() : preg_split('/\r?\n/', $text);
            return true;
        }
    }
    return false;
}
function csu_unzip_binary() {
    foreach (array('/usr/bin/unzip','/bin/unzip') as $bin) {
        if (is_file($bin) && is_executable($bin)) return $bin;
    }
    return '';
}
function csu_extractor_capabilities() {
    $bin = csu_unzip_binary();
    $cmd = csu_function_enabled('exec') || csu_function_enabled('proc_open');
    return array(
        'ziparchive'=>class_exists('ZipArchive'),
        'unzip'=>($bin !== '' && $cmd),
        'unzip_path'=>$bin,
        'command_runner'=>$cmd,
    );
}
function csu_fetch_string($url, $maxBytes) {
    if (!csu_allowed_url($url)) return false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_USERAGENT, CS_PLAYER_UPDATER_UA);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($raw) || strlen($raw) > $maxBytes) return false;
        return $raw;
    }
    $ctx = stream_context_create(array('http'=>array('timeout'=>25,'follow_location'=>1,'max_redirects'=>5,'user_agent'=>CS_PLAYER_UPDATER_UA)));
    $raw = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maxBytes) return false;
    return $raw;
}
function csu_state_paths() {
    return array(CS_PLAYER_ROOT.'/admin/logs/cs-player/update-state.json', CS_PLAYER_ROOT.'/CS_PLAYER_UPDATE_VERSION.json');
}
function csu_local_state() {
    $defaults = array('product'=>'CS PLAYER','version'=>'3.0.3','build'=>303,'channel'=>'stable');
    foreach (csu_state_paths() as $path) {
        if (!is_file($path)) continue;
        $raw=@file_get_contents($path); $data=is_string($raw)?json_decode($raw,true):null;
        if (is_array($data) && !empty($data['version'])) return array_merge($defaults,$data);
    }
    return $defaults;
}
function csu_manifest() {
    $sources=array(CS_PLAYER_UPDATE_MANIFEST,CS_PLAYER_UPDATE_MANIFEST_FALLBACK);
    foreach ($sources as $source) {
        $raw=csu_fetch_string($source,1048576); if ($raw===false) continue;
        $m=json_decode($raw,true); if (!is_array($m)||empty($m['version'])) continue;
        $url=''; $sha=''; $size=0;
        if (isset($m['download'])&&is_array($m['download'])) {
            $url=trim((string)($m['download']['url']??'')); $sha=strtolower(trim((string)($m['download']['sha256']??''))); $size=(int)($m['download']['size']??0);
        }
        if ($url===''&&isset($m['download_url'])) $url=trim((string)$m['download_url']);
        if ($sha===''&&isset($m['sha256'])) $sha=strtolower(trim((string)$m['sha256']));
        if ($size<=0&&isset($m['size'])) $size=(int)$m['size'];
        if (!csu_allowed_url($url)) continue;
        if ($sha!==''&&!preg_match('/^[a-f0-9]{64}$/',$sha)) return array(false,'SHA-256 do manifesto é inválido.');
        if ($size<0) return array(false,'Tamanho do pacote no manifesto é inválido.');
        $m['_manifest_url']=$source; $m['_download_url']=$url; $m['_sha256']=$sha; $m['_size']=$size;
        return array($m,null);
    }
    return array(false,'Não foi possível consultar um update.json válido no Dropbox nem no canal reserva GitHub.');
}
function csu_is_newer($remote,$local,$remoteBuild,$localBuild) {
    if ($remoteBuild>0&&$localBuild>0&&$remoteBuild!==$localBuild) return $remoteBuild>$localBuild;
    return version_compare((string)$remote,(string)$local,'>');
}
function csu_download_file($url,$target) {
    if (!csu_allowed_url($url)) return false;
    $fp=@fopen($target,'wb'); if(!$fp) return false; $ok=false;
    if (function_exists('curl_init')) {
        $ch=curl_init($url); curl_setopt($ch,CURLOPT_FILE,$fp); curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true); curl_setopt($ch,CURLOPT_MAXREDIRS,5);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,15); curl_setopt($ch,CURLOPT_TIMEOUT,600); curl_setopt($ch,CURLOPT_USERAGENT,CS_PLAYER_UPDATER_UA);
        if (defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS')) curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS')&&defined('CURLPROTO_HTTPS')) curl_setopt($ch,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTPS);
        $exec=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $ok=($exec===true&&$code>=200&&$code<300); curl_close($ch);
    } else {
        fclose($fp); $fp=null;
        $ctx=stream_context_create(array('http'=>array('timeout'=>600,'follow_location'=>1,'max_redirects'=>5,'user_agent'=>CS_PLAYER_UPDATER_UA)));
        $in=@fopen($url,'rb',false,$ctx); $fp=@fopen($target,'wb');
        if($in&&$fp){$ok=(stream_copy_to_stream($in,$fp)!==false);fclose($in);}
    }
    if(is_resource($fp))fclose($fp);
    return $ok&&is_file($target)&&filesize($target)>1024;
}
function csu_zip_safe($zip) {
    for($i=0;$i<$zip->numFiles;$i++){
        $name=(string)$zip->getNameIndex($i);
        if($name===''||strpos($name,"\0")!==false||$name[0]==='/'||preg_match('#(^|/)\.\.(/|$)#',$name))return false;
    }
    return true;
}
function csu_safe_archive_names($names) {
    if(!is_array($names)||!$names)return false;
    foreach($names as $name){$name=(string)$name;if($name===''||strpos($name,"\0")!==false||$name[0]==='/'||preg_match('#(^|/)\.\.(/|$)#',$name))return false;}
    return true;
}
function csu_extract_package($zipPath,$extract,&$engine,&$detail) {
    $engine='';$detail='';
    if(class_exists('ZipArchive')){
        $zip=new ZipArchive(); $opened=$zip->open($zipPath);
        if($opened===true&&csu_zip_safe($zip)&&$zip->extractTo($extract)){ $zip->close();$engine='ZipArchive';return true; }
        if($opened===true)@$zip->close(); $detail='ZipArchive não conseguiu extrair o pacote.';
    }
    $bin=csu_unzip_binary();
    if($bin!==''){
        $out=array();$code=0;
        if(csu_run_command(escapeshellarg($bin).' -Z1 '.escapeshellarg($zipPath),$out,$code)&&$code===0&&csu_safe_archive_names($out)){
            $out2=array();$code2=0;
            if(csu_run_command(escapeshellarg($bin).' -oq '.escapeshellarg($zipPath).' -d '.escapeshellarg($extract),$out2,$code2)&&$code2===0){$engine=$bin;return true;}
            $detail='unzip retornou código '.(int)$code2.'.';
        } elseif($detail==='') $detail='Não foi possível validar a lista interna do ZIP com unzip.';
    }
    if($detail==='')$detail='Nenhum extrator ZIP utilizável. ZipArchive e /usr/bin/unzip estão indisponíveis para o PHP do painel.';
    return false;
}
function csu_path_relative($path,$root) {
    $path=str_replace('\\','/',(string)$path);$root=rtrim(str_replace('\\','/',(string)$root),'/');
    if($root===''||strpos($path,$root.'/')!==0)return '';
    return ltrim(substr($path,strlen($root)),'/');
}
function csu_safe_iterator($root,$mode=RecursiveIteratorIterator::SELF_FIRST) {
    try {
        $dir=new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_FILEINFO);
        return new RecursiveIteratorIterator($dir,$mode,RecursiveIteratorIterator::CATCH_GET_CHILD);
    } catch(Throwable $e) {
        csu_update_log('iterator_failed',array('root'=>$root,'error'=>$e->getMessage()));
        return false;
    }
}
function csu_copy_file_atomic($src,$dst,&$error) {
    $error='';
    if(!is_file($src)||!is_readable($src)){$error='Origem ausente ou sem leitura: '.$src;return false;}
    $parent=dirname($dst);
    if(!is_dir($parent)&&!@mkdir($parent,0755,true)&&!is_dir($parent)){$error='Não foi possível criar diretório: '.$parent;return false;}
    if(!is_writable($parent)){$error='Diretório de destino sem permissão de escrita: '.$parent;return false;}
    if(is_dir($dst)){$error='Destino é diretório, mas o pacote contém arquivo: '.$dst;return false;}
    $tmp=$parent.'/.csu-'.basename($dst).'.'.bin2hex(random_bytes(4)).'.tmp';
    if(!@copy($src,$tmp)){$e=error_get_last();$error='Falha ao copiar para temporário: '.$tmp.($e&&isset($e['message'])?' — '.$e['message']:'');@unlink($tmp);return false;}
    $mode=@fileperms($src);if($mode!==false)@chmod($tmp,$mode&0777);
    if(is_file($dst)&&!@unlink($dst)){
        $e=error_get_last();$error='Não foi possível substituir arquivo existente: '.$dst.($e&&isset($e['message'])?' — '.$e['message']:'');@unlink($tmp);return false;
    }
    if(!@rename($tmp,$dst)){
        $e=error_get_last();$error='Não foi possível ativar arquivo novo: '.$dst.($e&&isset($e['message'])?' — '.$e['message']:'');@unlink($tmp);return false;
    }
    clearstatcache(true,$dst);
    if(!is_file($dst)||@filesize($dst)!==@filesize($src)){$error='Verificação pós-cópia falhou: '.$dst;return false;}
    return true;
}
function csu_preflight_tree($src,$dst,&$issues,$maxIssues=25) {
    $issues=array();if(!is_dir($src)){ $issues[]='Raiz do pacote ausente: '.$src;return false; }
    $it=csu_safe_iterator($src,RecursiveIteratorIterator::SELF_FIRST);if($it===false){$issues[]='Não foi possível percorrer o pacote.';return false;}
    foreach($it as $item){
        $rel=csu_path_relative($item->getPathname(),$src);if($rel==='')continue;$to=$dst.'/'.$rel;
        if($item->isDir()){
            $probe=$to;while(!is_dir($probe)&&dirname($probe)!==$probe)$probe=dirname($probe);
            if(is_dir($probe)&&!is_writable($probe))$issues[]='Sem escrita no diretório: '.$probe;
        } elseif($item->isFile()) {
            $parent=dirname($to);$probe=$parent;while(!is_dir($probe)&&dirname($probe)!==$probe)$probe=dirname($probe);
            if(is_dir($to))$issues[]='Conflito arquivo/diretório: '.$to;
            elseif(is_dir($probe)&&!is_writable($probe))$issues[]='Sem escrita para instalar: '.$to;
        }
        if(count($issues)>=$maxIssues)break;
    }
    $issues=array_values(array_unique($issues));return !$issues;
}
function csu_copy_tree($src,$dst,&$detail=null,&$created=null,$progressCallback=null) {
    $detail='';if(!is_array($created))$created=array();
    if(!is_dir($src)){$detail='Diretório de origem inexistente: '.$src;return false;}
    if(!is_dir($dst)&&!@mkdir($dst,0755,true)&&!is_dir($dst)){$detail='Não foi possível criar destino: '.$dst;return false;}
    $it=csu_safe_iterator($src,RecursiveIteratorIterator::SELF_FIRST);if($it===false){$detail='Falha ao percorrer diretório de origem.';return false;}
    $files=array();$dirs=array();
    foreach($it as $item){$rel=csu_path_relative($item->getPathname(),$src);if($rel==='')continue;if($item->isDir())$dirs[]=array($item->getPathname(),$rel);elseif($item->isFile())$files[]=array($item->getPathname(),$rel);}
    foreach($dirs as $entry){$to=$dst.'/'.$entry[1];if(!is_dir($to)){if(!@mkdir($to,0755,true)&&!is_dir($to)){$detail='Falha ao criar diretório: '.$to;return false;}$created[]=$to;}}
    $total=max(1,count($files));$done=0;
    foreach($files as $entry){
        $from=$entry[0];$rel=$entry[1];$to=$dst.'/'.$rel;$wasThere=file_exists($to)||is_link($to);$err='';
        if(!csu_copy_file_atomic($from,$to,$err)){$detail=$rel.' — '.$err;csu_update_log('copy_file_failed',array('file'=>$rel,'source'=>$from,'destination'=>$to,'error'=>$err));return false;}
        if(!$wasThere)$created[]=$to;$done++;
        if(is_callable($progressCallback))call_user_func($progressCallback,$done,$total,$rel);
    }
    return true;
}
function csu_remove_created(array $created,$dstRoot) {
    usort($created,function($a,$b){return strlen($b)<=>strlen($a);});
    $root=rtrim(realpath($dstRoot)?:$dstRoot,'/').'/';
    foreach($created as $path){
        $normalized=str_replace('\\','/',(string)$path);
        if(strpos($normalized,str_replace('\\','/',$root))!==0)continue;
        if(is_file($path)||is_link($path))@unlink($path);elseif(is_dir($path)){ $items=@scandir($path);if(is_array($items)&&count($items)<=2)@rmdir($path); }
    }
}
function csu_remove_tree($path) {
    $path=(string)$path;
    if($path===''||$path==='/'||$path===CS_PLAYER_ROOT)return false;
    if(!file_exists($path)&&!is_link($path))return true;
    if(is_file($path)||is_link($path))return @unlink($path);
    $items=@scandir($path);
    if(!is_array($items))return false;
    foreach($items as $name){
        if($name==='.'||$name==='..')continue;
        $child=$path.DIRECTORY_SEPARATOR.$name;
        if(is_dir($child)&&!is_link($child))csu_remove_tree($child);else @unlink($child);
    }
    return @rmdir($path);
}
function csu_backup_targets($srcRoot,$dstRoot,$backupRoot,&$detail=null) {
    $detail='';$it=csu_safe_iterator($srcRoot,RecursiveIteratorIterator::LEAVES_ONLY);if($it===false){$detail='Falha ao percorrer pacote para backup.';return false;}
    foreach($it as $item){if(!$item->isFile())continue;$rel=csu_path_relative($item->getPathname(),$srcRoot);if($rel==='')continue;$current=$dstRoot.'/'.$rel;if(!is_file($current))continue;$to=$backupRoot.'/files/'.$rel;if(!is_dir(dirname($to))&&!@mkdir(dirname($to),0755,true)&&!is_dir(dirname($to))){$detail='Falha ao criar backup: '.dirname($to);return false;}if(!@copy($current,$to)){$detail='Falha ao copiar backup de: '.$rel;return false;}}return true;
}
function csu_restore_targets($backupRoot,$dstRoot,&$detail=null){$src=$backupRoot.'/files';$created=array();return !is_dir($src)||csu_copy_tree($src,$dstRoot,$detail,$created);}
function csu_update_log($event,$ctx=array()){$dir=CS_PLAYER_ROOT.'/admin/logs/cs-player';if(!is_dir($dir))@mkdir($dir,0755,true);$line=json_encode(array('time'=>date('c'),'event'=>$event,'context'=>$ctx),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(is_string($line))@file_put_contents($dir.'/update.log',$line.PHP_EOL,FILE_APPEND|LOCK_EX);}
function csu_write_state($m){$dir=CS_PLAYER_ROOT.'/admin/logs/cs-player';if(!is_dir($dir))@mkdir($dir,0755,true);$state=array('product'=>'CS PLAYER','version'=>(string)$m['version'],'build'=>(int)($m['build']??0),'channel'=>(string)($m['channel']??'stable'),'updated_at'=>date('c'),'manifest'=>(string)($m['_manifest_url']??CS_PLAYER_UPDATE_MANIFEST));return @file_put_contents($dir.'/update-state.json',json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function csu_php_cli() {
    if(!csu_function_enabled('exec')&&!csu_function_enabled('proc_open')) return '';
    $candidates=array();
    if(defined('PHP_BINARY') && PHP_BINARY) $candidates[]=(string)PHP_BINARY;
    foreach(array('/usr/bin/php8.4','/usr/bin/php8.3','/usr/bin/php','/usr/local/bin/php',CS_PLAYER_ROOT.'/php/bin/php') as $bin) $candidates[]=$bin;
    $seen=array();
    foreach($candidates as $bin){
        if($bin===''||isset($seen[$bin])||!is_file($bin)||!is_executable($bin)) continue;
        $seen[$bin]=true;$out=array();$code=0;
        if(!csu_run_command(escapeshellarg($bin).' -r '.escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'),$out,$code)||$code!==0) continue;
        $ver=trim(implode("\n",$out));
        if(preg_match('/^(\d+)\.(\d+)$/',$ver,$m) && ((int)$m[1]>8 || ((int)$m[1]===8 && (int)$m[2]>=3))) return $bin;
    }
    return '';
}
function csu_lint_tree($root,&$badFile,&$lintDetail=null) {
    $badFile='';$lintDetail='';$phpBin=csu_php_cli();
    if($phpBin===''){ $lintDetail='CLI PHP 8.3+ não disponível; validação sintática externa ignorada.'; return true; }
    $it=csu_safe_iterator($root,RecursiveIteratorIterator::LEAVES_ONLY);if($it===false){$lintDetail='Não foi possível percorrer a árvore PHP.';return false;}
    foreach($it as $file){
        if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
        $out=array();$code=0;
        if(!csu_run_command(escapeshellarg($phpBin).' -l '.escapeshellarg($file->getPathname()),$out,$code)||$code!==0){
            $badFile=$file->getPathname();$lintDetail=trim(implode("\n",$out));return false;
        }
    }
    $lintDetail='Validado com '.$phpBin.'.';return true;
}
function csu_progress_path($token){
    $token=preg_replace('/[^a-zA-Z0-9_-]/','',(string)$token);
    if($token==='') return '';
    $dir=CS_PLAYER_ROOT.'/admin/logs/cs-player/update-progress';if(!is_dir($dir))@mkdir($dir,0755,true);
    return $dir.'/'.$token.'.json';
}
function csu_progress($token,$percent,$stage,$message){
    $path=csu_progress_path($token);if($path==='')return;
    $data=array('ok'=>true,'percent'=>max(0,min(100,(int)$percent)),'stage'=>(string)$stage,'message'=>(string)$message,'updated_at'=>date('c'));
    @file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function csu_cleanup_match_file($path,$olderThan,$extensions=array(),$prefixes=array()){
    if(!is_file($path)||is_link($path))return false;
    $mtime=@filemtime($path);if($mtime===false||$mtime>=$olderThan)return false;
    $name=basename($path);$lower=strtolower($name);
    foreach($prefixes as $prefix){if(strpos($name,$prefix)===0)return true;}
    foreach($extensions as $ext){$ext=strtolower($ext);if($ext!==''&&substr($lower,-strlen($ext))===$ext)return true;}
    return false;
}
function csu_cleanup_dir_files($base,$olderThan,array $extensions,array $prefixes,$maxItems=1500){
    $result=array('removed'=>0,'bytes'=>0,'failed'=>0,'scanned'=>0);
    if(!is_dir($base)||!is_readable($base))return $result;
    $queue=array(rtrim($base,'/'));$seen=0;
    while($queue&&$seen<$maxItems){
        $dir=array_shift($queue);$items=@scandir($dir);if(!is_array($items)){ $result['failed']++; continue; }
        foreach($items as $name){
            if($name==='.'||$name==='..')continue;
            if(++$seen>$maxItems)break 2;
            $path=$dir.DIRECTORY_SEPARATOR.$name;$result['scanned']++;
            if(is_dir($path)&&!is_link($path)){$queue[]=$path;continue;}
            if(!csu_cleanup_match_file($path,$olderThan,$extensions,$prefixes))continue;
            $size=(int)@filesize($path);if(@unlink($path)){$result['removed']++;$result['bytes']+=$size;}else{$result['failed']++;}
        }
    }
    return $result;
}
function csu_cleanup_step($step){
    $now=time();$result=array('removed'=>0,'bytes'=>0,'failed'=>0,'scanned'=>0,'step'=>$step);
    if($step==='temp'){
        foreach(array(sys_get_temp_dir(),'/var/tmp') as $base){
            $r=csu_cleanup_dir_files($base,$now-86400,array('.tmp','.temp','.part','.cache','.log.old'),array('cs-player-web-update-','cs-player-update-'),1200);
            foreach(array('removed','bytes','failed','scanned') as $k)$result[$k]+=$r[$k];
        }
    } elseif($step==='update_tmp'){
        foreach(array(sys_get_temp_dir(),'/var/tmp') as $base){
            if(!is_dir($base))continue;$items=@scandir($base);if(!is_array($items)){ $result['failed']++; continue; }
            foreach($items as $name){
                if($name==='.'||$name==='..')continue;$result['scanned']++;
                if(strpos($name,'cs-player-web-update-')!==0&&strpos($name,'cs-player-update-')!==0)continue;
                $path=$base.DIRECTORY_SEPARATOR.$name;$mtime=@filemtime($path);if($mtime!==false&&$mtime>=$now-3600)continue;
                $before=0;if(is_file($path))$before=(int)@filesize($path);
                $ok=is_dir($path)?csu_remove_tree($path):@unlink($path);
                if($ok){$result['removed']++;$result['bytes']+=$before;}else{$result['failed']++;}
            }
        }
    } elseif($step==='cache'){
        foreach(array(CS_PLAYER_ROOT.'/admin/tmp',CS_PLAYER_ROOT.'/admin/cache',CS_PLAYER_ROOT.'/admin/uploads/tmp') as $base){
            $r=csu_cleanup_dir_files($base,$now-86400,array('.tmp','.temp','.part','.cache','.log.old'),array('cs-player-'),2000);
            foreach(array('removed','bytes','failed','scanned') as $k)$result[$k]+=$r[$k];
        }
    } elseif($step==='progress'){
        $base=CS_PLAYER_ROOT.'/admin/logs/cs-player/update-progress';
        $r=csu_cleanup_dir_files($base,$now-21600,array('.json'),array(),1000);
        foreach(array('removed','bytes','failed','scanned') as $k)$result[$k]+=$r[$k];
    } elseif($step==='backups'){
        $backupBase=CS_PLAYER_ROOT.'/admin/backups/remote-updates';
        if(is_dir($backupBase)){
            $dirs=array();$items=@scandir($backupBase);
            if(is_array($items))foreach($items as $name){if($name==='.'||$name==='..')continue;$path=$backupBase.'/'.$name;if(is_dir($path)&&!is_link($path))$dirs[]=$path;}
            usort($dirs,function($a,$b){return (int)@filemtime($b)<=>(int)@filemtime($a);});
            foreach(array_slice($dirs,3) as $dir){$result['scanned']++;$mtime=@filemtime($dir);if($mtime!==false&&$mtime>=$now-86400)continue;if(csu_remove_tree($dir))$result['removed']++;else$result['failed']++;}
        }
    } else {
        throw new InvalidArgumentException('Etapa de limpeza inválida.');
    }
    return $result;
}
function csu_cleanup_safe(){
    $total=array('removed'=>0,'bytes'=>0,'failed'=>0,'scanned'=>0);
    foreach(array('temp','update_tmp','cache','progress','backups') as $step){
        try{$r=csu_cleanup_step($step);}catch(Throwable $e){$r=array('removed'=>0,'bytes'=>0,'failed'=>1,'scanned'=>0);csu_update_log('cleanup_step_failed',array('step'=>$step,'error'=>$e->getMessage()));}
        foreach(array('removed','bytes','failed','scanned') as $k)$total[$k]+=(int)($r[$k]??0);
    }
    return $total;
}

$action=isset($_GET['action'])?(string)$_GET['action']:(isset($_POST['action'])?(string)$_POST['action']:'check');
if($action==='check'){
    list($manifest,$error)=csu_manifest();if($manifest===false)csu_json(array('ok'=>false,'message'=>$error,'manifest_url'=>CS_PLAYER_UPDATE_MANIFEST,'extractors'=>csu_extractor_capabilities()),502);
    $local=csu_local_state();$rb=(int)($manifest['build']??0);$lb=(int)($local['build']??0);$available=csu_is_newer($manifest['version'],$local['version'],$rb,$lb);
    csu_json(array('ok'=>true,'available'=>$available,'installed'=>$local,'remote'=>array('product'=>(string)($manifest['product']??'CS PLAYER'),'version'=>(string)$manifest['version'],'build'=>$rb,'channel'=>(string)($manifest['channel']??'stable'),'published_at'=>(string)($manifest['published_at']??''),'changelog'=>(isset($manifest['changelog'])&&is_array($manifest['changelog']))?$manifest['changelog']:array(),'required'=>(bool)($manifest['update']['required']??false),'database_changes'=>(bool)($manifest['update']['database_changes']??false)),'manifest_url'=>(string)($manifest['_manifest_url']??CS_PLAYER_UPDATE_MANIFEST),'extractors'=>csu_extractor_capabilities()),200);
}
if($action==='status'){$token=(string)($_GET['token']??'');$path=csu_progress_path($token);if($path!==''&&is_file($path)){$d=json_decode((string)@file_get_contents($path),true);if(is_array($d))csu_json($d,200);}csu_json(array('ok'=>true,'percent'=>0,'stage'=>'aguardando','message'=>'Aguardando início...'),200);}
if($action==='cleanup_step'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $step=(string)($_POST['step']??'');
    try{$r=csu_cleanup_step($step);csu_update_log('cleanup_step_completed',$r);csu_json(array('ok'=>true,'message'=>'Etapa concluída.','result'=>$r),200);}catch(Throwable $e){csu_update_log('cleanup_step_failed',array('step'=>$step,'error'=>$e->getMessage()));csu_json(array('ok'=>false,'message'=>'Falha controlada na etapa de limpeza: '.$e->getMessage()),422);}
}
if($action==='cleanup'&&$_SERVER['REQUEST_METHOD']==='POST'){
    try{$r=csu_cleanup_safe();csu_update_log('cleanup_completed',$r);csu_json(array('ok'=>true,'message'=>'Limpeza concluída com segurança.','removed'=>$r['removed'],'bytes'=>$r['bytes'],'failed'=>$r['failed'],'scanned'=>$r['scanned']),200);}catch(Throwable $e){csu_update_log('cleanup_failed',array('error'=>$e->getMessage()));csu_json(array('ok'=>false,'message'=>'A limpeza foi interrompida com segurança. Consulte update.log para detalhes.'),500);}
}
if($action!=='install'||$_SERVER['REQUEST_METHOD']!=='POST')csu_json(array('ok'=>false,'message'=>'Ação inválida.'),400);

$token=(string)($_POST['token']??'');csu_progress($token,2,'inicialização','Preparando atualização...');
$lockFp=@fopen(sys_get_temp_dir().'/cs-player-update.lock','c');
if(!$lockFp||!@flock($lockFp,LOCK_EX|LOCK_NB))csu_json(array('ok'=>false,'message'=>'Já existe uma atualização em andamento.'),409);
register_shutdown_function(function()use($lockFp){if(is_resource($lockFp)){@flock($lockFp,LOCK_UN);@fclose($lockFp);}});

csu_progress($token,7,'manifesto','Validando manifesto remoto...');
list($manifest,$error)=csu_manifest();if($manifest===false)csu_json(array('ok'=>false,'message'=>$error),502);
$local=csu_local_state();$rb=(int)($manifest['build']??0);$lb=(int)($local['build']??0);
if(!csu_is_newer($manifest['version'],$local['version'],$rb,$lb))csu_json(array('ok'=>true,'updated'=>false,'message'=>'CS PLAYER já está atualizado.','installed'=>$local),200);
if(!empty($manifest['update']['database_changes']))csu_json(array('ok'=>false,'message'=>'Atualização bloqueada: o manifesto informa alteração de banco de dados.'),409);
$caps=csu_extractor_capabilities();if(empty($caps['ziparchive'])&&empty($caps['unzip']))csu_json(array('ok'=>false,'message'=>'Nenhum extrator ZIP disponível. Instale ZipArchive ou habilite /usr/bin/unzip para o PHP do painel.','extractors'=>$caps),500);

$tmp=sys_get_temp_dir().'/cs-player-web-update-'.bin2hex(random_bytes(5));$zipPath=$tmp.'/update.zip';$extract=$tmp.'/extract';@mkdir($extract,0755,true);
register_shutdown_function(function()use($tmp){if(is_dir($tmp))csu_remove_tree($tmp);});
csu_progress($token,15,'download','Baixando pacote de atualização...');
if(!csu_download_file($manifest['_download_url'],$zipPath))csu_json(array('ok'=>false,'message'=>'Falha ao baixar o pacote de atualização.'),502);
csu_progress($token,35,'integridade','Verificando tamanho e SHA-256...');
$actualSize=(int)@filesize($zipPath); $expectedSize=(int)($manifest['_size']??0);
if($expectedSize>0 && $actualSize!==$expectedSize){csu_update_log('package_size_mismatch',array('expected_bytes'=>$expectedSize,'actual_bytes'=>$actualSize,'version'=>(string)$manifest['version']));csu_json(array('ok'=>false,'message'=>'Tamanho do pacote não confere com o manifesto. Atualização cancelada.','expected_bytes'=>$expectedSize,'actual_bytes'=>$actualSize),409);}
if($manifest['_sha256']!==''){$actual=strtolower((string)hash_file('sha256',$zipPath));if(!hash_equals($manifest['_sha256'],$actual)){csu_update_log('package_sha256_mismatch',array('expected_sha256'=>$manifest['_sha256'],'actual_sha256'=>$actual,'size_bytes'=>$actualSize,'version'=>(string)$manifest['version']));csu_json(array('ok'=>false,'message'=>'SHA-256 do pacote não confere. Atualização cancelada.','expected_sha256'=>$manifest['_sha256'],'actual_sha256'=>$actual),409);}}
csu_progress($token,45,'extração','Extraindo pacote em área temporária...');
$engine='';$detail='';if(!csu_extract_package($zipPath,$extract,$engine,$detail)){csu_update_log('extract_failed',array('detail'=>$detail));csu_json(array('ok'=>false,'message'=>'Falha ao extrair atualização: '.$detail,'extractors'=>$caps),500);}
$root=$extract.'/XtreamUI-master';if(!is_dir($root)||!is_file($root.'/admin/settings.php')||!is_file($root.'/admin/player/bootstrap.php'))csu_json(array('ok'=>false,'message'=>'Pacote CS PLAYER incompatível: estrutura técnica de instalação ou arquivos críticos ausentes.'),409);
$lintDetail='';$bad='';csu_progress($token,58,'validação','Validando PHP com runtime CLI compatível...');if(!csu_lint_tree($root.'/admin',$bad,$lintDetail))csu_json(array('ok'=>false,'message'=>'Pacote contém arquivo PHP com erro de sintaxe.','file'=>substr($bad,strlen($root)+1),'detail'=>$lintDetail),409);

csu_progress($token,68,'backup','Criando backup de rollback...');
$stamp=date('Y-m-d_H-i-s');$backup=CS_PLAYER_ROOT.'/admin/backups/remote-updates/'.$stamp;if(!@mkdir($backup,0755,true)&&!is_dir($backup))csu_json(array('ok'=>false,'message'=>'Não foi possível criar diretório de backup.'),500);
$backupDetail='';if(!csu_backup_targets($root,CS_PLAYER_ROOT,$backup,$backupDetail)){csu_update_log('backup_failed',array('error'=>$backupDetail,'backup'=>$backup));csu_json(array('ok'=>false,'message'=>'Não foi possível criar backup transacional dos arquivos que serão substituídos.','detail'=>$backupDetail),500);}
$persist=array('config','admin/logs/cs-player/web-player-config.php','admin/logs/cs-player/web-player-config.bak.php','admin/logs/cs-player/web-player-dns.json','admin/logs/cs-player/web-player-dns-health.php','admin/logs/cs-player/dns-health.json','admin/logs/cs-player/update-state.json');$persistData=array();
foreach($persist as $rel){$path=CS_PLAYER_ROOT.'/'.$rel;if(is_file($path))$persistData[$rel]=@file_get_contents($path);}
$preflightIssues=array();csu_progress($token,74,'pré-verificação','Verificando permissões e destinos...');
if(!csu_preflight_tree($root,CS_PLAYER_ROOT,$preflightIssues)){csu_update_log('preflight_failed',array('issues'=>$preflightIssues));csu_json(array('ok'=>false,'message'=>'A atualização não pode escrever em um ou mais caminhos do CS PLAYER. Nenhum arquivo foi alterado.','issues'=>$preflightIssues,'hint'=>'Ajuste proprietário/permissões do diretório do CS PLAYER para o usuário do PHP-FPM e tente novamente.'),409);}
csu_update_log('install_started',array('version'=>(string)$manifest['version'],'build'=>$rb,'extractor'=>$engine,'backup'=>$backup));
csu_progress($token,80,'instalação','Aplicando arquivos do CS PLAYER...');
$copyDetail='';$createdPaths=array();
$progressCb=function($done,$total,$rel)use($token){$pct=80+(int)floor(($done/max(1,$total))*10);csu_progress($token,min(90,$pct),'instalação','Aplicando '.$done.'/'.$total.' — '.$rel);};
if(!csu_copy_tree($root,CS_PLAYER_ROOT,$copyDetail,$createdPaths,$progressCb)){
    csu_remove_created($createdPaths,CS_PLAYER_ROOT);$restoreDetail='';$restored=csu_restore_targets($backup,CS_PLAYER_ROOT,$restoreDetail);
    csu_update_log('rollback_copy_failure',array('backup'=>$backup,'copy_error'=>$copyDetail,'restore_ok'=>$restored,'restore_error'=>$restoreDetail,'created_removed'=>count($createdPaths)));
    csu_json(array('ok'=>false,'message'=>'Falha ao copiar arquivos. Rollback automático executado.','detail'=>$copyDetail,'backup'=>$backup,'rollback_ok'=>$restored),500);
}
foreach($persistData as $rel=>$contents){if(!is_string($contents))continue;$path=CS_PLAYER_ROOT.'/'.$rel;if(!is_dir(dirname($path)))@mkdir(dirname($path),0755,true);@file_put_contents($path,$contents,LOCK_EX);}
$lintDetail='';$bad='';csu_progress($token,92,'verificação','Validando instalação aplicada...');if(!csu_lint_tree(CS_PLAYER_ROOT.'/admin/player',$bad,$lintDetail)){csu_remove_created($createdPaths,CS_PLAYER_ROOT);$restoreDetail='';$restored=csu_restore_targets($backup,CS_PLAYER_ROOT,$restoreDetail);foreach($persistData as $rel=>$contents){if(is_string($contents))@file_put_contents(CS_PLAYER_ROOT.'/'.$rel,$contents,LOCK_EX);}csu_update_log('rollback_post_lint_failure',array('file'=>$bad,'backup'=>$backup,'restore_ok'=>$restored,'restore_error'=>$restoreDetail));csu_json(array('ok'=>false,'message'=>'Validação pós-atualização falhou. Rollback automático executado.','file'=>$bad,'backup'=>$backup,'rollback_ok'=>$restored),500);}
csu_progress($token,98,'finalização','Finalizando e gravando estado da versão...');csu_write_state($manifest);csu_update_log('install_completed',array('version'=>(string)$manifest['version'],'build'=>$rb,'extractor'=>$engine,'backup'=>$backup));
csu_progress($token,100,'concluído','Atualização concluída com sucesso.');
csu_json(array('ok'=>true,'updated'=>true,'message'=>'CS PLAYER atualizado para '.(string)$manifest['version'].'.','version'=>(string)$manifest['version'],'build'=>$rb,'backup'=>$backup,'database_changed'=>false,'extractor'=>$engine,'rollback_ready'=>true),200);
