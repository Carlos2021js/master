# README #
# xtream-ui-install (compatível Ubuntu 22 + Python 3.13)

This is an installation mirror for xtream ui software.

### Como instalar (Ubuntu 22.04) ###

update your ubuntu first, then install panel  
  
* sudo apt-get update && sudo apt-get upgrade -y
* sudo apt-get install -y libxslt1-dev libcurl4 libgeoip-dev python3
* cd /home/user/workspace/xtream-ui-install-master
* sudo python3 install_py3.py
  - Quando perguntado, você pode optar por instalar Python 3.13 (deadsnakes)
  - Escolha MAIN para painel/admin, ou LB para balanceador

  
If you want to install main server with admin panel, choose MAIN.  
If you want to install load balance on additional servers, add a server to panel in manage servers page, then run script and proceed with LB option.  
If you want to update admin panel, select UPDATE, then paste download link of release_xyz.zip file.  

### Tutoriais ###

[Xtream-UI Tutorials](https://www.youtube.com/playlist?list=PLJB51brdC_w7dTDxi1MPqiuk3JH5U2ekn "Xtream-UI Tutorials")


### Files Hashes ###
* main_xtreamcodes_reborn.tar
* sha1: "532B63EA0FEA4E6433FC47C3B8E65D8A90D5A4E9"

* sub_xtreamcodes_reborn.tar
* sha1: "5F8A7643A9E7692108E8B40D0297A7A5E4423870"

* release_22f.zip
* sha-1: "95471A7EFEB49D7A1F52BAB683EA2BF849F79983"

### notas
- Este repositório inclui um instalador modernizado `install_py3.py` compatível com Ubuntu 22.04.
- Mantém a lógica do mirror, atualizando pacotes para `libcurl4` e removendo dependências legadas de Python 2.

### GeoLite2 (atualização) & melhorias de player e fonte
- GeoLite2: incluiu-se `update_geolite2.sh` para baixar e instalar a base GeoLite2-City mais recente diretamente da MaxMind.
  - Requer variáveis de ambiente: `MAXMIND_ACCOUNT_ID` e `MAXMIND_LICENSE_KEY` (cadastradas na MaxMind)
  - Execute: `sudo MAXMIND_ACCOUNT_ID=SEU_ID MAXMIND_LICENSE_KEY=SUA_CHAVE ./update_geolite2.sh`
  - O script instala e protege `/home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb` (chattr +i)
- Adicionada página `font_tester.html` para testar fontes e tamanhos (copiada para `wwwdir/`).
- Adicionado `player_upgrade.js` com player híbrido HLS/DASH via `hls.js` e `dash.js`.
- Use `font_tester.html` para validar fontes; integre `player_upgrade.js` nas páginas existentes conforme necessário.


### Publicar no GitHub (instruções) & transmissão híbrida e desempenho
**Publicar no GitHub**
1) Crie um novo repositório no GitHub (nome sugerido: `xtream-ui-install-ubuntu22`)
2) No terminal, dentro da pasta do projeto:
```
git init
git add .
# Garante que binários e mmdb não entrem no repo
cat [33m[39m [32m[39m > .gitignore << 'EOF'
/home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb
*.mmdb
*.tar.gz
*.zip
*.7z
*.log
EOF

git commit -m "Installer modernizado: Ubuntu 22, Python 3.13, player híbrido, GeoLite2 update script, importador M3U/M3U8"
git branch -M main
git remote add origin https://github.com/SEU_USUARIO/xtream-ui-install-ubuntu22.git
git push -u origin main
```
3) Para manter GeoLite2 atualizado sem versionar o arquivo `.mmdb` (evita licenças no repo):
   - Inclua `update_geolite2.sh` e documente a execução pós-instalação
   - O arquivo `.mmdb` ficará fora do repo e será instalado no host via script

**Transmissão híbrida e desempenho**
- Habilitado player HLS/DASH com parâmetros de buffer.
- Recomenda-se ativar HTTP/2 e tunar `fastcgi_buffers` e `worker_processes` no Nginx para maior desempenho.

**Menu Avançado (importação por link) + Sincronização**
- A página `advanced_import_m3u.php` é copiada para `wwwdir/` pelo instalador.
- Adicione um item no menu do painel apontando para `/advanced_import_m3u.php`.
- Campos de sincronização: ligar/desligar, intervalo (min), remover ausentes, organizar automaticamente.
- Para executar manualmente: `/sync_m3u_cron.php` (link disponível na página).
- Cron é instalado para rodar a cada 30 min e no boot; o script só aplica mudanças se `enabled=true` em `m3u_sync_settings.json`.
- Logs: `/home/xtreamcodes/logs/import_m3u.log` (import manual) e `/home/xtreamcodes/logs/sync_m3u.log` (cron).



### Exemplo de link de atualização via Dropbox
Use seu link público do Dropbox com `dl=1` para download direto.

Exemplo (seu link):
- Original: https://www.dropbox.com/scl/fi/eugdfdkdirg6fu3mueey4/csplay1.0.zip?rlkey=8ub67h3agyxyqgojz1rq0o4qq&st=iwzaulic&dl=0
- Para baixar direto: https://www.dropbox.com/scl/fi/eugdfdkdirg6fu3mueey4/csplay1.0.zip?rlkey=8ub67h3agyxyqgojz1rq0o4qq&st=iwzaulic&dl=1

Na página do painel “Avançado → Atualizar Painel via Dropbox”, cole o link com `dl=1` e clique em “Baixar, Validar e Atualizar”.
