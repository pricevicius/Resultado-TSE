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

run_tick() {
	if [ -n "$TSE_APURACAO_CONTAINER" ]; then
		docker exec "$TSE_APURACAO_CONTAINER" php "$PLUGIN_PATH/bin/tse-tick.php"
	else
		php "$PLUGIN_PATH/bin/tse-tick.php"
	fi
}

for OFFSET in 0 15 30 45; do
	run_tick >> "$LOG_FILE" 2>&1 &
	sleep 15
done
wait
