# TSE Apuração — histórico por versão

O que mudou em cada versão e por quê, da mais nova para a mais antiga. Comportamento permanente fica em [arquitetura.md](arquitetura.md); como validar, em [testes.md](testes.md). O que ainda está em aberto, em [../PENDENCIAS.md](../PENDENCIAS.md).

## Versão 2.6.1 — a coleta arranca sem WP-Cron

**Problema.** Em homologação (e em qualquer servidor que não consegue chamar o próprio endereço público, por NAT/hairpin) o
WP-Cron nunca dispara: `wp cron test` dá *spawn failed: Connection timed out*. O plano B do plugin, `AE_Job_Runner::kick()`
(chamado no `shutdown` do REST e do shortcode), só **drenava jobs que já existiam**. Quem cria os jobs `collect_results` é
`enqueue_due_collections()`, que só o `tick()` chamava. Resultado: seleção salva, fila vazia, `kick()` saindo cedo e nenhuma
coleta, até alguém rodar o cron na mão (aconteceu no revistaforum e no eptv).

**Correção.**
- `kick()` com fila vazia também enfileira as coletas devidas (`enqueue_due_collections()`), no máximo a cada 15 s
  (`ae_last_kick_enqueue_at`) para não consultar `ae_contests` a cada visita, com o mesmo lock e o mesmo breaker do TSE.
- **Salvar a Seleção** e **Sincronizar** passam a rodar o worker na hora (`AE_Job_Runner::run_now()`, até 20 s, sem derrubar a
  ação se falhar): a coleta começa no clique, sem esperar o cron. `tick()` ganhou o parâmetro opcional de orçamento (padrão 40 s,
  inalterado para WP-Cron e CLI).
- A tela do plugin aberta (GET, só admin) também chama o `kick()` no `shutdown`; o admin não passa por cache de página.
- **Diagnóstico:** a Visão geral testa uma requisição ao próprio `rest_url()` (cache de 10 min, `ae_loopback_status`) e, se
  falhar, avisa que o WP-Cron não dispara e mostra a linha de cron de sistema pronta (`wp cron event run --due-now`).

**O que não muda.** Para coletar continuamente sem ninguém olhando o site (dia da apuração), o cron de sistema
(`bin/tse-tick-loop.sh` ou a linha acima) continua sendo o recomendado; o `kick()` é o piso, não a substituição.

Teste: `tests/wp-kick.php` (K1–K8). K2 e K3 falham no código da 2.6.0 e passam na 2.6.1.

## Versão 2.6.0 — turnos, medição da coleta e testes de carga

**Esquema 2.1.0.** Nova tabela `ae_candidate_contests (candidate_id, contest_id)`, com chave primária nos dois campos. O
mesmo candidato disputa o 1º e o 2º turno, que são **disputas diferentes** (`ae_contests.round_no`), e `ae_candidates.contest_id`
só guarda uma. A regra agora é:

- `ae_candidates.contest_id` é a **disputa de referência**: a do menor turno. O EA20 do 2º turno **não** a move para o 2º
  turno (antes ela trocava de disputa a cada coleta do 2º turno e voltava a cada reimportação do CSV, que é do 1º turno).
  Só se move para corrigir uma ligação errada dentro do mesmo turno, ou quando o candidato ainda não tinha disputa.
  Candidato que só existe no 2º turno nasce ligado à disputa do 2º turno.
- `ae_candidate_contests` guarda **todas** as disputas do candidato. A importação do CSV e o EA20 gravam os vínculos em lote
  (`INSERT IGNORE`, uma query por 200 candidatos). A migração (`dbDelta`) cria a tabela sozinha e copia os vínculos que já
  existiam em `ae_candidates.contest_id`; é idempotente.
- O perfil do candidato mostra **"Turnos disputados: 1º e 2º turno"** quando há mais de um. O ranking, a REST e os shortcodes
  não mudam (leem `ae_result_rows` pela disputa do próprio turno). A limpeza do Simulado e o `uninstall.php` também tratam a tabela nova.

Por que fazer agora: com a eleição ainda sem coleta oficial, a tabela nasce junto com os candidatos importados, sem dado vivo
para migrar e com meses de folga antes do 2º turno (25/10). O custo é uma query a mais por 200 candidatos na coleta.

