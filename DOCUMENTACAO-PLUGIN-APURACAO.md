# TSE Apuração — arquitetura, operação e plano

## Estado deste documento

Este é o runbook técnico e funcional do plugin. Ele registra o que foi implementado, o que foi decidido e o que ainda está planejado. Toda mudança que altere fonte, contrato JSON, frequência, cache, fila, interface administrativa ou publicação deve atualizar este arquivo.

Última revisão: 22/09/2026, manhã (1º dia da janela do 2º simulado do TSE, 22–24/09).

## Objetivo

Entregar uma experiência plug and play para publishers em WordPress:

1. o administrador escolhe **Oficial** ou **Simulado** e clica em **Sincronizar configuração do TSE**;
2. o plugin lê o EA11 (`ele-c.json`) e cria eleições, turnos, cargos, abrangências e URLs EA20;
3. o administrador escolhe a eleição e clica em **Buscar e importar candidatos**;
4. o plugin localiza o pacote oficial no Portal de Dados Abertos, baixa e processa todos os CSVs do ZIP em lotes;
5. a coleta ocorre no servidor, grava snapshots auditáveis e abastece a API REST do WordPress;
6. blocos e shortcodes atualizam no navegador consultando apenas o próprio WordPress.

Nenhum visitante consulta o TSE diretamente. Nenhum operador precisa montar ou colar uma URL.

## Fontes oficiais e contratos

- Página técnica e FAQ: <https://www.tse.jus.br/eleicoes/informacoes-tecnicas-sobre-a-divulgacao-de-resultados>
- Instruções de download: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/tse-instrucoes-para-download-dos-arquivos-da-divulgacao-2026>
- EA11 — configuração de eleições: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/tse-ea11-arquivo-de-configuracao-de-eleicoes>
- EA14 — acompanhamento Brasil: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/tse-ea14-arquivo-de-acompanhamento-brasil>
- EA15 — acompanhamento UF: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/tse-ea15-arquivo-de-acompanhamento-uf>
- EA20 — resultado unificado: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/tse-ea20-arquivo-de-resultado-unificado>
- Candidatos 2026: <https://dadosabertos.tse.jus.br/dataset/candidatos-2026>
- Apresentação: <https://www.tse.jus.br/eleicoes/eleicoes-2026-content/arquivos/divulgacao-de-resultados/apresentacao-interessados-2026-pdf>
- Gravação: <https://www.youtube.com/watch?v=7Jz2UIryIwc>

### Endpoints descobertos pelo plugin

| Uso | Oficial | Simulado |
| --- | --- | --- |
| Host | `https://resultados.tse.jus.br` | ainda não publicado pelo TSE |
| Ambiente | `oficial` | `simulado` |
| EA11 | `/oficial/comum/config/ele-c.json` | indisponível até divulgação oficial |
| Candidatos | `https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_{ANO}.zip` | não se aplica |

O ciclo, pleito, eleição, abrangências e cargos não são fixados no código: vêm do EA11. Em 14/09/2026, o EA11 oficial ainda devolvia o ciclo `ele2024`; a sincronização de 2026 deve informar claramente que o catálogo respondeu, mas a eleição ainda não foi publicada. O FAQ do TSE também afirma que a URL de acesso aos simulados ainda não está disponível. Por segurança, o botão Simulado fica bloqueado até a publicação oficial, evitando 404 que podem levar a bloqueio de IP.

### Construção EA20

```text
{host}/{ambiente}/{ciclo}/{eleicao}/dados/{uf}/
{uf}-c{cargo4}-e{eleicao6}-u.json
```

O plugin só cria a URL depois de receber os códigos do EA11.

## Arquitetura implementada

```text
Dados Abertos ─► import_candidates ─► ae_candidates

EA11 ─► sync_tse ─► eleições + disputas + fontes EA20
                                     │
EA20 ─► limite/HTTP condicional ─► validação/normalização
                                     │
                falha ─► retry/log   ├─► snapshot bruto + SHA-256
                                     │
                                     ▼
                    REST WordPress + cache/CDN
                                     │
                                     ▼
                         bloco/shortcode no cliente
```

### Banco

