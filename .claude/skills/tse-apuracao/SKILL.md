---
name: tse-apuracao
description: Conceito e funcionamento do plugin WordPress "TSE Apuração", que publica apuração eleitoral ao vivo a partir dos arquivos oficiais do TSE (EA11, EA20, Dados Abertos). Use quando o plugin existir no WordPress em volta (pasta tse-apuracao / arquivo tse-apuracao.php) e for preciso entendê-lo, configurá-lo, depurá-lo, estendê-lo ou publicar apuração com ele; ou ao integrar qualquer coisa com EA11/EA20, shortcodes [tse_apuracao]/[tse_apuracao_card]/[apuracao_candidatos], fila ae_jobs ou o cron tse-tick-loop.
---

# TSE Apuração — a ideia do plugin

Esta skill descreve **o plugin em si**, independente do site, tema ou infraestrutura em volta. O único pressuposto é que o ambiente seja **WordPress** (PHP, MySQL, WP-Cron, REST API).

## Onde esta skill vive

A fonte é o **repositório do plugin**, em `.claude/skills/tse-apuracao/`, e ela acompanha o código: quem clona o plugin recebe a skill da mesma versão. Para usá-la em qualquer projeto, instale-a como skill global com `.claude/install-skill.sh` (copia para `~/.claude/skills`; `--link` cria um link simbólico). Depois de atualizar o plugin, rode o instalador de novo. Ao mudar o comportamento do plugin, atualize esta skill no mesmo commit, para ela não ficar para trás.

## Primeiro passo: localizar o plugin no projeto

1. Procure `tse-apuracao.php` (cabeçalho `Plugin Name: TSE Apuração`), normalmente em `wp-content/plugins/tse-apuracao/`. Pode ser pasta comum ou submódulo git.
2. Se existir, **leia o `README.md` e o `DOCUMENTACAO-PLUGIN-APURACAO.md` do próprio plugin**: são a fonte de verdade da versão que está clonada e podem estar mais novos que esta skill. Em caso de conflito, o código e a doc do plugin vencem.
3. Veja a versão no cabeçalho do plugin e o que há em `includes/`, `bin/`, `blocks/`, `templates/`, `tests/`.
4. Se não existir, o plugin precisa ser trazido para `wp-content/plugins/` antes (clone do repositório do plugin) e ativado.

Não assuma nomes de container, caminhos, UF, domínio ou ambiente: descubra pelo projeto (docker-compose, `wp-config.php`, crontab, CI) o que está em uso.

## O problema que ele resolve

Em noite de eleição milhares de leitores abrem a página ao mesmo tempo e o TSE limita acesso por IP. O plugin resolve isso com uma regra simples:

> **O servidor WordPress coleta do TSE. O leitor só consulta o WordPress.**

Nenhum navegador fala com o TSE. O servidor guarda **snapshots imutáveis** de cada disputa e a REST do WordPress serve o último snapshot válido, com cache de borda.

## Fluxo

```text
Dados Abertos (CSV zip) ─► import_candidates ─► ae_candidates

EA11 (catálogo) ─► sync ─► eleições + disputas + fontes EA20
                                     │
EA20 (resultado por cargo×UF) ─► HTTP condicional ─► validação/normalização
                                     │
                  falha ─► retry/log ├─► snapshot bruto + SHA-256 (imutável)
                                     ▼
                           REST do WordPress (+ cache/CDN)
                                     ▼
                  bloco / shortcode no navegador (polling)
```

Uso típico, do zero (tudo no admin, menu **Apuração**):

1. Ativar o plugin (tabelas e agendamento nascem sozinhos).
2. **Configuração:** escolher ambiente **Oficial** ou **Simulado**, informar a **UF deste site** e clicar em *Sincronizar configuração do TSE*. Só ficam ligadas as disputas daquela UF + Presidente (nacional); as demais nascem desligadas.
3. *(Opcional)* **Seleção de disputas** para ajuste fino.
4. **Importar e coletar:** *Buscar e importar candidatos*. Baixa o ZIP nacional, mas processa só o CSV da UF e os cargos ligados, e **só importa titulares** (Presidente, Governador, Senador, Deputados); vice e suplentes ficam de fora. Cada candidato é vinculado à disputa (`contest_id`) por cargo + UF + turno do CSV.
5. Publicar `[tse_apuracao cargo="governador" uf="es"]`, `[tse_apuracao_card ...]` ou `[apuracao_candidatos]`.
6. Garantir que a coleta rode de forma regular (ver Operação).