**Importação: linha duplicada por página.** A linha 251 de cada lote (usada só para saber que há mais páginas) era processada e
a seguinte página a lia de novo. Nenhum candidato duplicava (o upsert é idempotente), mas `ae_last_import.rows` saía maior que o
real (achado pelo teste do ZIP: 305 linhas para 304 candidatos). Corrigido.

**Medição da coleta (A10).** `AE_Perf` (`includes/class-perf.php`) guarda as últimas 300 amostras (opção `ae_perf_samples`):
tempo do download ao TSE (`fetch`, com o código HTTP), da coleta de uma disputa inteira (`job`) e do tempo que cada tick segura
o lock (`tick`, só os que trabalharam). A Visão geral mostra média, p95 e máximo, e a REST de saúde traz o campo `perf`. É o
dado que falta para decidir o intervalo de 120 s e o A2: aparece nas primeiras coletas oficiais.

**Simulado.** O caminho do simulado do TSE passou a ser filtrável (`ae_tse_simulation_path`, padrão `simulado/simulado2026`).
O atributo `eleicao` do bloco e do `[apuracao]` legado não é usado na renderização (o cargo e a UF decidem); o padrão
`eleicoes-2026` fica como está para não alterar blocos já salvos.

### Medições locais (2.6.0)

Docker local, sem rede (TSE falso), `tests/wp-latency.php`:

| Disputa | Custo local por coleta (decodificar, snapshot, ranking, vínculos) |
| --- | --- |
| leve (Governador, 8 candidatos) | ~50 ms |
| pesada (Deputado, 1.100 candidatos) | ~250 ms |

O teste também confirma o modelo "custo local + latência × requisições": 8 disputas com 150 ms de rede simulada deram 2,0 s
medidos contra 2,4 s previstos. A **única peça que não dá para medir fora do ar é a latência real do TSE**. Projeção para o
cenário nacional (27 UFs com Governador, Senador, Dep. Federal e Dep. Estadual, mais o Presidente: 55 disputas leves a cada
60 s e 54 pesadas a cada 120 s, tudo num worker só):

| Rede por requisição | Ocupação do worker |
| --- | --- |
| 0,10 s | 29% |
| 0,25 s | 50% |
| 0,50 s | 84% (apertado) |
| 1,00 s | 152% (a fila atrasa) |

O worker satura perto de **0,6 s por requisição** (70% de ocupação em ~0,4 s). Com poucas UFs ligadas a folga é enorme.
Regra prática para o dia: se o `p95` do `fetch` na Visão geral ficar abaixo de ~0,4 s, nada a mudar; entre 0,4 e 0,6 s, ligar
menos UFs de Câmara ou subir o intervalo das pesadas (`ae_heavy_interval`); acima disso, o A2 (duas pistas de worker) passa a valer.

**Origem da REST (WordPress inteiro por requisição).** Com `tests/load/results-node.js` (`bust=1`, que fura o cache de página),
a origem local atendeu **~4 a 5 req/s** em qualquer rota de resultado, leve ou pesada: o custo é o WordPress com todos os
plugins e o tema carregando, não a consulta. Com o cache de página do nginx local na frente, a mesma rota deu 120 a 640 req/s
(p95 abaixo de 110 ms para a rota leve). Uma requisição **condicional** (`If-None-Match`) não passa pelo cache do nginx local e
cai na origem: por isso o 304 aqui não é mais barato, só economiza bytes. Conclusão: sem cache de página ou CDN na frente, o
site não sustenta pico de leitores. O `Cache-Control` público (`s-maxage=60`, `stale-while-revalidate`) já é enviado.

**Concorrência do worker.** `tests/run-concurrency.sh`: 4 processos disputam 24 coletas de verdade (100 ms de rede simulada). O
resultado confere: cada job roda uma vez, 24 disputas dão 24 snapshots sem duplicata, o ranking vem completo, nenhum job é
tentado duas vezes e o lock do banco nunca foi segurado por dois processos ao mesmo tempo.

**ZIP real.** `tests/wp-import-zip.php` monta um ZIP de verdade (CSV em ISO-8859-1 por UF, Brasil à parte, vice, suplente, uma
UF fora da seleção, mais de 250 linhas numa UF) e importa pelo mesmo caminho do download. O container do site não tem
`php-zip`, então `tests/run-with-zip.sh` roda tudo num container descartável com o pacote instalado. Confere acentos,
paginação por cursor entre arquivos, escopo por UF e cargo, vínculos, remoção de quem sai do ZIP, HTTP 500 e arquivo que não é ZIP.

