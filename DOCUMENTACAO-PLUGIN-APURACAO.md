# TSE Apuração — arquitetura e decisões técnicas

## Sobre este documento

Este é o runbook técnico do plugin: o que foi implementado, o porquê de algumas decisões não óbvias, e o que ainda está planejado. Complementa o [README.md](README.md), que cobre instalação e uso.

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

### Endpoints descobertos pelo plugin

| Uso | Oficial | Simulado |
| --- | --- | --- |
| Host | `https://resultados.tse.jus.br` | `https://resultados-sim.tse.jus.br` |
| Ambiente | `oficial` | `simulado` |
| EA11 | `/oficial/comum/config/ele-c.json` | `/simulado/simulado2026/comum/config/ele-c.json` |
| Candidatos | `https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_{ANO}.zip` | não se aplica |

O ciclo, pleito, eleição, abrangências e cargos não são fixados no código: vêm do EA11. O TSE costuma manter o EA11 apontando para o ciclo eleitoral anterior até a nova eleição ser oficialmente publicada — a tela de sincronização deve deixar claro quando o catálogo respondeu mas a eleição-alvo ainda não está disponível. O botão **Simulado** fica bloqueado enquanto a URL de simulado não é divulgada pelo TSE, para evitar 404 que podem levar a bloqueio de IP.

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
                         bloco/shortcode no visitante
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
- **Configuração:** seletor Oficial/Simulado e botão de sincronização automática do EA11. A criação local de disputas fica recolhida em "Estrutura local para desenvolvimento".
- **Importar e coletar:** botão de importação automática de candidatos e explicação da coleta protegida. Não existem campos de URL no fluxo comum.
- **Fila e progresso:** jobs, tentativas, erros, cursor e retry.
- **Logs:** eventos mais recentes.

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

Não se infere "eleito" pela posição, percentual ou texto aproximado. A fonte é `cand.e`; `cand.st` é preservado para "Eleito", "Não eleito", "2º turno" e demais situações.

**2º turno vs. eleito:** o TSE usa `cand.e = "s"` também para quem só avança ao 2º
turno (não apenas para quem está de fato eleito). O normalizador nunca marca
`elected = 1` quando `cand.st` contém "turno" (`class-tse-client.php`, variável
`$runoff`), independente do valor de `cand.e`. Use `mb_stripos()` para essa
checagem — um `preg_match` ingênuo com classe de caracteres `[ºo°]` falha
silenciosamente porque trata o "º" (multibyte UTF-8) byte a byte.

**Ranking:** o `rank_no` gravado (de `cand.seq`/`posicao`) é a ordem oficial do
TSE, não necessariamente a ordem por número de votos — isso é uma decisão do
TSE (pode refletir critério além do voto bruto), não um bug de normalização; o
campo `elected` continua vindo só de `cand.e` (com a ressalva do 2º turno
acima). No front-end, o polling ao vivo precisa reordenar o DOM a cada
atualização (`tse-live.js`, `list.appendChild(li)`), não só atualizar o texto
da posição — senão a ordem visual fica presa à primeira renderização.

**Totais de votos:** o normalizador expõe `v.van` (votos anulados), `v.vb`
(brancos) e `v.vn` (nulos) além do total geral, em `totals`:
`annulled_votes`/`annulled_percentage`, `blank_votes`/`blank_percentage`,
`null_votes`/`null_percentage`. Não exige migração de schema (`totals_json` é
JSON livre). Expostos no payload do shortcode como `votos_anulados`,
`votos_brancos`, `votos_nulos`, `pct_votos_anulados`, e no endpoint
`apuracao/v1/results` como `segundo_turno` (booleano) por candidato.

**Vagas do Senado:** `seats` é metadado informativo (exposto em
`contest_meta()`) e nunca influencia a lógica de eleito. O Senado renova por
terços alternados (2/3 ou 1/3, dependendo do ciclo) — ajuste o valor conforme
o ciclo eleitoral vigente em `class-tse-discovery.php`, `class-plugin.php` e
`class-admin.php`.

### Cenário zerado

EA20 válido com `v.tv = 0`, `s.st = 0`, `s.pst = 0` e `and = "n"` é aceito. A interface exibe **Aguardando apuração**, candidatos zerados e 0,00%.

### Cenário 100%

Com `and = "f"`/`tf = "s"`, a interface exibe **Totalizado**. Cada candidato recebe a situação oficial: **Eleito** quando `e = "s"` e a situação não é de 2º turno; os demais exibem `st`, como **Não eleito** ou **2º turno**.

Fixtures: `ea20-zero.json`, `ea20-final.json` e `ea20-minimal.json`.

## Limite de acesso e bloqueios

O TSE informa 100 requisições/s por IP e bloqueio de dez minutos quando excedido. Respostas 304 contam; URLs 404 repetidas também podem causar bloqueio.

Proteções implementadas:

- teto conservador de **20 requisições/s** por origem WordPress;
- fila com lock contra workers concorrentes do plugin;
- `If-None-Match`/`If-Modified-Since`;
- nenhuma versão nova para 304 ou SHA repetido;
- circuit breaker de dez minutos após 403, 404 ou 429;
- timeout, redirecionamentos limitados e HTTPS restrito a `*.tse.jus.br`;
- retry exponencial e último snapshot válido.

Com dezenas de disputas monitoradas uma vez por minuto, o volume de requisições fica bem abaixo do teto — ele é uma barreira de segurança, não uma meta.

## Shortcodes e API

O shortcode `[tse_apuracao]` e o bloco usam snapshots locais; o JavaScript faz polling na REST do WordPress.

```text
[tse_apuracao cargo="governador" uf="es" turno="1" limite="10" atualizar="60"]
```

APIs:

