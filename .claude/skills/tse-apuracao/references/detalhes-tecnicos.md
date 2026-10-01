# TSE Apuração — detalhes técnicos

Complemento do `SKILL.md`. Os nomes abaixo são do plugin; confirme no código clonado, que pode ter evoluído.

## Mapa de arquivos esperado

| Arquivo | Papel |
| --- | --- |
| `tse-apuracao.php` | cabeçalho, constantes, bootstrap |
| `includes/class-tse-client.php` | HTTP, importação de candidatos, `collect_results`, `normalize_result`, `persist_result`, rate limit, circuit breaker |
| `includes/class-tse-discovery.php` | sincronização do EA11: eleições, disputas, fontes EA20 |
| `includes/class-job-runner.php` | fila `ae_jobs`, `tick()`, lock, retry, limpeza de jobs antigos |
| `includes/class-admin.php` | telas do menu Apuração e escopo de importação a partir das disputas ligadas |
| `includes/class-rest.php` | `apuracao/v1/*`, `Cache-Control`, ETag/304 |
| `includes/class-tse-shortcode.php` | `[tse_apuracao]`, `[tse_apuracao_card]`, `tse/v1/resultado` |
| `includes/class-shortcodes.php` | `[apuracao]`, `[apuracao_candidato(s)]`, `[apuracao_navegacao]` |
| `includes/class-candidate-catalog.php` | catálogo e ficha de candidatos |
| `includes/class-results.php` | leitura do último snapshot (cache de objeto) |
| `includes/class-collection-policy.php` | regra de intervalo por tipo de disputa, `interval_mode` auto/manual, `apply_all()`, limiar de "atrasado" |
| `includes/class-schema.php` | criação e migração das tabelas (inclui `ae_candidate_contests`) |
| `includes/class-perf.php` | amostras de tempo do download, da coleta e do tick (`AE_Perf`) |
| `bin/tse-tick.php`, `bin/tse-tick-loop.sh` | disparo independente do WP-Cron (CLI) |
| `assets/js/tse-live.js`, `assets/css/tse-apuracao.css` | polling ao vivo e estilo |
| `templates/card.php` | markup do card compacto |
| `blocks/apuracao` | bloco Gutenberg |
| `tests/` | smoke tests, fixtures `ea20-zero/final/minimal.json`, carga k6 |

## Importação de candidatos

- Baixa o ZIP nacional uma vez para arquivo temporário e processa por streaming em lotes de 250 linhas, com cursor (arquivo + linha) para retomar.
- Processa só o CSV da UF das disputas ligadas (e `BR`/`BRASIL` se Presidente estiver ligado) e filtra linha a linha por `CD_CARGO`. Sem nenhuma disputa ligada, cai para o Brasil inteiro.
- **Só titulares** (`CD_CARGO` 1, 3, 5, 6, 7, 8), sempre, com ou sem escopo. Vice (2, 4) e suplentes (9, 10) não entram (veja `HOLDER_POSITIONS` em `class-tse-client.php`).
- Upsert por eleição + `SQ_CANDIDATO`. **Nunca apaga** quem saiu do CSV.
- Campos usados: `SQ_CANDIDATO`, `NM_URNA_CANDIDATO`, `NM_CANDIDATO`, `NR_CANDIDATO`, `SG_PARTIDO`, `DS_SITUACAO_CANDIDATURA`; a linha inteira vai em `data_json`.
- Colunas úteis do CSV: `DT_GERACAO`, `HH_GERACAO`, `SG_UF`, `CD_CARGO`, `DS_CARGO`, `NM_COLIGACAO`, `DS_COMPOSICAO_COLIGACAO`, `SG_FEDERACAO`, `DS_SIT_TOT_TURNO`.
- **Vínculo com a disputa:** a importação preenche `contest_id` por cargo + UF + turno (Presidente = `BR`; Dep. Distrital = disputa `0008` do DF), com cache por execução. Reimportar preenche os já existentes, e a importação nunca apaga um vínculo que o EA20 já gravou. Sem esse vínculo, os filtros de cargo/UF do catálogo só funcionariam depois que o EA20 chegasse.
- Para conferir a base de uma UF, compare a contagem de `ae_candidates` com as linhas do CSV daquela UF e a data de geração do CSV com a última importação.

## Normalização do EA20