**Navegador.** O `[tse_apuracao_resumo]`, o card e a tabela completa foram abertos no Chromium headless em 1280 px e em 390 px,
com o JavaScript rodando: layout correto nas duas larguras, "Ao vivo" quando a checagem é recente e "Dados atrasados" quando passa
do limiar (comportamento do JS e do servidor idênticos).


## Versão 2.5.0 — importação, saúde e coleta mais barata

**Esquema 2.0.4.** `ae_candidates` ganhou `removed_at` (datetime, nulo). A migração roda sozinha no
próximo carregamento do WordPress (`dbDelta`); não exige ação do operador.

**Candidato que sai do CSV (A3).** A importação só insere e atualiza. Ao terminar, quem veio de um
CSV anterior (`data_json` com `SQ_CANDIDATO`), está no escopo da importação (UFs e cargos ligados) e
não apareceu nela recebe `removed_at`. Não se apaga nada: o cadastro e os resultados continuam, e o
catálogo mostra "Não consta na última lista do TSE" no cartão e no perfil. Se o candidato reaparece
numa importação seguinte, `removed_at` volta a nulo. Uma importação que não leu nenhuma linha (arquivo
vazio ou filtro que não casou) nunca marca ninguém. Candidatos criados só pelo EA20 não têm lista de
origem e ficam de fora.

**Última importação e reimportação agendada (A4).** Ao concluir, a importação grava a opção
`ae_last_import` (início, fim, linhas, UFs, cargos, quantos saíram e a data de geração do CSV, lida de
`DT_GERACAO`/`HH_GERACAO`). A aba **Importar e coletar** mostra isso e a contagem de candidatos que
não constam mais. A reimportação automática é **opt-in**, por eleição (`ae_auto_import_election`,
0 = desligada), a cada 6 h (filtro `ae_auto_import_interval`, mínimo de 1 h). Ela usa a seleção de
disputas, **nunca importa o Brasil inteiro sozinha** (sem disputa ligada, não agenda), não enfileira
se já há importação pendente e só tenta de novo no intervalo seguinte se falhar. O download do ZIP
prende o worker enquanto dura: desligue na noite da eleição.

**Avisos de saúde (A5, A7).** Na Visão geral e na Seleção de disputas, um aviso lista as disputas da UF
do site (`ae_site_uf`) que estão desligadas, o que antes deixava o Deputado Federal parado sem erro
visível. A Visão geral também avisa se alguma tabela `ae_*` não é InnoDB (a transação do snapshot não
protege nada em MyISAM) e a REST de saúde traz `non_innodb_tables`.

**Gravação do snapshot em lote (A1).** `persist_result` fazia 3 a 4 queries por candidato (mais de
4.400 para um Deputado Federal de SP). Agora resolve a eleição uma vez, busca os candidatos existentes
em lotes, só faz UPDATE de quem mudou e grava o ranking em INSERTs de 200 linhas. Medido em WordPress
real com 1.100 candidatos: de 4.406 para 16 queries e de ~2 s para ~0,1 s no caso normal (1ª coleta,
com candidatos novos: ~1.100 queries, uma por candidato inserido). A saída é idêntica à anterior
(hash do ranking e do cadastro conferido). Efeito colateral: `ae_candidates.updated_at` só muda
quando o dado muda, não a cada snapshot. Isto não encurta uma coleta que já começou nem muda o lock
do worker; a necessidade de duas pistas de worker (A2) deve ser reavaliada com carga real.

**Sem valores fixos de eleição (A6, parte).** O ano sugerido nos formulários é o ano par corrente ou o
seguinte (`AE_Plugin::default_election_year()`, filtro `ae_default_election_year`); o cabeçalho do
catálogo usa o ano da eleição com candidatos; "Simulado" e o placeholder de UF são neutros; o plugin
não cria mais a eleição `eleicoes-2026` sozinho (quem já a tem, mantém). Continuam fixos de propósito:
o caminho do simulado do TSE (`simulado/simulado2026`, convenção do próprio TSE), os padrões do bloco
Gutenberg e do `[apuracao]` legado (`eleicoes-2026`, mudar alteraria blocos já salvos) e a doc.

