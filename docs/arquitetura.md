# TSE Apuração — arquitetura e funcionamento

O que o plugin é e como funciona hoje: fontes do TSE, banco, painel, importação, normalização, limites de acesso e publicação no cliente. Sem histórico de projeto: ele está em [versoes.md](versoes.md) e em [historico/](historico/). Índice e regras de organização: [README.md](README.md).

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
- **vincula o candidato à disputa** (`ae_candidates.contest_id` e a tabela `ae_candidate_contests`, ver [Versão 2.6.0](versoes.md#versão-260--turnos-medição-da-coleta-e-testes-de-carga)) por cargo + UF + turno do CSV, na própria importação (Presidente é `BR`; Deputado Distrital é a disputa `0008` do DF). Com isso os filtros de cargo e UF do catálogo funcionam antes de a apuração começar. Reimportar preenche os candidatos já existentes, e a importação nunca apaga um vínculo que o EA20 já tenha gravado;
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

Uma caixa única com um bloco por disputa (cargo · UF, os primeiros colocados com partido, percentual e % apurado), selo
geral (*Ao vivo*, *Apuração concluída* ou *Dados atrasados*, calculado sobre as disputas que já têm dado) e
link opcional para a apuração completa. Atributos: `disputas` (lista `cargo:uf[:turno]` separada por vírgula,
até 8; padrão Presidente e Governador e Senador da UF do site), `limite` (candidatos por disputa, 1 a 10; padrão 3), `titulo` (padrão "Apuração"), `link`,
`link_texto`, `atualizar` (padrão 60; 0 desliga) e `classe`. Lê só os snapshots locais; o navegador atualiza
cada linha pelo mesmo endpoint `tse/v1/resultado` (com o `limite` da linha), sem consultar o TSE. Código em
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