| Tabela | Responsabilidade |
| --- | --- |
| `ae_elections` | eleição e metadados do EA11 |
| `ae_contests` | turno, cargo, abrangência, vagas e fonte gerenciada |
| `ae_candidates` | cadastro de Dados Abertos ou enriquecido pelo EA20 |
| `ae_snapshots` | JSON bruto, SHA-256, totais, geração e sequência |
| `ae_result_rows` | ranking materializado do snapshot |
| `ae_jobs` | fila retomável com lock, tentativas e cursor |
| `ae_logs` | trilha operacional |

Snapshots são imutáveis. Payload repetido pelo mesmo SHA-256 não cria nova versão. Em falha, a API continua servindo o último snapshot válido.

## Painel administrativo implementado

O menu único **Apuração** contém:

- **Visão geral:** contadores, saúde do schema/cron, último snapshot e atalhos.
- **Configuração:** seletor Oficial/Simulado e botão de sincronização automática do EA11. A criação local de 83 disputas fica recolhida em “Estrutura local para desenvolvimento”.
- **Importar e coletar:** botão de importação automática de candidatos e explicação da coleta protegida. Não existem campos de URL no fluxo comum.
- **Fila e progresso:** jobs, tentativas, erros, cursor e retry.
- **Logs:** cem eventos mais recentes.

A antiga tela duplicada em **Configurações > TSE Apuração** deixou de ser registrada. Opções antigas permanecem apenas para compatibilidade visual e mock do shortcode legado.

## Importação de candidatos implementada

O botão deriva o ano da eleição e usa o pacote oficial `consulta_cand_{ANO}.zip`. O processamento:

- baixa o ZIP uma vez para arquivo temporário;
- percorre todos os CSVs do ZIP;
- lê por streaming e lotes de 250 linhas;
- salva cursor de arquivo + linha para retomada;
- importa `SQ_CANDIDATO`, nomes, número, partido e situação;
- permite que o EA20 complete candidatos ausentes e derive a foto oficial por `sqcand`.

## Normalização EA20 implementada

O normalizador percorre `carg[] → agr[] → par[] → cand[]`.

| Finalidade | Campo TSE |
| --- | --- |
| candidato | `sqcand`, `n`, `nm`, `nmu` |
| partido | `par.sg` |
| votos/percentual | `cand.vap`, `cand.pvap` |
| eleito/situação | `cand.e`, `cand.st` |
| andamento/final | `and`, `tf`, `md` |
| seções | `s.ts`, `s.st`, `s.pst` |
| votos totais | `v.tv` |
| geração | `dg`, `hg`, `idg` |

Não se infere “eleito” pela posição, percentual ou texto aproximado. Para 2026, a fonte é `cand.e`; `cand.st` é preservado para “Eleito”, “Não eleito”, “2º turno” e demais situações.

**Correção 15/09/2026:** o TSE usa `cand.e = "s"` também para quem só avança ao 2º
turno (não apenas para quem está de fato eleito) — descoberto ao vivo no simulado,
quando Governador-ES mostrou 2 candidatos como "Eleito" simultaneamente. O
normalizador agora nunca marca `elected = 1` quando `cand.st` contém "turno"
(`class-tse-client.php`, variável `$runoff`), independente do valor de `cand.e`.
Primeira tentativa usou `preg_match` com classe de caracteres `[ºo°]`, que falhou
silenciosamente por tratar o "º" (multibyte UTF-8) byte a byte; a versão final usa
`mb_stripos($status, 'turno')`, seguro para acentuação.

**Ranking do Senado:** o `rank_no` gravado (de `cand.seq`/`posicao`) é a ordem
oficial do TSE, não necessariamente a ordem por número de votos — em teste, um
candidato com menos votos apareceu como "1º/Eleito" à frente de outro com mais
votos. Isso é uma decisão do TSE (pode refletir critério além do voto bruto), não
um bug de normalização; o campo `elected` continua vindo só de `cand.e` (com a
ressalva do 2º turno acima). O que **era** bug era o front-end: o polling ao vivo
reaproveitava o mesmo `<li>` do DOM e só atualizava o texto do número de posição,
sem reordenar o elemento — corrigido em `tse-live.js` (`list.appendChild(li)` a
cada atualização, o que reordena reaproveitando o nó existente).

