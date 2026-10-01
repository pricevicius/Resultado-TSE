# TSE Apuração — arquitetura, operação e plano

## Estado deste documento

Este é o runbook técnico e funcional do plugin. Ele registra o que foi implementado, o que foi decidido e o que ainda está planejado. Toda mudança que altere fonte, contrato JSON, frequência, cache, fila, interface administrativa ou publicação deve atualizar este arquivo.

Última revisão: 01/10/2026 (versão 2.5.1, que torna a extensão `php-zip` requisito de ativação). Desde 28/09: saúde do disparo da coleta (2.3.8), marcadores `#NE` e vínculo do candidato com a disputa (2.3.8), intervalo por tipo de disputa, Slack removido e snapshot em transação (2.4.0), paginação do catálogo (2.4.1), candidatos que saem do CSV, reimportação agendada, avisos de saúde, gravação do snapshot em lote e testes em WordPress real (2.5.0).

**O que ainda está em aberto** (código, validação parcial, implantação por projeto e decisões já tomadas) está em [PENDENCIAS.md](PENDENCIAS.md).

## Objetivo

Entregar uma experiência plug and play para publishers em WordPress:

1. o administrador escolhe **Oficial** ou **Simulado**, informa a **UF deste site** e clica em **Sincronizar configuração do TSE**;
2. o plugin lê o EA11 (`ele-c.json`) e cria eleições, turnos, cargos e abrangências — só nascem **ligadas** para coleta as disputas da UF informada (+ Presidente, que é nacional); as das outras 26 UFs nascem desligadas automaticamente;
3. o administrador escolhe a eleição e clica em **Buscar e importar candidatos** — a importação já herda o mesmo recorte (UF + cargos ligados), sem precisar escolher nada de novo;
4. o plugin localiza o pacote oficial no Portal de Dados Abertos, baixa o ZIP nacional mas processa só o CSV da UF do site e só os cargos habilitados;
5. a coleta ocorre no servidor, grava snapshots auditáveis e abastece a API REST do WordPress;
6. blocos e shortcodes atualizam no navegador consultando apenas o próprio WordPress.

Nenhum visitante consulta o TSE diretamente. Nenhum operador precisa montar ou colar uma URL.

### Guia rápido — primeira instalação num site novo

Passo a passo pra quem está configurando o plugin pela primeira vez, do zero:

1. **Ative o plugin.** As tabelas e o agendamento de coleta são criados sozinhos assim que o WordPress carrega a primeira vez com o plugin ativo — nenhum comando manual é necessário.
2. Vá em **Apuração → Configuração**.
3. Escolha o **Ambiente**: use **Simulado** pra testar (só funciona nos horários que o TSE anuncia); use **Oficial** só depois que o TSE publicar a eleição real (antes disso ele responde com o ciclo antigo e a sincronização falha de propósito, avisando isso).
4. **Preencha a UF deste site** (ex.: `ES`, `PE`). Esse campo é o que impede o site de puxar coleta e candidatos do Brasil inteiro sem querer — **não pule esse campo**, mesmo que ele não seja tecnicamente obrigatório.
5. Clique em **Sincronizar configuração do TSE**. Isso cria as eleições e ~109 disputas nacionais no banco, mas só liga pra coleta as da sua UF + Presidente.
6. (Opcional) Vá em **Seleção de disputas** só se precisar de um ajuste fino diferente do padrão (ex.: ligar também uma disputa de outra UF por algum motivo específico, ou desligar algo da própria UF que não vai ser publicado). Na maioria dos casos não precisa mexer aqui.
7. Vá em **Importar e coletar** e clique em **Buscar e importar candidatos**. A tela mostra antes de clicar quais UFs/cargos serão trazidos — confirme que bate com o que você espera.
8. Publique com `[tse_apuracao cargo="governador" uf="es"]` (placar de uma disputa) ou `[apuracao_candidatos]` (catálogo de candidatos).