O operador nunca cola URL: elas nascem dos códigos do EA11.

## Fontes do TSE

| Fonte | Papel |
| --- | --- |
| **EA11** `/oficial/comum/config/ele-c.json` | catálogo: ciclo, pleito, eleições, abrangências, cargos. Ciclo, pleito e códigos **nunca são fixos no código** |
| **EA20** `{host}/{ambiente}/{ciclo}/{eleicao}/dados/{uf}/{uf}-c{cargo4}-e{eleicao6}-u.json` | resultado unificado por cargo × UF |
| Dados Abertos `consulta_cand_{ANO}.zip` (cdn.tse.jus.br) | cadastro de candidatos, nacional, um CSV por UF + BR/BRASIL |
| EA14 / EA15 | acompanhamento Brasil/UF. **Não implementados**; só seriam necessários para mapas municipais |

Hosts: oficial `resultados.tse.jus.br`; simulado `resultados-sim.tse.jus.br`. Só `*.tse.jus.br` em HTTPS é aceito. Antes de o TSE publicar a eleição, o EA11 oficial responde com o ciclo anterior; a sincronização falha de propósito avisando isso, e o botão Simulado fica bloqueado até a URL existir (evita 404 e bloqueio de IP).

## Contrato EA20 (campos usados)

Percurso `carg[] → agr[] → par[] → cand[]`.

| Finalidade | Campo |
| --- | --- |
| candidato | `sqcand`, `n`, `nm`, `nmu` |
| partido | `par.sg` |
| votos / % | `cand.vap`, `cand.pvap` |
| eleito / situação | `cand.e`, `cand.st` |
| andamento / final | `and` (n/p/f), `tf`, `md` |
| seções | `s.ts`, `s.st`, `s.pst` |
| totais | `v.tv`, `v.vv`, `v.vb`, `v.vn`, `v.van` |
| geração | `dg`, `hg`, `idg` |

"Eleito" vem **só** de `cand.e`, nunca de posição ou percentual.

## Quirks do TSE (já descobertos ao vivo)

- `cand.e = "s"` também aparece para quem só vai ao **2º turno**. Nunca marcar eleito se `cand.st` contém "turno". Usar `mb_stripos`, não regex com `[ºo°]` (o "º" é multibyte).
- `and` e `tf` podem **discordar** (ex.: `and="n"` com `tf="s"`). A interface deriva status só de `and`; consumidores externos não devem confiar só em `totals.final`.
- O `seq` do Senado é a ordem oficial do TSE, não necessariamente por votos.
- Votos **anulados, brancos e nulos** (`van`/`vb`/`vn`) são relevantes (já passaram de 8%); sem eles o total não fecha.
- Vagas do Senado variam por ciclo (1/3 ou 2/3 renovado). `seats` é só metadado e não influencia quem é eleito.
- O simulado publica casos extremos de propósito (nomes com aspas e símbolos, ranking fora de ordem). Sanitizar tudo.
- No CSV de candidatos, a situação da candidatura pode vir `#NE` (não divulgado) e a totalização `#NULO`; nesse caso o cadastro não diz quem foi indeferido, e quem define é o EA20. O CSV também não traz URL de foto: ela é derivada de `sqcand` quando o EA20 chega.
- O CSV de candidatos é regenerado pelo TSE com frequência (renúncias, substituições): reimportar perto da eleição.

## Proteções de acesso (o TSE bloqueia por IP)