**Totais de votos:** o normalizador ignorava por completo `v.van` (votos
anulados), `v.vb` (brancos) e `v.vn` (nulos) — só guardava o total geral. Em teste
real (Governador-SP), `van` chegou a 8,4% dos votos, uma categoria não trivial.
Adicionados a `totals`: `annulled_votes`/`annulled_percentage`,
`blank_votes`/`blank_percentage`, `null_votes`/`null_percentage`. Não exige
migração de schema (`totals_json` é JSON livre). Expostos no payload do shortcode
como `votos_anulados`, `votos_brancos`, `votos_nulos`, `pct_votos_anulados`, e no
endpoint `apuracao/v1/results` como `segundo_turno` (booleano) por candidato.

**Vagas do Senado:** `seats` era fixado em `2` para o cargo `0005` em todo o
código (`class-tse-discovery.php`, `class-plugin.php`, `class-admin.php`). O
Senado renova por terços alternados — 2/3 em 2022, **1/3 em 2026** — então o valor
correto para este ciclo é `1`. Corrigido nos três lugares. `seats` é só metadado
informativo (exposto em `contest_meta()`), nunca influenciou a lógica de eleito.

### Cenário zerado

EA20 válido com `v.tv = 0`, `s.st = 0`, `s.pst = 0` e `and = "n"` é aceito. A interface exibe **Aguardando apuração**, candidatos zerados e 0,00%.

### Cenário 100%

Com `and = "f"`/`tf = "s"`, a interface exibe **Totalizado**. Cada candidato recebe a situação oficial: **Eleito** quando `e = "s"` e a situação não é de 2º turno; os demais exibem `st`, como **Não eleito** ou **2º turno**.

Fixtures: `ea20-zero.json`, `ea20-final.json` e `ea20-minimal.json` (precisam de um
caso "2º turno com `cand.e = s`" adicionado como regressão do bug acima).

**`and` e `tf` podem discordar entre si (descoberto ao vivo, 22/09/2026, 2º
simulado):** a disputa de Presidente veio com `and = "n"` (`progress:
"not_started"`, `reported_percentage: 0`) e `tf = "s"` ao mesmo tempo — o doc
até então assumia que os dois só coexistem no cenário 100% ("Cenário 100%"
acima). A interface do plugin **não é afetada**: `status` (Totalizado/Parcial/
Aguardando apuração) e `atrasado` são derivados só de `totals['progress']`
(`and`), nunca de `totals['final']` (`tf`) — ver `class-tse-shortcode.php`,
método que monta o payload do shortcode. Porém `totals.final` é exposto cru
no endpoint público `apuracao/v1/results` (`class-rest.php::results()`); um
consumidor externo que confie só em `final` pode concluir "apuração encerrada"
com 0% apurado. Não é bloqueante para o que o plugin entrega hoje — registrado
aqui para qualquer integração externa que venha a consumir esse campo, e como
mais um exemplo (como o "2º turno" e os votos anulados) de que o TSE publica
combinações de campos no simulado que a documentação pública dele não
descreve explicitamente.

## Limite de acesso e bloqueios

O TSE informa 100 requisições/s por IP e bloqueio de dez minutos quando excedido. Respostas 304 contam; URLs 404 repetidas também podem causar bloqueio.

Proteções implementadas:

- teto conservador de **20 requisições/s** por origem WordPress;
- fila com lock contra workers concorrentes do plugin;
- `If-None-Match`/`If-Modified-Since`;
- nenhuma versão nova para 304 ou SHA repetido;
- circuit breaker de dez minutos após 403 ou 429; 404 usa backoff por fonte
  (10 min a 6h, ver "Autonomia operacional") em vez de bloqueio global, para não
  desligar 100+ disputas válidas por causa de uma única URL ainda não publicada;
- timeout, redirecionamentos limitados e HTTPS `*.tse.jus.br`;
- retry exponencial e último snapshot válido.

Com 83 disputas uma vez por minuto, a média é aproximadamente 1,4 requisição/s. O teto é uma barreira, não uma meta.

## Publicação no cliente

O shortcode `[tse_apuracao]` e o bloco usam snapshots locais. O JavaScript faz polling na REST do WordPress. Foram ajustados turno, estado inicial, percentual oficial, status e inclusão/remoção das etiquetas.

```text
[tse_apuracao cargo="governador" uf="es" turno="1" limite="10" atualizar="60"]
```

APIs:

- `GET /wp-json/apuracao/v1/results/{eleicao}/{turno}/{cargo}/{abrangencia}`
- `GET /wp-json/apuracao/v1/candidates/{eleicao}/{id-externo}`
- compatibilidade: `GET /wp-json/tse/v1/resultado?cargo=governador&uf=es&turno=1`