Se `[tse_apuracao ...]` e `[apuracao_candidatos]` não aparecem no editor de blocos, publique o shortcode direto no conteúdo do post/página — os dois funcionam como shortcode clássico, sem precisar do bloco.

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
- **Configuração:** seletor Oficial/Simulado, campo **UF deste site** (define quais disputas nascem ligadas na sincronização) e botão de sincronização automática do EA11. A criação local de 83 disputas fica recolhida em “Estrutura local para desenvolvimento”.
- **Seleção de disputas:** ajuste fino opcional — liga/desliga disputa por disputa. Não é mais o único lugar que decide o recorte por UF (isso já acontece na sincronização), só sobrepõe caso a caso quando necessário.
- **Importar e coletar:** botão de importação automática de candidatos e explicação da coleta protegida. A tela mostra, antes de importar, quais UFs e cargos serão trazidos (derivado das disputas ligadas). Não existem campos de URL no fluxo comum.
- **Fila e progresso:** jobs, tentativas, erros, cursor e retry.
- **Logs:** cem eventos mais recentes.
- **Como usar:** o manual dos shortcodes dentro do admin (2.5.0): para cada um (`[tse_apuracao]`, `[tse_apuracao_card]`, `[apuracao_candidatos]`, `[apuracao_candidato]`, `[apuracao_navegacao]`, o legado `[apuracao]` e o bloco), os atributos, padrões e valores, exemplos com botão *Copiar* que usam a UF do site, os links de catálogo filtrado (`ae_busca`, `ae_cargo`, `ae_uf`, `ae_partido`, `ae_pagina`, `ae_candidato`), a tabela de cargos (lida de `TSE_API::CARGOS`) e um quadro "se algo não aparece". Fica em `includes/class-admin-guide.php`; o teste `tests/wp-integration.php` falha se um shortcode registrado não estiver documentado ou se algum exemplo não executar. **Ao criar ou mudar um shortcode ou atributo, atualize essa aba.**

A antiga tela duplicada em **Configurações > TSE Apuração** deixou de ser registrada. Opções antigas permanecem apenas para compatibilidade visual e mock do shortcode legado.

## Importação de candidatos implementada

O botão deriva o ano da eleição e usa o pacote oficial `consulta_cand_{ANO}.zip` — esse pacote é sempre nacional, o TSE não oferece download por UF, então o download em si não encolhe. O que encolhe é o processamento:

- baixa o ZIP uma vez para arquivo temporário (o arquivo inteiro, ~30 CSVs, um por UF + Brasil);
- **processa só o(s) CSV(s) da(s) UF(s) das disputas ligadas** (derivado de `AE_Collection_Policy::import_scope()`, que lê `collection.enabled` de cada disputa — a mesma seleção usada pra coleta de resultado). Se Presidente estiver ligado, inclui também os arquivos `BR`/`BRASIL`;
- dentro de cada CSV, **filtra linha por linha pelo cargo** (`CD_CARGO`) das disputas ligadas e **só importa titulares** (Presidente, Governador, Senador, Deputado Federal/Estadual/Distrital). **Vice (cargos 2 e 4) e suplentes (9 e 10) não são importados**, nem quando nenhuma disputa está ligada;
- se nenhuma disputa estiver ligada (UF do site não configurada), cai no comportamento antigo e processa o Brasil inteiro, sem travar nem falhar silenciosamente;
- lê por streaming e lotes de 250 linhas (contra o arquivo, não contra as linhas já filtradas — o cursor de retomada continua consistente mesmo descartando linha por cargo);
- salva cursor de arquivo + linha para retomada;
- importa `SQ_CANDIDATO`, nomes, número, partido e situação;
- **vincula o candidato à disputa** (`ae_candidates.contest_id`) por cargo + UF + turno do CSV, na própria importação (Presidente é `BR`; Deputado Distrital é a disputa `0008` do DF). Com isso os filtros de cargo e UF do catálogo funcionam antes de a apuração começar. Reimportar preenche os candidatos já existentes, e a importação nunca apaga um vínculo que o EA20 já tenha gravado;
- permite que o EA20 complete candidatos ausentes e derive a foto oficial por `sqcand`.

**Dependência de ambiente (requisito de instalação, desde a 2.5.1):** requer a extensão `php-zip` (`ZipArchive`). A ativação do plugin é **recusada** com uma mensagem explicando o que instalar (`AE_Plugin::missing_requirements()`, chamada pelo hook de ativação); se a extensão desaparecer depois, as telas do admin mostram um aviso permanente e a REST de saúde lista `missing_requirements`. Para desenvolvimento ou teste, `TSE_APURACAO_ALLOW_NO_ZIP` no `wp-config.php` (ou o filtro `ae_missing_requirements`) dispensa a verificação. Confirmar no `docker/Dockerfile` local (`php8.2-zip`) e em homolog/produção.