**Versão única (A11).** `AE_VERSION` passou a ser definida a partir de `TSE_APURACAO_VERSION`.

### Medições locais (2.5.0)

Docker local, sem rede, sem concorrência: coleta de Deputado Federal com 1.100 candidatos (JSON de 173 KB)
~0,3 s e ~20 queries no caso normal (1ª coleta ~0,9 s e ~1.100 queries, uma por candidato novo; 304 em
~10 ms). Antes do A1 eram ~2 s e ~4.400 queries. Catálogo com 62 mil candidatos: página 1 em ~100 ms (era
~1.275 ms, antes de tirar `DISTINCT` e `data_json` da consulta); filtros por cargo+UF ~50–75 ms; busca por
nome ~200 ms; páginas muito profundas sem filtro ~500 ms. Isso é latência local: em produção o banco tem
latência de rede, e é por isso que o número de queries importa mais que os milissegundos.


## Catálogo de candidatos: paginação — versão 2.4.1

O catálogo (`[apuracao_candidatos]`, `AE_Candidate_Catalog`) tinha um `LIMIT 60` fixo e não
tinha controle de página: com mais de 60 candidatos, só os 60 primeiros por ordem alfabética
apareciam, e o contador ("N candidatos encontrados") mostrava no máximo 60. Em SP, por
exemplo, Deputado Estadual passa de mil candidatos.

**Comportamento**

- **24 candidatos por página** por padrão (múltiplo de 3, fecha a grade de 3 colunas).
  Ajustável por atributo, `[apuracao_candidatos por_pagina="12"]` (de 1 a 200), ou pelo
  filtro `ae_catalog_per_page`. O atributo vale sobre o filtro.
- **Total real:** uma consulta de contagem separada informa "1.487 candidatos encontrados ·
  mostrando 1–24". Com 1 candidato: "1 candidato encontrado"; com 0: "Nenhum candidato
  encontrado".
- **Navegação:** `Anterior / 1 2 3 … 12 / Próxima` (`paginate_links`, dentro de
  `<nav class="ae-catalog-pagination">`). **Só aparece quando há mais de uma página.** Com
  24 resultados ou menos (ou `por_pagina` maior que o total) não há navegação, por design.
- **Parâmetro de URL:** `?ae_pagina=N`. É deliberadamente diferente de `paged`: numa página
  estática o WordPress trata `paged` como paginação de arquivo. O catálogo também usa
  `ae_busca`, `ae_cargo`, `ae_uf`, `ae_partido` e `ae_candidato` (ficha).
- **Filtros preservados:** os links de página carregam os filtros ativos (com os valores
  codificados). Buscar ou trocar um filtro volta para a página 1.
- **Página fora do intervalo** (`ae_pagina=99`, `0` ou texto) é ajustada para a última ou a
  primeira página, sem erro.
- **Ordem estável:** `ORDER BY ballot_name ASC, id ASC`. O `id` desempata homônimos; sem ele,
  nomes iguais podem repetir ou pular candidatos entre páginas.
- **Âncora:** o botão **Buscar** e os links de página terminam em `#ae-catalog-lista`
  (o contador logo acima dos cards), então o navegador desce direto para a lista em vez de
  voltar ao topo da página.
- **Escopo:** a lista mostra só o que foi importado, que são **titulares** (vice e suplentes
  não são importados). Um catálogo pequeno pode ser falta de importação, não de paginação.

**Estilo:** `assets/css/tse-apuracao.css`, bloco "Paginação do catálogo de candidatos"
(`.ae-catalog-pagination`, `.page-numbers`, `.current`, `.dots`). A marcação é a padrão do
`paginate_links` (`type=list`), então um tema que estilize `.page-numbers` também a afeta.


## Intervalo de coleta por tipo de disputa — versão 2.4.0

Problema: o worker é sequencial e uma coleta de Deputado Federal/Estadual (centenas de
candidatos por UF) pode levar minutos. Com várias UFs ligadas, essas coletas atrasam todas
as outras, inclusive as majoritárias.

**Regra** (`AE_Collection_Policy`, `includes/class-collection-policy.php`), aplicada **só
quando há mais de uma UF ligada** (o Presidente é nacional e não conta como UF):