### `[tse_apuracao_card]` — card compacto (novo, 15/09/2026)

Criado para uso na home/grades, onde o widget completo (`[tse_apuracao]`) é grande
demais. Reaproveita toda a lógica de dados de `TSE_Shortcode::snapshot_resultado()`;
só muda a apresentação (`templates/card.php`).

```text
[tse_apuracao_card cargo="presidente" titulo="Presidente"]
[tse_apuracao_card cargo="presidente" limite="4" titulo="Presidente" classe="wrapper"]
```

Atributos: `cargo`, `uf`, `turno`, `atualizar`, `titulo` (aceita `title` como
sinônimo), `classe` (classe CSS extra) e `limite`:

- `limite=1` (padrão): um card único com o líder da disputa — foto, número, nome,
  partido, % grande, barra, badge (Eleito/2º turno/Não eleito).
- `limite>1`: repete o **mesmo** card, um por colocado, dentro de
  `<div class="tse-card-grid">`. Título e "% apurado" saem do card individual e
  viram um cabeçalho único da seção (`<header class="apuracao__header"><h2>`,
  no mesmo padrão de outros cabeçalhos de seção do site), porque repetir isso em
  cada mini-card ficava redundante e visualmente poluído — foi a primeira versão
  entregue e revertida no mesmo dia a partir de feedback direto.
- `classe` é aplicada no elemento **de fora** (o único card, se `limite=1`, ou o
  `.tse-card-secao`/`.tse-card-grid`, se `limite>1`) — nunca repetida em cada
  mini-card — para o front conseguir plugar num grid que já existe no tema.

Rótulo "ao vivo" tem 3 estados agora (`liveLabel()` em `tse-live.js`, compartilhado
com `[tse_apuracao]`): **Ao vivo** (em andamento) → **Apuração concluída**
(`progress = final`, TSE não vai mandar mais nada, não é atraso) → **Dados
atrasados** (sem novo snapshot há mais de 3 min e ainda não é final). Antes, uma
disputa finalizada há 25 min aparecia como "Dados atrasados" para sempre, o que é
enganoso — só corrigido depois de diagnosticar ao vivo por que o Presidente
(100%, `final`) parecia "atrasado" no simulado.

### Bug: CSS/JS não carregava fora de página singular

`enqueue_assets()` (hook `wp_enqueue_scripts`) só chamava `enqueue_widget_assets()`
quando `is_singular()` e o shortcode aparecia em `$post->post_content`. Isso
funciona em posts/páginas normais, mas a **home** do site (listagem, não
singular) nunca batia nessa condição — o único outro enqueue era o chamado de
dentro do próprio `render()`/`render_card()`, que roda tarde demais (depois que o
`wp_head()` já imprimiu as tags `<link>`), então o `<link>` do CSS nunca saía.
Resultado: shortcode na home renderiza sem nenhum estilo (visto ao vivo:
`http://localhost/` empilhado, sem grid, sem cor). Corrigido carregando os assets
sempre, incondicionalmente — os arquivos são pequenos (CSS ~10KB) e o shortcode
pode aparecer em qualquer lugar (home, widget, page builder, block theme) sem
estar em `$post->post_content`.

## Planejado — ainda não implementado

### EA14/EA15 e mapas

Para mapas municipais em escala nacional, a próxima fase deve:

1. consultar EA14 para detectar UFs alteradas;
2. consultar EA15 somente nas UFs alteradas;
3. enfileirar EA20 somente para municípios/cargos alterados;
4. importar EA12 para relacionar código TSE, município, UF e IBGE;
5. criar snapshots e endpoint REST geográfico compacto;
6. carregar geometria estática pelo CDN do publisher e votos pela API local.

### Candidatos

- associar automaticamente candidato à disputa por ano/cargo/UF durante o CSV;
- cache próprio de fotos, se a política editorial exigir independência do CDN TSE;
- páginas individuais, vice/suplentes e dados complementares;
- atualização programada quatro vezes ao dia.

### Segurança e operação

- validar assinatura digital X.509 de cada JSON;
- monitorar alteração dos contratos EA11/EA20;
- alertas externos para fila, bloqueio, atraso e divergência;
- retenção/compactação de snapshots aprovada por redação e infraestrutura.

## Plano dos simulados