**Resiliência:** o nome/partido/número de cada candidato também é gravado direto em `ae_result_rows` no momento da coleta (não só via `candidate_id` em `ae_candidates`) — se o cadastro de candidatos ainda não existir ou estiver temporariamente fora de sincronia com o resultado, a tela mostra o nome capturado no snapshot em vez de cair para o ID numérico do TSE.

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

### `[tse_apuracao_resumo]` — widget simples para a home (2.5.0)

Uma caixa única com uma linha por disputa (cargo · UF, líder com partido, percentual e % apurado), selo
geral (*Ao vivo*, *Apuração concluída* ou *Dados atrasados*, calculado sobre as disputas que já têm dado) e
link opcional para a apuração completa. Atributos: `disputas` (lista `cargo:uf[:turno]` separada por vírgula,
até 8; padrão Presidente e Governador e Senador da UF do site), `titulo` (padrão "Apuração"), `link`,
`link_texto`, `atualizar` (padrão 60; 0 desliga) e `classe`. Lê só os snapshots locais; o navegador atualiza
cada linha pelo mesmo endpoint `tse/v1/resultado` (`limite=1`), sem consultar o TSE. Código em
`includes/class-resumo.php`, `templates/resumo.php`, `assets/js/tse-resumo.js` (depende de `tse-live`, que
fornece `TSEConfig`) e o bloco `.tse-resumo` em `assets/css/tse-apuracao.css`, com variáveis CSS
sobrescrevíveis. Para destacar uma disputa só, continua valendo o card abaixo.

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

- cache próprio de fotos, se a política editorial exigir independência do CDN TSE;
- páginas individuais, vice/suplentes (hoje fora da importação, por decisão) e dados complementares;
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

**Pegadinha confirmada em 24/09/2026:** `crontab -e`/`crontab -l` **não** herda
o ambiente do shell interativo (`~/.bashrc`/`~/.zshrc`) nem variáveis exportadas
manualmente antes de editar o crontab — se `TSE_APURACAO_CONTAINER` não estiver
declarada dentro do próprio arquivo do crontab (como variável, nas linhas antes
do agendamento), o script cai no branch "roda PHP local" mesmo com o container
Docker no ar, e falha silenciosamente com `php: not found` (ambiente sem PHP no
host, ex.: WSL) — sem travar o cron nem gerar alerta, só parando de coletar. Já
aconteceu de o container estar de pé há 20+ minutos com o tick de emergência
completamente parado por causa disso. Forma correta de configurar, direto no
`crontab -e`:

```
TSE_APURACAO_CONTAINER=tribuna_espiritosanto-app
TSE_APURACAO_LOG_FILE=/caminho/para/o/log
* * * * * /caminho/para/tse-apuracao/bin/tse-tick-loop.sh
```

Ao subir o ambiente local (`docker compose up` ou equivalente) depois de um
período parado, sempre conferir se o log configurado está de fato crescendo
(`tail -f`) e não só se o container está `Up` — os dois foram confundidos nesta
sessão.

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
  segundo simulado (22–24/09) e da eleição oficial — **atenção à pegadinha da
  variável de ambiente descrita acima**, ela se repete em qualquer ambiente
  novo (homolog, produção, ou local recriado);
- considerar paralelizar `collect_results` (hoje 1 worker) se, mesmo com disparo a
  cada 15 s, o TSE responder mais lento que o esperado sob carga real de eleição;
  o teste de hoje não indicou essa necessidade (82 jobs em 40 s com fonte
  respondendo normalmente), mas vale monitorar no simulado de 22–24/09.

