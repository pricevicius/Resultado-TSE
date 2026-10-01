#!/bin/sh
# Testa os POSTs do admin de verdade (HTTP, cookie de administrador, nonce lido da própria página):
# "Salvar" da reimportação automática e "Salvar seleção" (liga/desliga disputa e o aviso de UF desligada).
# Faz backup de ae_contests e das opções que mexe, e restaura tudo no final.
#   TSE_APURACAO_CONTAINER  container do WordPress (padrão: revistaforum-app)
#   TSE_SITE_URL            URL do site (padrão: http://localhost)
set -u
CONTAINER="${TSE_APURACAO_CONTAINER:-revistaforum-app}"
SITE="${TSE_SITE_URL:-http://localhost}"
WP="docker exec -u www-data $CONTAINER wp --path=${TSE_WP_PATH:-/var/www/html}"
fail=0
check() { if [ "$2" = "1" ]; then echo "ok   $1"; else echo "FAIL $1 — $3"; fail=1; fi; }

PFX=$($WP eval 'global $wpdb; echo $wpdb->prefix;')
BACKUP=/tmp/ae-http-admin-backup.sql
$WP db export "$BACKUP" --tables="${PFX}ae_contests" --single-transaction --set-gtid-purged=OFF >/dev/null 2>&1 || { echo "FAIL backup de ae_contests"; exit 1; }
OPT_AUTO=$($WP option get ae_auto_import_election 2>/dev/null || echo __none__)
restore() {
	$WP db import "$BACKUP" >/dev/null 2>&1
	if [ "$OPT_AUTO" = "__none__" ]; then $WP option delete ae_auto_import_election >/dev/null 2>&1; else $WP option update ae_auto_import_election "$OPT_AUTO" >/dev/null 2>&1; fi
	docker exec "$CONTAINER" rm -f "$BACKUP"
}
trap restore EXIT