Janelas: 15–17/09/2026 e 22–24/09/2026, 9h–12h e 14h–17h (Brasília). Essas são as primeiras janelas possíveis para validação externa; não garantem uma URL antes de sua divulgação pelo TSE.

1. ✅ sincronizar **Simulado** e registrar EA11, ciclo, pleito e eleições —
   validado ao vivo em 22/09/2026: ciclo `ele2026` (não mais `ele2024`), 193
   disputas ativas criadas a partir do EA11 real;
2. ✅ confirmar zero inicial e `and = "n"` — Presidente-BR veio zerado
   (`reported_percentage: 0`, `reported_sections: 0`), com a ressalva do
   `and`/`tf` divergentes registrada acima;
3. comparar parcial com o portal Resultados — ainda não feito (requer abrir o
   portal público em paralelo);
4. confirmar 100%, `and`, `tf`, `md`, eleitos/não eleitos e duas vagas de
   Senado — pendente (simulado de hoje ainda não chegou a 100% na disputa
   testada);
5. ✅ validar "2º turno" sem marcar como eleito — confirmado com dado real:
   candidato com `situation: "2º turno"` veio com `elected: "0"` e
   `segundo_turno: true` no payload da REST;
6. medir quantidade e pico de requests no IP de saída — não medido
   formalmente ainda; nenhum bloqueio (`ae_tse_blocked_until`) foi disparado
   durante os testes de hoje;
7. ✅ testar 304 — confirmado: segunda coleta da mesma disputa retornou
   `unchanged` (condicional `If-None-Match` funcionando); indisponibilidade
   mantendo último snapshot ainda não testada isoladamente;
8. confirmar no navegador que nenhum domínio TSE é acessado — não testado
   nesta rodada (testes de hoje foram via CLI/wp eval, não navegador);
9. ✅ executar carga da REST local — rodado em 22/09/2026 com k6 (`grafana/k6`
   via Docker, `--network host`) contra `apuracao/v1/results/tse-21270/1/0001/br`
   (disputa real do simulado, com snapshot válido): 211.977 requisições em
   2min20s, rampa até 200 VUs (perfil reduzido de 5min para ~2min20s em
   relação ao `tests/load/results.js` original, só para esta rodada de
   validação). **p95 = 179,86 ms** (meta <400 ms), **0% de erro** (meta <1%),
   100% dos checks (`200/304` + header `Cache-Control` presente). Alvo batido
   com folga — mas atenção: isso mede a REST do WordPress local servindo do
   cache de snapshot já gravado, não o caminho de coleta (`AE_TSE_Client`)
   sob carga simultânea de leitura;
10. anexar fixtures sanitizadas e registrar hash, horário, versão e aprovação
    — ainda não feito.

Alvo inicial: p95 abaixo de 400 ms e erros abaixo de 1% com 200 usuários virtuais, ajustável à infraestrutura.

## Incidente 15/09/2026 — atraso de sincronização durante o 1º simulado

Durante a primeira janela do simulado oficial do TSE (15/09, manhã), o site publicava
resultados visivelmente atrasados em relação ao portal `resultados-sim.tse.jus.br`
(ex.: TSE já em ~99,99% de apuração enquanto o nosso `[tse_apuracao]` mostrava
percentuais bem menores).

### Diagnóstico

Consulta direta ao banco (`wp_ae_jobs`, `wp_ae_snapshots`, `wp_ae_logs`) mostrou:

- fila `collect_results` com **105 jobs acumulados**, apenas 1 em execução por vez;
- disputas com snapshot válido **até 19 minutos desatualizado** (`contest_id=457`
  chegou a 1142 s de idade), contra o intervalo configurado de 60 s;
- nenhum bloqueio ativo do TSE (`ae_tse_blocked_until` já expirado) e nenhum erro de
  parsing — os snapshots que chegavam eram válidos (SHA-256 ok, `snapshot_valid` no
  log). Ou seja, **não era problema de fonte/contrato, era de vazão do worker**;
- o `wp-cron.php` (verificado no `access.log` do nginx) disparava de forma irregular,
  a cada ~60–90 s, dependendo de tráfego no site — exatamente o risco já registrado
  em "Pendências para produção" ("WP-Cron por tráfego é fallback");
