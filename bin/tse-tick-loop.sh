#!/bin/sh
# Disparado pelo cron do host a cada minuto; dentro do minuto, chama o tick
# a cada 15s para não depender do WP-Cron por tráfego durante a apuração ao vivo.
#
# Configuração via variáveis de ambiente:
#   TSE_TICK_CONTAINER   Nome do container Docker onde o WordPress roda.
#                         Se vazio, o script chama o PHP diretamente no host (sem Docker).
#   TSE_TICK_PLUGIN_PATH Caminho do arquivo bin/tse-tick.php.
#                         Dentro do container, caminho do WordPress lá dentro
#                         (ex.: /var/www/html/wp-content/plugins/tse-apuracao/bin/tse-tick.php).
#                         Fora do container, caminho absoluto no host.
#   TSE_TICK_LOG_FILE    Arquivo de log. Padrão: tse-tick.log ao lado deste script.
#   TSE_TICK_PHP_BIN     Binário do PHP. Padrão: php.
#
# Exemplos:
#   Com Docker:
#     TSE_TICK_CONTAINER=meu-container \
#     TSE_TICK_PLUGIN_PATH=/var/www/html/wp-content/plugins/tse-apuracao/bin/tse-tick.php \
#     ./tse-tick-loop.sh
#
#   Sem Docker:
#     TSE_TICK_PLUGIN_PATH=/caminho/para/wp-content/plugins/tse-apuracao/bin/tse-tick.php \
#     ./tse-tick-loop.sh

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

CONTAINER="${TSE_TICK_CONTAINER:-}"
PLUGIN_PATH="${TSE_TICK_PLUGIN_PATH:-$SCRIPT_DIR/tse-tick.php}"
LOG_FILE="${TSE_TICK_LOG_FILE:-$SCRIPT_DIR/tse-tick.log}"
PHP_BIN="${TSE_TICK_PHP_BIN:-php}"

for OFFSET in 0 15 30 45; do
	if [ -n "$CONTAINER" ]; then
		docker exec "$CONTAINER" "$PHP_BIN" "$PLUGIN_PATH" >> "$LOG_FILE" 2>&1 &
	else
		"$PHP_BIN" "$PLUGIN_PATH" >> "$LOG_FILE" 2>&1 &
	fi
	sleep 15
done
wait
