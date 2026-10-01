# TSE Apuração — como testar

Tudo roda em WordPress real (Docker), sem rede para o TSE (o TSE é falso e interceptado). Cada suíte restaura o que altera. Os comandos de uso diário estão em [../PENDENCIAS.md](../PENDENCIAS.md) (seção E).

## Testes (2.7.0)

| Comando | O que faz |
| --- | --- |
| `tests/run-in-docker.sh` | as suítes anteriores mais `tests/wp-latency.php` (custo local e projeção) e `tests/wp-import-zip.php` (ignorado sem `php-zip`) |
| `tests/run-with-zip.sh` | `wp-import-zip`, `wp-integration` e `wp-collect` num container descartável com `php-zip` |
| `tests/run-concurrency.sh` | 4 workers na mesma fila; `TSE_CONC_WORKERS` muda a quantidade |
| `node tests/load/results-node.js <url> [conexões] [segundos] [etag] [bust]` | carga na REST sem k6 |
| `tests/run-matrix.sh` | as suítes em PHP 7.4 e 7.2 (containers descartáveis, MariaDB próprio) |


## Testes em WordPress real

`tests/run-in-docker.sh` roda três suítes no container do site (sem PHPUnit) e sai com código diferente de
zero se alguma falhar. Todas restauram o que alteram.

| Suíte | O que cobre |
| --- | --- |
| `tests/admin-smoke.php` e `tests/wp-integration.php` | abas do admin, avisos de UF e InnoDB, importação de candidatos com CSV sintético (UF fictícia `ZZ`), marcação e retorno de removidos, agendamento, `persist_result` em lote, snapshot idempotente e rollback da transação com falha forçada |
| `tests/wp-collect.php` | coleta ponta a ponta com um **TSE falso** (`pre_http_request`, UF fictícia `ZY`, nenhuma requisição real): zerado, parcial, final, 2º turno, divergência `and`/`tf`, 304, 404 com backoff, 429 com pausa, 500, JSON inválido; REST, shortcode e card |
| `tests/e2e/turno-browser.js` | **navegador real** (Chromium headless por CDP, sem dependências): cria uma disputa fictícia (`EY`) com `tests/e2e/turno-fixture.php`, abre uma página com blocos repetidos (apuração ×2, cards, resumo e faixa), avança o 2º turno no banco e confere a virada sozinha (selo, finalistas, seletor, card que some), o clique no seletor sem recarregar, a requisição compartilhada entre blocos iguais, ausência de erro de JS e `?ae_turno=1`. Remove a massa no fim. Uso: `node tests/e2e/turno-browser.js` (usa o Chromium do Playwright ou `CHROME=`) |
| `tests/wp-kick.php` | `kick()` sem WP-Cron: com a fila vazia cria e executa a coleta devida, respeita o intervalo mínimo, a checagem espaçada e o breaker do TSE; `run_now()` e `loopback_status()`. TSE falso (UF fictícia `ZY`); restaura a configuração das disputas ao final |
| `tests/http-admin.sh` | POSTs reais do admin por HTTP (cookie de administrador e nonce lidos da página): reimportação automática e Seleção de disputas, com nonce inválido, sem login e restauração de `ae_contests` |

Variáveis: `TSE_APURACAO_CONTAINER` (padrão `revistaforum-app`), `TSE_WP_PATH`, `TSE_PLUGIN_REL`, `TSE_SITE_URL`.
O TSE falso só reproduz o formato documentado do EA20; ele não substitui uma coleta contra o TSE real
(rede, bloqueio por IP). O `tests/bootstrap.php` do PHPUnit agora carrega `tse-apuracao.php`, mas o PHPUnit
ainda exige a biblioteca de testes do WordPress (`WP_TESTS_DIR`), que não está instalada neste ambiente.


## Como testar em WordPress real (Docker)

Sem depender do site de produção. Precisa só de Docker.

```bash
docker network create tse-test
docker run -d --name tse-db --network tse-test -e MARIADB_ROOT_PASSWORD=x -e MARIADB_DATABASE=wp mariadb:10.11
docker run -d --name tse-wp --network tse-test \
  -e WORDPRESS_DB_HOST=tse-db -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=x -e WORDPRESS_DB_NAME=wp \
  -v "$PWD":/var/www/html/wp-content/plugins/tse-apuracao:ro wordpress:php8.2-apache   # php7.4-apache para a branch php7.4
docker exec tse-wp sh -c 'curl -sL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
docker exec tse-wp wp --allow-root core install --url=http://localhost --title=T --admin_user=a --admin_password=a --admin_email=a@a.com --skip-email
docker exec tse-wp wp --allow-root plugin activate tse-apuracao
```

Depois insira candidatos de teste em `wp_ae_candidates` (com `election_id`, `contest_id` e
`ballot_name`) e renderize com `wp eval 'echo do_shortcode("[apuracao_candidatos]");'`
(simule filtros com `$_GET['ae_uf']='SP'; $_GET['ae_pagina']=2;`). Verificações feitas na 2.4.1,
com 100 candidatos de SP (incluindo homônimos) e 3 do ES:

| Caso | Resultado |
| --- | --- |
| páginas 1 a 5 | 24, 24, 24, 24 e 4 cards; 100 ids únicos de 100 (sem repetir nem pular) |
| contador | "100 candidatos encontrados · mostrando 1–24"; última página "97–100" |
| `ae_pagina=99` / `0` / `abc` | última / primeira / primeira página |
| conjunto pequeno (3, 4 e 10 resultados) | sem navegação |
| nenhum resultado | "Nenhum candidato encontrado", sem navegação |
| `por_pagina="10"` | 10 cards e páginas 1 a 10; `por_pagina="9999"` limita a 200 |
| cargo + partido + página 2 | filtros mantidos nos links de página |
| links | terminam em `#ae-catalog-lista` |