- `AE_Job_Runner::tick()` processa jobs **sequencialmente** (um lock global via
  `wp_cache_add`) com orçamento de 40 s por disparo. Um tick manual isolado processou
  ~82 jobs nesses 40 s — ou seja, a vazão em si é suficiente; o problema é a lacuna
  entre disparos do WP-Cron, que faz o backlog crescer sempre que o tráfego do site
  cai ou quando o TSE demora mais para responder (carga real da imprensa no
  simulado).

### Correção aplicada (mesmo dia, com o simulado em andamento)

Sem alterar o código de coleta/normalização, foi adicionado um disparo direto e
independente de tráfego:

- [`bin/tse-tick.php`](bin/tse-tick.php): carrega o WordPress
  (`wp-load.php`) e chama `AE_Job_Runner::instance()->tick()` diretamente, sem passar
  pelo agendamento do WP-Cron;
- [`bin/tse-tick-loop.sh`](bin/tse-tick-loop.sh): dispara esse script a cada 15 s
  (4x por minuto), com saída em arquivo de log configurável por variável de
  ambiente (`TSE_APURACAO_LOG_FILE`);
- crontab real do host chamando o loop a cada minuto:
  `* * * * * .../tse-apuracao/bin/tse-tick-loop.sh`. Container Docker e caminho
  de log ficam fora deste repositório, configurados por ambiente
  (`TSE_APURACAO_CONTAINER`, `TSE_APURACAO_LOG_FILE`).

O lock interno do `AE_Job_Runner` (`wp_cache_add` com TTL de 55 s) garante que essas
chamadas extras nunca rodem em paralelo com o WP-Cron nem entre si — na pior das
hipóteses, uma chamada recém-disparada encontra o lock ocupado e retorna
imediatamente sem custo. O WP-Cron continua ativo como redundância.

Resultado logo após a ativação: fila caiu de 105 para a faixa de dezenas em menos de
2 minutos e a idade máxima dos snapshots voltou para dentro do intervalo configurado
(< 90 s). Ver o arquivo de log configurado em `TSE_APURACAO_LOG_FILE` para o
histórico de execuções.

### Ação de acompanhamento

- migrar esse cron "de emergência" para um mecanismo suportado em produção (ex.:
  cron de sistema no servidor real, não um host de desenvolvimento) antes do
  segundo simulado (22–24/09) e da eleição oficial;
- considerar paralelizar `collect_results` (hoje 1 worker) se, mesmo com disparo a
  cada 15 s, o TSE responder mais lento que o esperado sob carga real de eleição;
  o teste de hoje não indicou essa necessidade (82 jobs em 40 s com fonte
  respondendo normalmente), mas vale monitorar no simulado de 22–24/09.

## Avaliação de performance e prontidão (15/09/2026)

Relatório completo (fluxograma + métricas): <https://claude.ai/artifact/XDMtdhFMDrvQYeXB8gMwY7>.

### O teto real de disputas é conhecido, não estimado

2026 é ano de **eleição geral** — Presidente, Governador, Senador, Deputado
Federal e Deputado Estadual/Distrital. Não tem prefeito/vereador (só em 2028).
Contando 1 disputa por cargo × UF:

```
1 Presidente + 27 Governador + 27 Senador + 27 Dep. Federal + 27 Dep. Estadual/Distrital = 109
```

**109 é exatamente o número de disputas ativas no simulado testado hoje** — não é
uma amostra pequena de um universo maior, é o teto real do 1º turno. Pior caso de
2º turno (Presidente + hipoteticamente os 27 governadores, o que nunca ocorre na
prática): 137. A vazão medida isoladamente (82 jobs processados em 40 s, um
worker sequencial) cobre esse teto com folga — ver o incidente de sincronização
acima para o cenário em que isso *não* foi suficiente (não por falta de vazão, mas
por o disparo do WP-Cron ser irregular).

**Conclusão:** a arquitetura de coleta (contagem de disputas × 1 worker) está
adequada para o escopo real de 2026. EA14/EA15 continuam sendo pré-requisito
apenas se a cobertura crescer para além de cargo × UF (ex.: municípios em anos de
eleição municipal) — não são bloqueio para este ciclo.

### O que ainda não depende do TSE (pode ser feito antes do 2º simulado)

- sincronizar os arquivos alterados hoje com homolog e produção — confirmado ao
  vivo que o homolog ainda mostra "Eleito" onde deveria ser "2º turno" porque
  está rodando o código de antes desses fixes;
- recriar `bin/tse-tick.php` como cron de sistema real (não WP-Cron) nos
  ambientes de homolog/produção, do jeito que já foi feito no Docker local;