Limite do TSE: 100 req/s por IP, bloqueio de 10 min ao exceder; 304 e 404 repetidos contam. O plugin: teto de 20 req/s, `If-None-Match`/`If-Modified-Since`, SHA repetido não cria versão, circuit breaker de 10 min em 403/429, **404 com backoff por fonte (10 min a 6 h)** sem desligar a disputa, retry exponencial, e **sempre serve o último snapshot válido** em caso de falha.

## Banco

`ae_elections`, `ae_contests`, `ae_candidates` (`contest_id` = disputa de referência, a do menor turno), `ae_candidate_contests` (todas as disputas do candidato, 1º e 2º turno), `ae_snapshots` (imutáveis), `ae_result_rows` (ranking materializado; guarda nome/partido/número direto para sobreviver se `ae_candidates` estiver vazio), `ae_jobs` (fila com lock, tentativas e cursor), `ae_logs`.

## Superfície pública

- Shortcodes: `[tse_apuracao cargo= uf= turno= limite= atualizar=]`, `[tse_apuracao_card cargo= limite= titulo= classe=]`, `[tse_apuracao_resumo disputas="cargo:uf[:turno],..." titulo= link= link_texto= atualizar= classe=]` (widget simples para a home: uma linha por disputa, até 8), `[apuracao_candidatos]`, `[apuracao_candidato]`, `[apuracao_navegacao]`, `[apuracao]` (legado). Bloco Gutenberg `blocks/apuracao`. Se o bloco não aparecer no editor, o shortcode clássico no conteúdo funciona igual.
- REST: `GET /wp-json/apuracao/v1/results/{eleicao}/{turno}/{cargo}/{abrangencia}`, `GET /apuracao/v1/candidates/{eleicao}/{id}`, compatibilidade `GET /tse/v1/resultado?cargo=&uf=&turno=`. Ambos os endpoints de resultado mandam `Cache-Control` público, ETag e 304, para a borda (CDN) poder guardar.
- **Catálogo paginado:** `[apuracao_candidatos por_pagina="24"]` (1 a 200; filtro `ae_catalog_per_page`) mostra 24 por página, com contador real ("1.487 candidatos encontrados · mostrando 1–24") e navegação Anterior/1 2 3/Próxima **só quando há mais de uma página**. Parâmetro de URL `ae_pagina` (não `paged`, que o core trata como arquivo); filtros `ae_busca`, `ae_cargo`, `ae_uf`, `ae_partido`. Buscar e trocar de página levam à âncora `#ae-catalog-lista`. Ordem `ballot_name, id` (o `id` desempata homônimos).
- Front: `assets/js/tse-live.js` faz polling na REST local e revalida ao voltar a aba ao primeiro plano. Rótulo em 3 estados: *Ao vivo*, *Apuração concluída*, *Dados atrasados*. Os assets carregam sempre, não só em página singular, senão home/listagens ficam sem estilo.

## Operação: o que quebra em silêncio