**Confirmado em 24/09/2026, sem depender do TSE estar lento:** reabilitar de uma
vez as 27 disputas de Deputado Federal (desabilitadas por engano na tela de
Seleção — ver "Plano de prontidão" abaixo) sozinho já foi suficiente pra estourar
o orçamento do worker sequencial por ~10-12 min — a fila subiu de ~33 para 106
jobs e **82 das 108 disputas ativas passaram a "atrasado" (>3min sem checagem)**,
incluindo disputas majoritárias que antes estavam saudáveis (ex.: Governador-ES).
Causa: Deputado Federal tem centenas de candidatos por UF, então cada job desse
tipo é muito mais pesado que uma majoritária simples (RJ chegou a levar 2min30s
numa única coleta, contra ~1s de Governador/Senador). O sistema se autorrecuperou
sozinho (fila voltou a zero atrasos, mediana 25s/pior caso 82s) sem intervenção
manual além do fix inicial — mas confirma que **qualquer reativação em lote de
disputas de Câmara (Deputado Federal/Estadual, 27+27=54 no total) durante a
apuração real pode gerar alguns minutos de atraso generalizado**, não só nas
disputas recém-reativadas. Evitar reativar grupos inteiros de uma vez fora de
uma janela de baixo tráfego; se precisar, fazer em lotes menores.

## Plano de prontidão para a eleição

Primeiro turno previsto para **04/10/2026** (primeiro domingo de outubro, regra
fixa da legislação eleitoral) — a partir de hoje (24/09), **faltam ~10 dias**.
A janela do 2º simulado (22–24/09) termina hoje; não há mais nenhuma rodada de
validação com dado real do TSE agendada antes da eleição oficial. Prioridades
abaixo, em ordem de risco caso não sejam feitas:

**P0 — bloqueia ir ao ar com segurança:**

- ~~sincronizar os fixes de 15/09 com homolog~~ **confirmado em 24/09**: o
  `deploy_job` do homolog roda `git submodule foreach ... checkout main && pull`
  em todo push pra `release/*`, então os submódulos sempre vão pro HEAD do
  `main` deles — homolog já está rodando o código atual (verificado: `$runoff`
  do 2º turno e `votos_anulados`/`votos_brancos` presentes). **Falta ainda
  produção** (`deploy_feature_job`, branch `main`, mesmo mecanismo de
  submódulo, mas nunca verificado ao vivo);
- migrar `tse-tick-loop.sh` para cron de sistema real em homolog e produção,
  com a variável de ambiente configurada corretamente (ver pegadinha acima) —
  confirmado em 24/09 que o homolog **não tem esse cron instalado**
  (`no crontab for ci_user`), rodando só no WP-Cron por tráfego; avaliado como
  aceitável pro homolog em si (tráfego baixo, não é onde o público vê o
  resultado), mas **produção precisa desse cron antes do dia 4**;
- ~~confirmar se o `.gitlab-ci.yml` do Espírito Santo inicializa submódulos
  automaticamente~~ **confirmado em 24/09**: sim, todo `deploy_job` faz
  `submodule sync` + `update --init --recursive` + `foreach checkout main`;
- fechar os itens 3, 4, 6, 8 e 10 do checklist do 2º simulado (linha acima) —
  hoje é o último dia com dado real do TSE disponível para testar isso antes
  da eleição;
- **novo (24/09):** antes de ir ao ar em homolog/produção, conferir a aba
  **Seleção de disputas** — nesta sessão, as 27 disputas de Deputado Federal
  (todas as UFs) mais 1 Deputado Estadual (DF) estavam com `collection.enabled
  = false`, aparentemente desmarcadas sem querer ao testar essa tela nova
  (feature de 23/09). Ficaram travadas no snapshot de véspera sem nenhum erro
  visível — só percebido porque o "Última atualização" na tela não mudava.
  Reativar tudo de uma vez também expôs um efeito colateral: por ~10-12min, 82
  das 108 disputas ativas (inclusive majoritárias saudáveis, tipo
  Governador-ES) ficaram "atrasado" porque Deputado Federal tem centenas de
  candidatos por UF e cada job desse tipo é bem mais pesado que uma
  majoritária (RJ chegou a 2min30s numa única coleta) — o worker sequencial
  não aguentou a rajada de 27 jobs pesados de uma vez. Sistema se
  autorrecuperou sozinho, sem intervenção manual. **Lição:** reativar grupos
  inteiros de Câmara em lote pode gerar atraso generalizado temporário; evitar
  fazer isso em horário de pico ou fazer em lotes menores.

**P1 — reduz risco, não impede ir ao ar:**

- rodar o teste de carga contra a coleta (`AE_TSE_Client`) sob concorrência,
  não só contra a REST servindo snapshot em cache (o teste de 22/09 mediu
  isso, faltou o outro caminho);