- ligar cache persistente (ver plano de cache abaixo);
- rodar o teste de carga já definido (200 usuários virtuais, p95 < 400 ms, erro
  < 1%) — pode ser feito contra snapshots já existentes no banco, sem depender
  do TSE ao vivo.

### O que só o 2º simulado (22–24/09) pode validar

O TSE publica dado de teste deliberadamente com casos extremos — foi assim que
apareceram hoje o "2º turno marcado como eleito", o ranking do Senado fora de
ordem de votos e o candidato com nome `Candidato string 1234!@#$"TSE"`. Não dá
pra garantir que todos os formatos possíveis já foram vistos sem mais uma rodada
de dado real. Isso não é falha de arquitetura — é a natureza de integrar com uma
fonte de terceiro cujo contrato não é 100% especificado publicamente.

## Plano de cache — decisão adiada para depois do 2º simulado

Produção terá **Cloudflare** na frente do site. Isso resolve bem o eixo de escala
que ficou em aberto (tráfego de leitor, não quantidade de disputas — ver acima),
porque a arquitetura já é "cliente busca": `tse-live.js` já faz `fetch()` no REST
do WordPress por polling, não é o servidor reprocessando a cada requisição.

Ponto técnico a resolver quando isso for retomado: hoje só o endpoint
`apuracao/v1/results` manda `Cache-Control` explícito
(`class-rest.php::cached_response()` — `public, max-age=30, s-maxage=60,
stale-while-revalidate=300`). O endpoint que o widget/card realmente usa no dia a
dia, `tse/v1/resultado` (`class-tse-shortcode.php::rest_resultado()`), **não**
define isso — sem cabeçalho explícito, a REST API do WordPress manda o
`no-cache` padrão dela, e o Cloudflare não tem motivo para guardar aquela
resposta na borda. Sem esse ajuste, o cache de borda simplesmente não pega nesse
endpoint específico, mesmo com o Cloudflare ligado.

Cloudflare (cache de borda HTTP) e Redis/Memcached (cache de objeto no PHP, usado
por `AE_Results::latest()` via `wp_cache_get/set`) resolvem problemas diferentes
e complementares — o primeiro não substitui o segundo.

Decisão do time: reavaliar escala e cache **depois** do simulado de 22–24/09, com
Cloudflare já configurado e mais um ponto de dado real do TSE para confirmar (ou
não) a folga estimada aqui.

## Pendências para produção

- executar e documentar os simulados; não declarar homologação antes deles;
- implementar EA14/EA15 apenas se a cobertura crescer além de cargo × UF (não é
  bloqueio para o escopo de 2026 — ver avaliação de performance acima);
- ~~configurar cron real a cada minuto~~ mitigado em 15/09 com `bin/tse-tick-loop.sh`
  via crontab do host; falta migrar para o cron definitivo do ambiente de produção
  **e** para o homolog (confirmado desatualizado);
- sincronizar código corrigido hoje (2º turno, votos anulados, seats do Senado,
  card, CSS) com homolog e produção;
- ~~adicionar `Cache-Control` em `tse/v1/resultado`~~ concluído na versão 2.3.0;
  o endpoint legado do widget agora também devolve ETag, 304 e a política de cache
  de borda;
- Redis/Memcached, InnoDB e CDN que preserve cabeçalhos;
- rodar o teste de carga já definido (200 VUs, p95 < 400 ms, erro < 1%) — não
  depende do TSE, pode ser feito agora;
- validar observabilidade, rollback, retenção e treinamento editorial;
- migrar/remover classes legadas após validar todos os shortcodes existentes.

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
- a saúde REST informa se `ZipArchive` está disponível. A importação de CSVs em
  ZIP requer `php-zip` no ambiente.

## Repositório — código movido para submódulo (22/09/2026)

O plugin deixou de viver dentro dos monorepos de site. Fonte de verdade agora é
<https://gitlab.okn.com.br/okn/custom-plugins/tse-resultado> (branch `main`),
registrado como submódulo Git em `www/wp-content/plugins/tse-apuracao` nos
repositórios de cada publisher (mesmo padrão já usado para `okndso`, `oknfeed`
e o tema).

Motivo: o plugin não depende de nenhuma marca/publisher específico (sem
branding de cliente no código desde esta revisão) e passou a ser reutilizado
em mais de um site do grupo — fazia sentido ter histórico e versionamento
próprios, com cada site fixando o commit/branch que quiser.