- `elected` vem só de `cand.e`, exceto 2º turno (`$runoff`). Sem `e`, cai para `st` começando com "ELEITO".
- `rank` = `seq`/`posicao` do TSE; sem eles, índice no array.
- `progress`: `n` → `not_started`, `p` → `partial`, `f` → `final`. `final` é verdadeiro se `and = f` ou `tf = s`.
- Snapshot é rejeitado se faltar estrutura essencial; a API segue servindo o anterior.
- Cenário zerado (`v.tv=0`, `s.st=0`, `and=n`) é válido e mostra "Aguardando apuração".
- Payload do shortcode inclui `votos_anulados`, `votos_brancos`, `votos_nulos`, percentuais correspondentes e `segundo_turno` por candidato.

## Autonomia operacional

- Lock nomeado no MySQL compartilhado entre WP-Cron, CLI e cron de sistema, mesmo sem Redis/Memcached.
- Jobs presos em `running` após queda de processo são recuperados quando o lock expira.
- Resposta 304 registra "última consulta bem-sucedida", então parcial estável não aparece como "Dados atrasados".
- O plugin só considera um snapshot "atrasado" pela idade da última consulta bem-sucedida, não pela mudança de conteúdo. O limiar é `max(3 min, 3 × intervalo da disputa)`.
- Fila: `enqueue_due_collections` enfileira as leves antes das pesadas; com mais de uma UF as pesadas ganham `run_after` +5 s. A fila atende por `run_after` e `id`.
- `persist_result` grava snapshot e ranking em `START TRANSACTION`/`COMMIT`; falha em qualquer inserção dá `ROLLBACK` e o job tenta de novo.
- Saúde do disparo: `ae_last_tick_at`, `ae_last_tick_source` e `ae_last_cli_tick_at` (limites pelos filtros `ae_tick_stale_seconds` e `ae_cli_tick_stale_seconds`).

## Testar em WordPress real (Docker)

Subir MariaDB + `wordpress:php8.2-apache` (ou `php7.4-apache`) numa rede Docker, montar o plugin em `wp-content/plugins/tse-apuracao`, baixar o `wp-cli.phar`, instalar o core e ativar o plugin. Inserir linhas em `ae_elections`, `ae_contests` e `ae_candidates` e renderizar com `wp eval 'echo do_shortcode("[apuracao_candidatos]");'`, simulando filtros em `$_GET`. As telas do admin (`AE_Admin::tab_*`, privadas) podem ser chamadas por Reflection com `wp_set_current_user(1)`. Para exercitar o worker sem tocar no TSE, defina `ae_tse_blocked_until` no futuro: `tick()` registra o batimento e retorna antes de qualquer requisição. O roteiro completo está na documentação do plugin. Só lint e stubs não bastam: a paginação, por exemplo, depende de `paginate_links` do core.

## Testes

- `tests/*-smoke.php`: contrato, setup, admin, EA11 ao vivo.
- Carga com k6 (`grafana/k6` via Docker, `--network host`) contra `apuracao/v1/results/...`. Meta: p95 < 400 ms e erro < 1% com 200 usuários virtuais. Isso mede a REST servindo snapshot em cache, **não** a coleta sob concorrência (o que é um teste à parte).
- Em simulados do TSE, valide: zero inicial, parcial contra o portal oficial, 100% (`and`, `tf`, `md`), 2º turno sem marcar eleito, 304 funcionando, nenhum bloqueio de IP, e que o navegador não acessa domínio do TSE.

## Portabilidade

- Sem marca de publisher no código; `bin/tse-tick-loop.sh` é parametrizado por variáveis de ambiente.
- Ambiente precisa de `php-zip`. PHP 8.x na branch principal; existe variante para PHP 7.4 em branch própria do repositório do plugin.
- Funciona em qualquer WordPress, em Docker ou em servidor tradicional (nesse caso o cron de sistema roda o PHP local).

## Ideias não implementadas

- EA14 detecta UFs alteradas → EA15 só nelas → EA20 só nos municípios/cargos alterados → EA12 liga código TSE ao IBGE → REST geográfica compacta (mapas).
- Cache próprio de fotos, páginas individuais de candidato, atualização programada 4×/dia.
- Validação da assinatura X.509 dos JSON, monitor de mudança de contrato EA11/EA20, alertas externos de fila/bloqueio/atraso, política de retenção de snapshots.