- decidir e, se necessário, ligar Redis/Memcached/CDN com preservação de
  cabeçalhos (decisão estava explicitamente adiada para depois do 2º simulado
  — esse prazo é agora);
- validar observabilidade (alerta de fila/atraso), rollback e retenção de
  snapshot.

**P2 — pode esperar para depois da eleição:**

- EA14/EA15 e mapas municipais (só necessário se a cobertura crescer além de
  cargo × UF, o que não é o caso de 2026);
- páginas individuais de candidato, cache de fotos, atualização 4x/dia;
- assinatura X.509, monitoramento de mudança de contrato EA11/EA20;
- remoção de classes legadas.

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

### A paginação não aparece: roteiro de diagnóstico

Siga na ordem; o primeiro item explica a maioria dos casos.

1. **O site está com a versão certa?** A paginação existe a partir da **2.4.1**. Veja a versão
   no cabeçalho de `tse-apuracao.php` ou no canto da tela **Apuração**. Se for menor:
   - plugin em pasta comum: atualize os arquivos;
   - plugin como **submódulo git**: atualize o repositório do plugin **e** o ponteiro do
     submódulo no repositório do site (`git add <caminho do plugin>` + commit). Esquecer o
     ponteiro deixa o site com o código velho;
   - confirme a **branch**: `main`/`master` exige PHP 8.1+; `php7.4` é a variante para PHP 7.4.
     As duas recebem as mesmas correções, mas o site precisa estar na branch que usa.
2. **Há mais de 24 resultados no filtro atual?** Com 24 ou menos não existe navegação.
   Teste sem filtros, ou com `por_pagina="6"` para forçar várias páginas.
3. **O shortcode é o certo?** `[apuracao_candidatos]` (plural) é o catálogo paginado.
   `[tse_apuracao]` e `[tse_apuracao_card]` são os placares e não têm catálogo.
4. **Cache de página** (WP Rocket e similares, FastCGI do nginx, Cloudflare). HTML antigo em
   cache continua sem a navegação, e um cache que **ignora a query string** serve a página 1
   para todo `?ae_pagina=N`. Limpe o cache e garanta que `ae_pagina`, `ae_busca`, `ae_cargo`,
   `ae_uf` e `ae_partido` façam parte da chave de cache (ou que essas URLs não sejam
   cacheadas). O polling do placar não é afetado por isso, mas o catálogo é HTML renderizado.
5. **CSS antigo ou minificado.** Os assets são versionados por `AE_VERSION` (`?ver=2.4.1`),
   mas plugins de otimização e CDN podem manter o CSS velho. Sem o CSS novo a navegação ainda
   existe, só aparece como lista simples. Para confirmar que ela foi gerada, procure no HTML:

   ```bash
   curl -s 'https://SEU-SITE/pagina-do-catalogo/?ae_uf=SP' | grep -c 'ae-catalog-pagination'
   ```

   Resultado `0` com mais de 24 candidatos indica código desatualizado ou cache; `1` indica que
   o HTML está certo e o problema é CSS/tema.
6. **O tema esconde ou sobrescreve** `nav`, `.page-numbers` ou `ul` dentro do conteúdo.
   Inspecione o elemento `.ae-catalog-pagination` no navegador.
7. **O catálogo mostra poucos candidatos mesmo assim?** Confira quantos foram importados em
   **Apuração > Visão geral > Candidatos**. A importação traz só o CSV da UF e os cargos das
   disputas ligadas, e só titulares.

### Como testar em WordPress real (Docker)

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

### Testes em WordPress real

`tests/run-in-docker.sh` roda três suítes no container do site (sem PHPUnit) e sai com código diferente de
zero se alguma falhar. Todas restauram o que alteram.

| Suíte | O que cobre |
| --- | --- |
| `tests/admin-smoke.php` e `tests/wp-integration.php` | abas do admin, avisos de UF e InnoDB, importação de candidatos com CSV sintético (UF fictícia `ZZ`), marcação e retorno de removidos, agendamento, `persist_result` em lote, snapshot idempotente e rollback da transação com falha forçada |
| `tests/wp-collect.php` | coleta ponta a ponta com um **TSE falso** (`pre_http_request`, UF fictícia `ZY`, nenhuma requisição real): zerado, parcial, final, 2º turno, divergência `and`/`tf`, 304, 404 com backoff, 429 com pausa, 500, JSON inválido; REST, shortcode e card |
| `tests/http-admin.sh` | POSTs reais do admin por HTTP (cookie de administrador e nonce lidos da página): reimportação automática e Seleção de disputas, com nonce inválido, sem login e restauração de `ae_contests` |