Consequências práticas:

- qualquer alteração no plugin agora exige dois commits: um no repositório do
  plugin, outro no monorepo do site fazendo o bump do ponteiro do submódulo
  (`git -C www/wp-content/plugins/tse-apuracao pull` + `git add` do path no
  monorepo). Esquecer o segundo passo é a causa clássica de "produção rodando
  código velho";
- `git clone` do monorepo não traz o código do plugin sozinho — é preciso
  `git submodule update --init --recursive` (a pipeline de deploy do
  Pernambuco, `.gitlab-ci.yml`, já faz isso automaticamente nos jobs
  `deploy_job` — branches `release/*`, homolog — e `deploy_feature_job` —
  branch `main`, produção; ambientes fora dessa pipeline precisam rodar
  manualmente);
- Pernambuco: convertido e pushado em `release/1.0.0` (commit `1823ed5e`);
- Espírito Santo: convertido e pushado como branch separado
  `release/1.0.0-tse-submodule` (não sobrescrito direto em cima do
  `release/1.0.0` daquele repositório, para revisão antes do merge — não foi
  confirmado se o `.gitlab-ci.yml` de lá também inicializa submódulos
  automaticamente).

Ajustes de portabilidade feitos junto com a extração (para o plugin poder ser
instalado em qualquer WordPress, sem depender deste grupo):

- removido `Author`/`Plugin URI` fixos ("Tribuna Online") do cabeçalho de
  `tse-apuracao.php`;
- `bin/tse-tick-loop.sh` parametrizado por variável de ambiente
  (`TSE_APURACAO_CONTAINER`, `TSE_APURACAO_PLUGIN_PATH`,
  `TSE_APURACAO_LOG_FILE`) em vez de nome de container Docker e caminho de
  log fixos de uma instalação específica;
- `bin/tse-tick.php` agora recusa execução fora de CLI (fechava um endpoint
  HTTP anônimo, já que `wp-content/plugins/...` costuma ser publicamente
  acessível) e `bin/` ganhou `index.php` silenciador;
- documentação sem caminhos absolutos de uma instalação de desenvolvimento
  específica;
- corrigida a descrição do circuit breaker: 404 usa backoff por fonte, não
  bloqueio global de 10 min (o texto antigo estava desalinhado com o que o
  código faz desde a v2.3.0, ver "Autonomia operacional" acima).

## Histórico desta rodada

- painel visual e fluxo de jobs;
- configuração automática EA11 e fontes EA20;
- importação automática de candidatos;
- parser EA20 aninhado e testes zero/final;
- proteção de taxa, HTTP condicional e circuit breaker;
- frontend ajustado para turno/status/etiquetas;
- documentação consolidada;
- **15/09/2026, tarde:** diagnosticado e corrigido ao vivo durante o 1º simulado:
  atraso de sincronização (cron real de emergência), "2º turno" marcado como
  eleito, votos anulados/brancos/nulos ausentes do totals, `seats` do Senado
  incorreto para 2026, DOM não reordenava no polling do Senado, CSS não
  carregava fora de página singular; criado `[tse_apuracao_card]`; avaliação de
  performance publicada (109 disputas = teto real de 2026, não estimativa);
  decisão de retomar cache (Cloudflare) depois do 2º simulado;
- próximo: sincronizar fixes com homolog/produção, cron real fora do Docker
  local, `Cache-Control` em `tse/v1/resultado`, teste de carga, 2º simulado
  (22–24/09), só então declarar homologado;
- **22/09/2026:** plugin extraído para repositório próprio
  ([tse-resultado](https://gitlab.okn.com.br/okn/custom-plugins/tse-resultado)),
  registrado como submódulo em Pernambuco e Espírito Santo, sem branding de
  publisher; validação ao vivo contra o TSE real (ambiente Simulado, 2º
  simulado, 1º dia): EA11 sincronizado (`ele2026`, 193 disputas), EA20
  coletado e normalizado para Presidente-BR, cache condicional (304)
  confirmado, nenhum bloqueio disparado, REST pública retornando `segundo_turno`
  corretamente; descoberta a divergência `and`/`tf` (não afeta a interface do
  plugin, registrada acima). Itens 3, 4, 6, 8, 9 e 10 do checklist do 2º
  simulado continuam pendentes.