| Disputa | Intervalo | Fila |
| --- | --- | --- |
| Presidente, Governador, Senador (e Prefeito) | 60 s | vão primeiro |
| Deputado Federal, Estadual e Distrital | 120 s (filtro `ae_heavy_interval`) | esperam 5 s na fila |

Com uma UF só, tudo fica em 60 s e a fila não muda. Quando o conjunto de UFs ligadas muda
(sincronização ou salvar a Seleção de disputas), `AE_Collection_Policy::apply_all()` recalcula
o intervalo automático.

- **Prioridade:** `enqueue_due_collections` enfileira as leves antes das pesadas, e, com mais
  de uma UF, as pesadas entram com `run_after` 5 s à frente. Assim, uma leve que vença logo
  depois ainda passa na frente de uma pesada que já estava na fila (a fila atende por
  `run_after` e `id`). Não há coluna nova nem segunda fila.
- **Explícito para o admin:** a aba **Seleção de disputas** tem um texto fixo com a regra e o
  estado atual ("agora há N UFs ligadas: regra ativa/inativa") e a coluna **Atualiza a cada**
  por disputa, marcada como *automático* ou *manual*. O valor vai de 30 a 900 s.
- **Ajuste manual:** alterar o valor na coluna grava `collection.interval_mode = "manual"` e
  a regra nunca mais o sobrescreve. Apagar o campo devolve a disputa ao automático.
  A sincronização do EA11 regrava o `config_json` inteiro; ela agora carrega `interval` e
  `interval_mode` do que já existia, para um ajuste manual sobreviver a um novo sync.
- **Disputas sincronizadas antes da 2.4.0** não têm `interval_mode`. Valem como
  **automáticas** se o intervalo gravado é 60 s ou o das pesadas, e como **manuais** em qualquer
  outro valor, para nunca apagar um ajuste que alguém tenha feito.
- **"Dados atrasados" no front:** o limiar deixou de ser fixo em 3 min e passou a
  `max(3 min, 3 × intervalo da disputa)` (`AE_Collection_Policy::stale_after`): 3 min para
  60 s e 6 min para 120 s. Sem isso, deputados a cada 2 min apareceriam como atrasados.

**O que a regra não resolve:** um job pesado já em andamento segura o lock do worker até
terminar (o orçamento de 40 s é checado só entre jobs). A regra reduz a frequência e dá
prioridade às leves, mas não interrompe uma coleta que já começou. Tornar a coleta pesada
mais barata (inserção em lote, pular candidato sem mudança) continua como melhoria futura.

**Snapshot em transação:** `persist_result` gravava o snapshot como `valid` e só depois as
linhas do ranking, sem transação; um processo interrompido no meio deixava um snapshot
parcial sendo servido. Agora snapshot e linhas entram em `START TRANSACTION`/`COMMIT`, com
`ROLLBACK` e nova tentativa do job se qualquer inserção falhar. Exige tabelas InnoDB (o
padrão do MySQL e do MariaDB atuais; o esquema não fixa o engine).

**Slack removido:** o plugin não se comunica mais com Slack nem com nenhum serviço de alerta
(ver "Saúde do disparo"). O aviso de cron parado e o campo `tick` da REST de saúde continuam.


## Saúde do disparo da coleta — versão 2.3.8

Motivo: o cron de sistema (`bin/tse-tick-loop.sh`) já parou em silêncio duas vezes
(variável `TSE_APURACAO_CONTAINER` fora do crontab → `php: not found`), e a tela de
saúde só mostrava o "próximo ciclo" do WP-Cron, que continuava aparecendo como ok.

- `AE_Job_Runner::tick()` registra um batimento (`ae_last_tick_at`, `ae_last_tick_source`)
  e, quando vem de CLI, também `ae_last_cli_tick_at`. O tick de CLI é registrado
  **à parte** porque o WP-Cron por tráfego mantém "algum tick" fresco mesmo com o cron
  de sistema morto, que é exatamente o caso a detectar.
- `AE_Job_Runner::tick_status()` devolve `stale` (nenhum tick de qualquer origem há mais de
  5 min, com disputas ligadas), `cli_stopped` (o cron de sistema já funcionou e passou
  de 2 min sem disparar) e `cli_never` (nunca houve tick de CLI: depende só do tráfego).
  Limites ajustáveis pelos filtros `ae_tick_stale_seconds` e `ae_cli_tick_stale_seconds`.