- **WP-Cron por tráfego não é confiável em dia de eleição.** O plugin traz um disparo independente: `bin/tse-tick-loop.sh` chama `bin/tse-tick.php` a cada 15 s (via cron de sistema), que roda `AE_Job_Runner::tick()` com orçamento de ~40 s e lock. `tse-tick.php` só roda em CLI.
- **Pegadinha do crontab:** o `crontab` não herda variáveis do shell. As variáveis do loop (`TSE_APURACAO_CONTAINER`, `TSE_APURACAO_PLUGIN_PATH`, `TSE_APURACAO_LOG_FILE`) devem estar declaradas **dentro do arquivo do crontab**. Sem isso o script cai no branch "PHP local" e falha com `php: not found`, sem alerta, apenas parando de coletar. Sempre confirmar que o log está *crescendo*, não só que o container/serviço está de pé.
- **Aviso de cron parado:** o worker registra batimentos e separa os que vêm de CLI (cron de sistema) dos do WP-Cron, porque o WP-Cron por tráfego mantém "algum tick" fresco mesmo com o cron de sistema morto. `AE_Job_Runner::tick_status()` devolve `stale`, `cli_stopped` e `cli_never`; a Visão geral mostra "Último tick" e avisos, e a REST de saúde (`apuracao/v1/admin/health`) traz o campo `tick`. O plugin **não** envia alertas para nenhum serviço externo: quem quiser alerta consome a REST de saúde.
- **Intervalo por tipo de disputa (regra do plugin).** Coleta de Deputado Federal/Estadual/Distrital tem centenas de candidatos por UF e pode levar minutos num worker sequencial, atrasando todas as outras. Por isso, **só quando há mais de uma UF ligada** (o Presidente é nacional e não conta): majoritárias (Presidente, Governador, Senador, Prefeito) ficam em 60 s e vão primeiro na fila; deputados passam a 120 s (filtro `ae_heavy_interval`) e esperam 5 s na fila. Com uma UF só, tudo é 60 s. O intervalo vive em `config_json.collection.interval`; `interval_mode` é `auto` (a regra recalcula quando as UFs ligadas mudam) ou `manual` (ajuste do admin na Seleção de disputas, nunca sobrescrito, inclusive no re-sync do EA11). Disputas antigas sem `interval_mode` valem como automáticas se o valor for 60 s ou o das pesadas, e como manuais em qualquer outro valor. A aba Seleção de disputas explica a regra e tem a coluna "Atualiza a cada".
- **"Dados atrasados" é proporcional ao intervalo:** `max(3 min, 3 × intervalo da disputa)`. Com intervalos maiores, um limiar fixo mostraria atraso falso.
- **O que a regra não resolve:** um job pesado já em andamento segura o lock do worker até acabar (o orçamento de 40 s só é checado entre jobs). Melhoria futura: tornar a coleta pesada mais barata (inserção em lote, pular candidato sem mudança).
- **Snapshot é gravado numa transação** (snapshot + ranking), para uma interrupção no meio não deixar um snapshot parcial sendo servido. Exige tabelas InnoDB. A Visão geral e a REST de saúde (`non_innodb_tables`) avisam se alguma tabela `ae_*` não é InnoDB. A gravação é em lote (poucas queries por snapshot, mesmo com mais de mil candidatos); `ae_candidates.updated_at` só muda quando o dado muda.
- **Candidato que sai do CSV (2.5.0):** a importação marca `ae_candidates.removed_at` em quem veio de um CSV anterior, está no escopo e não apareceu na importação nova (renúncia, substituição). Não apaga; o catálogo mostra "Não consta na última lista do TSE", e a marca some se o candidato reaparecer. Importação sem nenhuma linha lida nunca marca ninguém.
- **Última importação e reimportação agendada (2.5.0):** a opção `ae_last_import` guarda quando, quantas linhas, o escopo e a geração do CSV (`DT_GERACAO`/`HH_GERACAO`); aparece na aba Importar e coletar. A reimportação automática é opt-in (`ae_auto_import_election`), a cada 6 h (`ae_auto_import_interval`), só com disputa ligada (nunca o Brasil inteiro) e prende o worker durante o download do ZIP: desligar na noite da eleição.
- **Avisos que evitam parada silenciosa (2.5.0):** disputas da UF do site (`ae_site_uf`) desligadas aparecem num aviso na Visão geral e na Seleção de disputas. O container Docker pode não ter `php-zip` (a importação do ZIP não roda nele); a REST de saúde informa `zip_available`.
- **Turnos e medição (2.6.0):** o mesmo candidato disputa o 1º e o 2º turno (disputas diferentes). `ae_candidates.contest_id` guarda só a disputa de referência (menor turno; o EA20 do 2º turno não a move) e `ae_candidate_contests` guarda todas. `AE_Perf` mede o download ao TSE, a coleta de uma disputa e o lock por tick (Visão geral e `perf` em `/admin/health`): olhe o p95 do `fetch` na primeira hora; abaixo de ~0,4 s não há nada a fazer, acima de ~0,6 s o worker satura no cenário nacional. A origem da REST aguenta ~5 req/s por WordPress: cache de página ou CDN na frente é obrigatório.
- **Três branches de PHP (2.5.0):** `main` (8.1+), `php7.4` e `php7.2` (PHP 7.2+, WordPress 4.9+). A `php7.2` é gerada da `php7.4` por `tools/port-php72.py` (sem arrow functions, `??=`, propriedades tipadas nem `JSON_THROW_ON_ERROR`); `includes/compat.php` traz polyfills (`str_*`, `wp_date`) e o bloco só registra em WordPress 5.5+. Ao escrever código novo, evite o que o script não converte e rode o lint no 7.2.
- **Manual no admin (2.5.0):** a aba *Como usar* (`includes/class-admin-guide.php`) documenta os shortcodes, atributos, exemplos copiáveis e o diagnóstico "não aparece"; ao mudar um shortcode ou atributo, atualize-a (o teste falha se um shortcode registrado ficar sem documentação).
- **Testes em WordPress real:** `tests/run-in-docker.sh` roda `wp-integration.php` (admin, importação com CSV sintético, persistência em lote, rollback), `wp-collect.php` (coleta ponta a ponta com um TSE falso via `pre_http_request`: zerado/parcial/final/2º turno, 304, 404, 429, JSON inválido, REST e shortcodes) e `http-admin.sh` (POSTs reais do admin com cookie e nonce). Restauram o que alteram. O TSE falso segue o formato documentado do EA20; não substitui uma coleta real.
- Escala: numa eleição geral há ~109 disputas (1 Presidente + 27 × Governador, Senador, Deputado Federal, Deputado Estadual/Distrital). O gargalo é a regularidade do disparo e o peso das coletas de Câmara, não a contagem de disputas.
- A extensão `php-zip` (`ZipArchive`) é **requisito de ativação** (2.5.1): `AE_Plugin::missing_requirements()` recusa a ativação com mensagem na tela, o admin mostra aviso permanente se ela sumir e a REST de saúde traz `zip_available` e `missing_requirements`. Dispensa só para dev/teste: constante `TSE_APURACAO_ALLOW_NO_ZIP` ou filtro `ae_missing_requirements`.
- Cache de página (ex.: FastCGI) com bypass por query string não afeta o polling, só o HTML inicial. Cache de objeto (Redis/Memcached) e CDN são complementares, não substitutos.
- Se o plugin for submódulo git de um site, alterá-lo exige commit no repo do plugin **e** commit do novo ponteiro no repo do site.