Variáveis: `TSE_APURACAO_CONTAINER` (padrão `revistaforum-app`), `TSE_WP_PATH`, `TSE_PLUGIN_REL`, `TSE_SITE_URL`.
O TSE falso só reproduz o formato documentado do EA20; ele não substitui uma coleta contra o TSE real
(rede, bloqueio por IP). O `tests/bootstrap.php` do PHPUnit agora carrega `tse-apuracao.php`, mas o PHPUnit
ainda exige a biblioteca de testes do WordPress (`WP_TESTS_DIR`), que não está instalada neste ambiente.

### Medições locais (2.5.0)

Docker local, sem rede, sem concorrência: coleta de Deputado Federal com 1.100 candidatos (JSON de 173 KB)
~0,3 s e ~20 queries no caso normal (1ª coleta ~0,9 s e ~1.100 queries, uma por candidato novo; 304 em
~10 ms). Antes do A1 eram ~2 s e ~4.400 queries. Catálogo com 62 mil candidatos: página 1 em ~100 ms (era
~1.275 ms, antes de tirar `DISTINCT` e `data_json` da consulta); filtros por cargo+UF ~50–75 ms; busca por
nome ~200 ms; páginas muito profundas sem filtro ~500 ms. Isso é latência local: em produção o banco tem
latência de rede, e é por isso que o número de queries importa mais que os milissegundos.

## Versões de PHP e de WordPress (2.5.0)

| Branch | PHP | WordPress | Observação |
| --- | --- | --- | --- |
| `main` | 8.1+ | 5.5+ recomendado | código-fonte de referência |
| `php7.4` | 7.4+ | idem | mesmos commits da `main` |
| `php7.2` | **7.2+** | **4.9+** | gerada da `php7.4` por `tools/port-php72.py`; release `v2.5.0-php7.2` |

O plugin não depende da versão do WordPress: `includes/compat.php` define `str_contains`, `str_starts_with`,
`str_ends_with` e `wp_date()` quando não existem, e o bloco Gutenberg só é registrado quando o WordPress o
suporta (5.5+); nas versões antigas o shortcode `[tse_apuracao]` (e os demais) cobre o mesmo uso. Validado em
PHP 7.2.12 + WordPress 4.9.8 (front com todos os shortcodes, telas do admin autenticadas, REST e as suítes de
`tests/`), PHP 7.2.34 + WordPress 5.6, PHP 7.4.33 + WordPress 6.1 e PHP 8.2 + WordPress atual. PHP 7.2 e 7.3
estão sem correção de segurança: a variante existe para projetos que ainda não conseguiram migrar. Como
regenerar a `php7.2` está em [PENDENCIAS.md](PENDENCIAS.md) (seção E).

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
- **24/09/2026 (último dia do 2º simulado):** identificado e corrigido em
  ambiente local o mesmo tipo de falha silenciosa do incidente de 15/09: o
  crontab do host não tinha `TSE_APURACAO_CONTAINER` declarado, então o tick
  de emergência caía no branch de PHP local (inexistente no host) e falhava
  com `php: not found` a cada minuto, mesmo com o container Docker no ar —
  confirmado que isso não aparece como container parado nem erro óbvio, só
  como coleta silenciosamente desatualizada (pegadinha documentada acima, na
  seção do incidente de 15/09). Corrigido localmente; validado que o tick
  volta a rodar via `docker exec` (exit 0) e que `wp_ae_snapshots`/`wp_ae_jobs`
  mostram coleta em tempo real (fila sem acúmulo, snapshot com poucos segundos
  de idade). Criado o "Plano de prontidão para a eleição" abaixo com a mesma
  pegadinha marcada como P0 para homolog/produção, dado que o 1º turno é
  04/10/2026 (~10 dias a partir de hoje) e este foi o último dia com dado real
  do TSE para testar antes da eleição oficial.