- **Visão geral:** linha "Último tick" (com a origem: cron do sistema ou WP-Cron) e avisos
  no topo — vermelho para coleta parada, amarelo para cron de sistema parado, nota para
  "cron de sistema não detectado". Nada aparece se não houver disputa ligada.
- **REST** `apuracao/v1/admin/health`: novo campo `tick`. O plugin **não se comunica com
  Slack nem com nenhum serviço de alerta**: quem quiser alerta externo consome esse
  endpoint (com usuário `manage_options`) e decide o que fazer.
- **`bin/tse-tick-loop.sh`:** sem container configurado e sem `php` no host, ou com
  container configurado e sem `docker`, o script grava a mensagem no log, escreve em
  stderr e sai com código 1, em vez de falhar a cada 15 s sem aviso. Cada falha de tick
  ganha uma linha com data e código de saída.

### Marcadores do TSE nos dados de candidato (2.3.8)

O CSV de candidatos preenche campos sem informação com marcadores como `#NE` (não
divulgado) e `#NULO`, e a situação da candidatura pode vir `#NE` para todos. Isso não é
dado para o leitor. `AE_Candidate_Catalog::clean_value()` devolve vazio para esses
marcadores (`#` + letras maiúsculas): a importação não grava mais `#NE` em
`ae_candidates.situation`, e a ficha do candidato não exibe esses valores nem os de
`ae_candidates` já importados antes (a limpeza também ocorre na exibição, sem reimportar).
Quando a situação fica vazia, a ficha mostra "Não informada".


## Front ao vivo — throttling de aba e cache de página (v2.3.7)

Achado em 24/09/2026: um painel ficou ~10min mostrando `atualizado_em_local`
antigo mesmo com a coleta rodando normal no servidor (confirmado via REST e
banco, sempre frescos). Duas causas distintas, ambas endereçadas:

- **Throttling de aba em segundo plano (causa real deste caso):**
  `tse-live.js` dependia só de `setInterval` pro polling; navegadores
  pausam/atrasam `setInterval` de abas inativas por muitos minutos pra
  economizar bateria/CPU. Um visitante que deixa a aba aberta e minimizada
  (padrão comum em cobertura eleitoral) pode ver dado desatualizado por um
  bom tempo sem nenhum erro visível. **Corrigido na v2.3.7:** listener de
  `visibilitychange` que dispara atualização imediata assim que a aba volta a
  ficar visível, sem esperar o próximo tick do timer.
- **Cache FastCGI do nginx (achado, não é bug):** `fastcgi_cache_valid 1m` no
  template de nginx do host (`/etc/nginx/snippets/cache-directives.conf`)
  cacheia a página por até 1 minuto, mas a regra de bypass já exclui qualquer
  URL com query string — e é assim que `/wp-json/tse/v1/resultado` funciona,
  então o polling ao vivo **nunca** passa por esse cache. O único efeito é o
  HTML inicial (renderizado no servidor) poder estar até ~1min desatualizado
  até o JS rodar seu primeiro poll (3s depois de carregar) e substituir os
  valores. Comportamento seguro por design, só não estava documentado — vale
  saber pra não confundir com um bug real numa futura investigação.


## Autonomia operacional — versão 2.3.0

- o worker usa lock nomeado no MySQL, compartilhado entre WP-Cron, CLI e cron de
  sistema mesmo quando não há Redis/Memcached;
- jobs que ficaram em `running` após encerramento de processo são recuperados
  automaticamente quando o lock expira;
- HTTP 404 de uma fonte EA20 não desativa a disputa: a nova tentativa ocorre com
  backoff de 10 minutos até seis horas, sem martelar o TSE e sem ação editorial;
- uma resposta 304 registra a última consulta bem-sucedida. Assim, durante uma
  parcial estável, a interface não apresenta “Dados atrasados” apenas porque o
  snapshot não mudou;
- a saúde REST informa se `ZipArchive` está disponível (`zip_available`) e lista `missing_requirements`. A importação de CSVs em
  ZIP requer `php-zip` no ambiente, e desde a 2.5.1 sem ela o plugin nem ativa.