# Cookies de um administrador (sessão real, para o nonce bater).
COOKIES=$($WP eval '
$u = get_users( array( "role" => "administrator", "number" => 1 ) )[0]->ID;
$exp = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance( $u )->create( $exp );
echo AUTH_COOKIE . "=" . wp_generate_auth_cookie( $u, $exp, "auth", $token ) . "; " . LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie( $u, $exp, "logged_in", $token );')
[ -n "$COOKIES" ] || { echo "FAIL não foi possível criar a sessão de administrador"; exit 1; }

page() { curl -s -H "Cookie: $COOKIES" "$SITE/wp-admin/admin.php?page=apuracao-eleitoral&tab=$1" | tr '\n' ' '; }
nonce_for() { # $1=html $2=action
	printf '%s' "$1" | grep -oP "name=\"_wpnonce\" value=\"\K[a-z0-9]+(?=\"[^>]*>\s*<input[^>]*_wp_http_referer[^>]*>\s*<input type=\"hidden\" name=\"action\" value=\"$2\")" | head -1
}
post() { # $1=cookie-mode(auth|none) resto=args curl; imprime "status|location"
	mode=$1; shift
	if [ "$mode" = auth ]; then H="Cookie: $COOKIES"; else H="X-None: 1"; fi
	curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' -H "$H" "$@" "$SITE/wp-admin/admin-post.php"
}

# --- Reimportação automática
IMPORT=$(page import)
N=$(nonce_for "$IMPORT" ae_save_auto_import)
check "aba Importar carrega e traz o formulário da reimportação" "$([ -n "$N" ] && echo 1 || echo 0)" "nonce não encontrado"
EID=$($WP db query "SELECT id FROM ${PFX}ae_elections ORDER BY year DESC, id DESC LIMIT 1" --skip-column-names)
R=$(post auth -d action=ae_save_auto_import -d _wpnonce="$N" -d election_id="$EID")
check "Salvar reimportação: redireciona com aviso de sucesso" "$(printf '%s' "$R" | grep -q '^302|.*tab=import.*ae_type=success' && echo 1 || echo 0)" "$R"
check "Salvar reimportação: opção gravada com a eleição escolhida" "$([ "$($WP option get ae_auto_import_election)" = "$EID" ] && echo 1 || echo 0)" "$($WP option get ae_auto_import_election)"
R=$(post auth -d action=ae_save_auto_import -d _wpnonce="$N" -d election_id=0)
check "Desligar: opção volta a 0" "$([ "$($WP option get ae_auto_import_election)" = "0" ] && echo 1 || echo 0)" "$R"
R=$(post auth -d action=ae_save_auto_import -d _wpnonce=0000000000 -d election_id="$EID")
check "nonce inválido é recusado (403) e não grava" "$(printf '%s' "$R" | grep -q '^403|' && [ "$($WP option get ae_auto_import_election)" = "0" ] && echo 1 || echo 0)" "$R"
R=$(post none -d action=ae_save_auto_import -d _wpnonce="$N" -d election_id="$EID")
check "sem login é recusado e não grava" "$(printf '%s' "$R" | grep -vq '^302|.*tab=import' && [ "$($WP option get ae_auto_import_election)" = "0" ] && echo 1 || echo 0)" "$R"
R=$(post auth -d action=ae_save_auto_import -d _wpnonce="$N" -d election_id=999999)
check "eleição inexistente é recusada" "$(printf '%s' "$R" | grep -q 'ae_type=error' && echo 1 || echo 0)" "$R"

# --- Seleção de disputas
SITE_UF=$($WP option get ae_site_uf)
ROW=$($WP db query "SELECT id, JSON_EXTRACT(config_json,'\$.collection.interval') FROM ${PFX}ae_contests WHERE active=1 AND scope_code='$SITE_UF' AND position_code='0006' LIMIT 1" --skip-column-names)
CID=$(printf '%s' "$ROW" | cut -f1); IV=$(printf '%s' "$ROW" | cut -f2)
SEL=$(page selecao)
NS=$(nonce_for "$SEL" ae_save_sync_selection)
check "aba Seleção carrega e traz o formulário" "$([ -n "$NS" ] && [ -n "$CID" ] && echo 1 || echo 0)" "nonce='$NS' contest='$CID'"
R=$(post auth -d action=ae_save_sync_selection -d _wpnonce="$NS" -d "contest_ids[]=$CID" -d "interval[$CID]=$IV")
check "Salvar seleção com a disputa desmarcada: redireciona com sucesso" "$(printf '%s' "$R" | grep -q '^302|.*tab=selecao.*ae_type=success' && echo 1 || echo 0)" "$R"
EN=$($WP db query "SELECT JSON_EXTRACT(config_json,'\$.collection.enabled') FROM ${PFX}ae_contests WHERE id=$CID" --skip-column-names)
check "disputa ficou desligada no banco" "$([ "$EN" = "false" ] && echo 1 || echo 0)" "$EN"
check "Visão geral mostra o aviso de disputas da UF desligadas" "$(page overview | grep -q "Disputas de $SITE_UF desligadas" && echo 1 || echo 0)" ""
check "o aviso aponta para a Seleção de disputas" "$(page overview | grep -q 'tab=selecao">Seleção de disputas' && echo 1 || echo 0)" ""
SEL=$(page selecao); NS=$(nonce_for "$SEL" ae_save_sync_selection)
R=$(post auth -d action=ae_save_sync_selection -d _wpnonce="$NS" -d "contest_ids[]=$CID" -d "enabled[$CID]=1" -d "interval[$CID]=$IV")
EN=$($WP db query "SELECT JSON_EXTRACT(config_json,'\$.collection.enabled') FROM ${PFX}ae_contests WHERE id=$CID" --skip-column-names)
check "religar pela tela: disputa volta a ligada e o aviso some" "$([ "$EN" = "true" ] && ! page overview | grep -q "Disputas de $SITE_UF desligadas" && echo 1 || echo 0)" "$EN"
IV2=$($WP db query "SELECT JSON_EXTRACT(config_json,'\$.collection.interval') FROM ${PFX}ae_contests WHERE id=$CID" --skip-column-names)
check "intervalo não muda quando o valor enviado é o já gravado" "$([ "$IV2" = "$IV" ] && echo 1 || echo 0)" "antes=$IV depois=$IV2"
R=$(post auth -d action=ae_save_sync_selection -d _wpnonce="$NS" -d "contest_ids[]=$CID" -d "enabled[$CID]=1" -d "interval[$CID]=240")
M=$($WP db query "SELECT CONCAT(JSON_EXTRACT(config_json,'\$.collection.interval'),'/',JSON_EXTRACT(config_json,'\$.collection.interval_mode')) FROM ${PFX}ae_contests WHERE id=$CID" --skip-column-names)
check "ajuste manual (240 s) é gravado como manual" "$([ "$M" = '240/"manual"' ] && echo 1 || echo 0)" "$M"
R=$(post auth -d action=ae_save_sync_selection -d _wpnonce="bad" -d "contest_ids[]=$CID")
check "Salvar seleção com nonce inválido é recusado (403)" "$(printf '%s' "$R" | grep -q '^403|' && echo 1 || echo 0)" "$R"

[ $fail -eq 0 ] && printf "\nTudo certo.\n" || printf "\nHá falhas.\n"
exit $fail
