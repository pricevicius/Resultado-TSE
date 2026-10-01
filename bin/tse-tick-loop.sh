#!/bin/sh
# Disparo direto do worker de coleta a cada 15s, independente do WP-Cron por
# tráfego, para uso durante a apuração ao vivo. Chame este script pelo cron
# do host a cada minuto.
#
# Ajuste para o seu ambiente via variáveis de ambiente (não edite este
# arquivo): se TSE_APURACAO_CONTAINER estiver definido, o tick roda dentro
# desse container Docker; caso contrário, roda via PHP local.
#
#   TSE_APURACAO_CONTAINER    nome do container Docker (opcional)
#   TSE_APURACAO_PLUGIN_PATH  caminho do plugin dentro do container/host
#                             (padrão: wp-content/plugins/tse-apuracao a
#                             partir da raiz do WordPress)
#   TSE_APURACAO_LOG_FILE     arquivo de log (padrão: /tmp/tse-apuracao-tick.log)

PLUGIN_PATH="${TSE_APURACAO_PLUGIN_PATH:-/var/www/html/wp-content/plugins/tse-apuracao}"
LOG_FILE="${TSE_APURACAO_LOG_FILE:-/tmp/tse-apuracao-tick.log}"

log_line() {
	printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

# Falha em voz alta: sem container e sem PHP local o tick nunca roda, e antes isso
# só aparecia como "php: not found" num log que ninguém lê (o crontab não herda
# variáveis do shell, então TSE_APURACAO_CONTAINER precisa estar DENTRO do crontab).
if [ -n "$TSE_APURACAO_CONTAINER" ]; then
	if ! command -v docker >/dev/null 2>&1; then
		MSG="ERRO: TSE_APURACAO_CONTAINER=$TSE_APURACAO_CONTAINER, mas o comando 'docker' nao existe neste host."
		log_line "$MSG" >> "$LOG_FILE"; echo "$MSG" >&2; exit 1
	fi
elif ! command -v php >/dev/null 2>&1; then
	MSG="ERRO: TSE_APURACAO_CONTAINER nao definido e 'php' nao existe neste host. Declare a variavel dentro do proprio crontab (ele nao herda variaveis do shell) ou instale o PHP."
	log_line "$MSG" >> "$LOG_FILE"; echo "$MSG" >&2; exit 1
fi

run_tick() {
	if [ -n "$TSE_APURACAO_CONTAINER" ]; then
		docker exec "$TSE_APURACAO_CONTAINER" php "$PLUGIN_PATH/bin/tse-tick.php"
	else
		php "$PLUGIN_PATH/bin/tse-tick.php"
	fi
}

for OFFSET in 0 15 30 45; do
	(
		run_tick >> "$LOG_FILE" 2>&1
		RC=$?
		[ "$RC" -ne 0 ] && log_line "tick falhou (exit $RC)" >> "$LOG_FILE"
	) &
	sleep 15
done
wait