## Funcionalidade nova "não aparece" no site (diagnóstico)

Antes de depurar o código, confira nesta ordem: (1) **versão** do plugin no site (cabeçalho de `tse-apuracao.php`) contra a que trouxe a funcionalidade; (2) se o plugin é **submódulo**, o ponteiro no repositório do site foi atualizado; (3) a **branch** em uso (a principal exige PHP 8.1+; existe uma variante para PHP 7.4 que recebe as mesmas correções); (4) **cache** de página/CDN servindo HTML antigo ou ignorando a query string (`ae_pagina`, `ae_busca`…); (5) CSS minificado/antigo ou tema sobrescrevendo; (6) a funcionalidade tem **condição de exibição** (ex.: paginação só com mais de uma página). Um `curl` na URL procurando a classe do elemento (`ae-catalog-pagination`) separa "HTML errado" de "CSS/tema".

## Como agir ao ser acionada

- **"Configurar/usar":** localizar o plugin, ler a doc dele, seguir o fluxo acima, descobrir ambiente real (container, crontab, UF) em vez de supor.
- **"Atrasado/parado":** olhar `ae_jobs` (fila e erros), `ae_logs`, idade do último snapshot, `ae_tse_blocked_until`, e se o log do tick está crescendo.
- **"Número/status errado":** comparar o `raw_json` do snapshot com os quirks acima antes de culpar o parser.
- **"Estender":** manter a regra central (leitor nunca fala com o TSE; snapshot imutável) e atualizar a doc do plugin junto com qualquer mudança de fonte, contrato, cache, fila ou admin.

Detalhes de arquivos, normalização e testes: `references/detalhes-tecnicos.md`.