- `GET /wp-json/apuracao/v1/results/{eleicao}/{turno}/{cargo}/{abrangencia}`
- `GET /wp-json/apuracao/v1/candidates/{eleicao}/{id-externo}`
- compatibilidade: `GET /wp-json/tse/v1/resultado?cargo=governador&uf=es&turno=1`

### `[tse_apuracao_card]` — card compacto

Pensado para uso na home/grades, onde o widget completo (`[tse_apuracao]`) é
grande demais. Reaproveita toda a lógica de dados de
`TSE_Shortcode::snapshot_resultado()`; só muda a apresentação
(`templates/card.php`).

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
  viram um cabeçalho único da seção (`<header class="apuracao__header"><h2>`),
  para não repetir a mesma informação em cada mini-card.
- `classe` é aplicada no elemento **de fora** (o único card, se `limite=1`, ou o
  `.tse-card-secao`/`.tse-card-grid`, se `limite>1`) — nunca repetida em cada
  mini-card — para o front conseguir plugar num grid que já existe no tema.

Rótulo "ao vivo" tem 3 estados (`liveLabel()` em `tse-live.js`, compartilhado
com `[tse_apuracao]`): **Ao vivo** (em andamento) → **Apuração concluída**
(`progress = final`, TSE não vai mandar mais nada, não é atraso) → **Dados
atrasados** (sem novo snapshot há mais de 3 min e ainda não é final). Sem esse
terceiro estado, uma disputa já finalizada aparece como "atrasada" para
sempre, o que é enganoso.

### CSS/JS em páginas não singulares

`enqueue_assets()` (hook `wp_enqueue_scripts`) precisa carregar os assets
incondicionalmente, e não só quando `is_singular()` e o shortcode aparece em
`$post->post_content`. O shortcode pode aparecer em qualquer lugar (home,
widget, page builder, block theme) sem estar no conteúdo do post — carregar o
CSS/JS de dentro do próprio `render()`/`render_card()` roda tarde demais
(depois que `wp_head()` já imprimiu as tags `<link>`). Os arquivos são
pequenos (CSS ~10KB), então carregar sempre tem custo desprezível.

## Operação recomendada em produção

O agendamento padrão usa WP-Cron, que só dispara quando o site recebe
tráfego. Em janelas de apuração ao vivo, isso pode não ser frequente o
suficiente para manter os snapshots atualizados no intervalo configurado.

Recomendações:

- disparar `bin/tse-tick.php` por um cron real do sistema (ou um loop como
  `bin/tse-tick-loop.sh`), independente de tráfego, além do WP-Cron como
  redundância;
- o lock interno de `TSE_Job_Runner` (`wp_cache_add` com TTL) garante que
  disparos concorrentes nunca rodem em paralelo — uma chamada que encontra o
  lock ocupado retorna imediatamente sem custo;
- colocar um CDN/cache de borda (ex. Cloudflare) na frente do site ajuda na
  escala de leitura (visitantes fazem polling da REST do WordPress, não do
  TSE), mas exige enviar `Cache-Control` explícito nos endpoints usados pelo
  widget/card — hoje só `apuracao/v1/results` define isso
  (`class-rest.php::cached_response()`); `tse/v1/resultado`
  (`class-tse-shortcode.php::rest_resultado()`) ainda não, e sem cabeçalho
  explícito a REST API do WordPress manda `no-cache` por padrão;
- cache de borda HTTP (Cloudflare) e cache de objeto no PHP
  (`TSE_Results::latest()` via `wp_cache_get/set`) resolvem problemas
  diferentes e complementares.

## Planejado — ainda não implementado

### EA14/EA15 e mapas

Para mapas municipais em escala nacional, a próxima fase deve:

1. consultar EA14 para detectar UFs alteradas;
2. consultar EA15 somente nas UFs alteradas;
3. enfileirar EA20 somente para municípios/cargos alterados;
4. importar EA12 para relacionar código TSE, município, UF e IBGE;
5. criar snapshots e endpoint REST geográfico compacto;
6. carregar geometria estática pelo CDN do publisher e votos pela API local.

Só é necessário se a cobertura crescer para além de cargo × UF (ex.: municípios em anos de eleição municipal).

### Candidatos

- associar automaticamente candidato à disputa por ano/cargo/UF durante o CSV;
- cache próprio de fotos, se a política editorial exigir independência do CDN TSE;
- páginas individuais, vice/suplentes e dados complementares;
- atualização programada em intervalos fixos.

### Segurança e operação

- validar assinatura digital X.509 de cada JSON;
- monitorar alteração dos contratos EA11/EA20;
- alertas externos para fila, bloqueio, atraso e divergência;
- política de retenção/compactação de snapshots.

## Testando com os simulados do TSE

O TSE costuma abrir janelas de simulado antes da eleição oficial, publicando
dados de teste — inclusive casos extremos (posições fora de ordem de voto,
nomes com caracteres especiais, "2º turno" marcado como eleito) que valem a
pena usar como roteiro de validação:

1. sincronizar **Simulado** e conferir que EA11, ciclo, pleito e eleições batem;
2. confirmar zero inicial e `and = "n"`;
3. comparar parcial com o portal oficial de resultados;
4. confirmar 100%, `and`, `tf`, `md`, eleitos/não eleitos;
5. validar "2º turno" sem marcar como eleito;
6. medir quantidade e pico de requisições no IP de saída;
7. testar 304 e indisponibilidade mantendo o último snapshot;
8. confirmar no navegador que nenhum domínio TSE é acessado diretamente pelo visitante;
9. executar carga contra a REST local (ex.: alvo de p95 abaixo de 400 ms e erros abaixo de 1% sob a carga esperada do site).

O contrato do TSE não é 100% especificado publicamente — vale rodar mais de uma janela de simulado antes de confiar nos formatos vistos.
