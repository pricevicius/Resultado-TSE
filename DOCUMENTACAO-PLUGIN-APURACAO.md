# TSE Apuração — arquitetura, operação e plano

## Estado deste documento

Este é o runbook técnico e funcional do plugin. Ele registra o que foi implementado, o que foi decidido e o que ainda está planejado. Toda mudança que altere fonte, contrato JSON, frequência, cache, fila, interface administrativa ou publicação deve atualizar este arquivo.

Última revisão: 15/09/2026 (janela do 1º simulado do TSE, 15–17/09).

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

### Cenário zerado

EA20 válido com `v.tv = 0`, `s.st = 0`, `s.pst = 0` e `and = "n"` é aceito. A interface exibe **Aguardando apuração**, candidatos zerados e 0,00%.

### Cenário 100%

Com `and = "f"`/`tf = "s"`, a interface exibe **Totalizado**. Cada candidato recebe a situação oficial: **Eleito** quando `e = "s"`; os demais exibem `st`, como **Não eleito**. Senado suporta duas vagas sem inferência por ranking.

Fixtures: `ea20-zero.json`, `ea20-final.json` e `ea20-minimal.json`.

## Limite de acesso e bloqueios

O TSE informa 100 requisições/s por IP e bloqueio de dez minutos quando excedido. Respostas 304 contam; URLs 404 repetidas também podem causar bloqueio.

Proteções implementadas:

- teto conservador de **20 requisições/s** por origem WordPress;
- fila com lock contra workers concorrentes do plugin;
- `If-None-Match`/`If-Modified-Since`;
- nenhuma versão nova para 304 ou SHA repetido;
- circuit breaker de dez minutos após 403, 404 ou 429;
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

1. sincronizar **Simulado** e registrar EA11, ciclo, pleito e eleições;
2. confirmar zero inicial e `and = "n"`;
3. comparar parcial com o portal Resultados;
4. confirmar 100%, `and`, `tf`, `md`, eleitos/não eleitos e duas vagas de Senado;
5. validar “2º turno” sem marcar como eleito;
6. medir quantidade e pico de requests no IP de saída;
7. testar 304 e indisponibilidade mantendo último snapshot;
8. confirmar no navegador que nenhum domínio TSE é acessado;
9. executar carga da REST local;
10. anexar fixtures sanitizadas e registrar hash, horário, versão e aprovação.

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
  (4x por minuto) via `docker exec` no container da aplicação, com saída em
  `~/jobs/tribunaonline/tse-tick.log`;
- crontab real do host (usuário `price`) chamando o loop a cada minuto:
  `* * * * * .../tse-apuracao/bin/tse-tick-loop.sh`.

O lock interno do `AE_Job_Runner` (`wp_cache_add` com TTL de 55 s) garante que essas
chamadas extras nunca rodem em paralelo com o WP-Cron nem entre si — na pior das
hipóteses, uma chamada recém-disparada encontra o lock ocupado e retorna
imediatamente sem custo. O WP-Cron continua ativo como redundância.

Resultado logo após a ativação: fila caiu de 105 para a faixa de dezenas em menos de
2 minutos e a idade máxima dos snapshots voltou para dentro do intervalo configurado
(< 90 s). Ver `~/jobs/tribunaonline/tse-tick.log` para o histórico de execuções.

### Ação de acompanhamento

- migrar esse cron "de emergência" para um mecanismo suportado em produção (ex.:
  cron de sistema no servidor real, não um host de desenvolvimento) antes do
  segundo simulado (22–24/09) e da eleição oficial;
- considerar paralelizar `collect_results` (hoje 1 worker) se, mesmo com disparo a
  cada 15 s, o TSE responder mais lento que o esperado sob carga real de eleição;
  o teste de hoje não indicou essa necessidade (82 jobs em 40 s com fonte
  respondendo normalmente), mas vale monitorar no simulado de 22–24/09.

## Pendências para produção

- executar e documentar os simulados; não declarar homologação antes deles;
- implementar EA14/EA15 antes de mapas nacionais;
- ~~configurar cron real a cada minuto~~ mitigado em 15/09 com `bin/tse-tick-loop.sh`
  via crontab do host; falta migrar para o cron definitivo do ambiente de produção;
- Redis/Memcached, InnoDB e CDN que preserve cabeçalhos;
- validar observabilidade, rollback, retenção e treinamento editorial;
- migrar/remover classes legadas após validar todos os shortcodes existentes.

## Histórico desta rodada

- repositório consolidado em `/home/price/jobs/tribunaonline/www/wp-content/plugins/tse-apuracao`;
- painel visual e fluxo de jobs;
- configuração automática EA11 e fontes EA20;
- importação automática de candidatos;
- parser EA20 aninhado e testes zero/final;
- proteção de taxa, HTTP condicional e circuit breaker;
- frontend ajustado para turno/status/etiquetas;
- documentação consolidada;
- próximo: lint, testes WordPress, smoke do admin, revisão, commit e push.
