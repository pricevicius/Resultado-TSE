#!/bin/sh
# Disparado pelo cron do host a cada minuto; dentro do minuto, chama o tick
# a cada 15s para não depender do WP-Cron por tráfego durante a apuração ao vivo.
for OFFSET in 0 15 30 45; do
	docker exec tribuna_espiritosanto-app php /var/www/html/wp-content/plugins/tse-apuracao/bin/tse-tick.php \
		>> /home/price/jobs/tribunaonline/tse-tick.log 2>&1 &
	sleep 15
done
wait
