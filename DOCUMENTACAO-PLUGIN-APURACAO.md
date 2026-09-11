# Arquitetura — Plugin de Apuração Eleitoral

## Objetivo e limites

O plugin publica resultados oficiais do TSE em WordPress com baixa latência, rastreabilidade e recuperação diante de falhas da origem. Ele atende múltiplas eleições, turnos, cargos e abrangências sem acoplar a modelagem a 2026. A configuração inicial de 2026 cria Presidente (Brasil, uma vaga), Governador (por UF, uma vaga) e Senador (por UF, duas vagas).

O sistema **não** trata dados coletados como resultado oficial sem validação, não consulta o TSE em páginas públicas e não apaga snapshots. Dados eleitorais e fotos devem respeitar os termos de uso, LGPD e a política editorial do veículo.

## Auditoria inicial

Em 11/09/2026 o rascunho inicial foi criado em um workspace vazio, sem runtime PHP. A implementação foi então integrada ao repositório WordPress `tse-apuracao`, preservando o widget e o shortcode legados como camada de compatibilidade. A validação estática e os artefatos de carga foram incluídos, mas a execução WordPress/PHP requer ambiente local ou CI.

## Modelo de dados

Todas as tabelas usam o prefixo do site e são criadas por `dbDelta`; nenhuma informação de alto volume usa `postmeta`.

| Tabela | Papel | Índices relevantes |
| --- | --- | --- |
| `ae_elections` | eleições e configuração por ano | `slug`, `(year,status)` |
| `ae_contests` | turno + cargo + abrangência + vagas | `(election_id,round_no,position_code,scope_code)` |
| `ae_candidates` | cadastro importado dos dados abertos | `(election_id,external_id)`, `(contest_id,ballot_number)` |
| `ae_snapshots` | artefato bruto, hash, data e totais de cada coleta | `(contest_id,status,captured_at)` |
| `ae_result_rows` | ranking materializado de cada snapshot | `(snapshot_id,external_candidate_id)`, ranking |
| `ae_jobs` | importação/coleta retomável | estado, horário e lock |
| `ae_logs` | eventos operacionais sem dados sensíveis | evento/data e nível/data |

O identificador externo do TSE é preservado, o payload bruto recebe SHA-256, e cada linha de resultado referencia o snapshot. Isso permite reproduzir uma publicação, comparar coletas e manter a última versão válida.

## Fluxo de dados

```text
TSE dados abertos / EA14, EA15, EA20
              │ (somente job agendado)
              ▼
validação + normalização ── falha ──► log/retry + último snapshot válido
              │
              ▼
snapshot imutável + result_rows materializados
              │ invalida somente a chave afetada
              ▼
object cache / CDN (ETag + Last-Modified + SWR)
              │
              ▼
REST compacto → bloco Gutenberg, shortcode e páginas editoriais
```

O coletor aceita apenas HTTPS em `*.tse.jus.br`, tem timeout, redirecionamentos limitados e tentativas exponenciais. A implementação atual normaliza os aliases conhecidos dos formatos EA14/EA15/EA20 em uma única fronteira. Antes da eleição, fixtures reais de cada arquivo devem ser aprovadas no teste de contrato; nunca se deve alterar a estrutura de resposta diretamente nas páginas públicas.

## Operação e desempenho

- **Leituras:** `AE_Results` busca uma disputa e seu último snapshot válido por índices, e monta uma resposta compacta uma vez no object cache. Não soma votos nem chama origem em leitura.
- **HTTP/CDN:** cada endpoint público devolve `ETag`, `Last-Modified`, `s-maxage=60`, `stale-while-revalidate=300` e `stale-if-error=3600`. A resposta 304 evita transferência de JSON repetido.
- **Falhas:** snapshots incompletos são rejeitados antes de gravar resultados. Um erro de coleta mantém o snapshot válido anterior publicado.
- **Jobs:** jobs usam lock de object cache, claim condicional no banco, token e TTL. Importações de JSON e de CSV/ZIP dos dados abertos têm cursor de 250 registros; o CSV/ZIP é baixado uma vez para arquivo temporário, lido por streaming e retomado pela linha mesmo após nova execução. Erros usam backoff e encerram como `failed` após cinco tentativas.
- **Escala:** em produção, configure Redis/Memcached para object cache, WP-Cron real (`wp cron event run ae_run_jobs`) a cada minuto, fila dedicada se disponível, banco com InnoDB, CDN que respeite os cabeçalhos e retenção documentada de payloads.

## API

Público, cacheável:

- `GET /wp-json/apuracao/v1/results/{eleicao}/{turno}/{cargo}/{abrangencia}`
- `GET /wp-json/apuracao/v1/candidates/{eleicao}/{id-externo}`

Administrativo, requer `manage_options` e nonce/cookie WordPress:

- `POST /wp-json/apuracao/v1/admin/jobs` — enfileira `import_candidates` ou `collect_results`.
- `POST /wp-json/apuracao/v1/admin/elections` — cria/atualiza a eleição; envie `id` para atualizar.
- `POST /wp-json/apuracao/v1/admin/contests` — cria/atualiza turno, cargo, abrangência e vagas; envie `id` para atualizar.
- `GET /wp-json/apuracao/v1/admin/health` — versão do schema, cron, jobs pendentes/falhos e último snapshot.

Exemplo de coleta: `{ "type":"collect_results", "payload": { "contest_id":123, "kind":"EA20", "source_url":"https://...tse.jus.br/...json" } }`. A URL é sempre configurada pelo operador; o plugin não adivinha paths de arquivos por UF/cargo.

## Publicação editorial

O bloco **Apuração eleitoral** renderiza no servidor e é adequado a home, matéria e especial. Seus atributos são eleição, turno, cargo, abrangência e título. O shortcode equivalente é:

`[apuracao eleicao="eleicoes-2026" turno="1" cargo="0003" abrangencia="SP" titulo="Governo de São Paulo"]`

Há também o shortcode-base `[apuracao_candidato id="id-externo"]`; o template editorial de candidato é a próxima fase, após a definição de URL/permalink do site.

## Fases de entrega

1. **Fundação (implementada):** schema, eleição 2026, jobs/locks, coletor, snapshots, cache, REST, bloco/shortcodes, health e carga.
2. **Operação editorial:** CRUD completo de eleições/disputas, tela de filas/logs, permissões específicas, templates de candidato e páginas de apuração.
3. **Homologação TSE:** fixtures oficiais, verificador por contrato de EA14/15/20, comparação de totais, observabilidade e alertas.
4. **Produção:** cache distribuído/CDN, testes de pico, plano de rollback e treinamento da redação.

## Validação dos simulados de 2026

As janelas informadas para simulado são **15–17/09/2026** e **22–24/09/2026**. Como a entrega foi iniciada em 11/09/2026, não é possível alegar que a coleta futura já foi validada. O código está preparado para a homologação; o procedimento obrigatório é:

1. Criar uma disputa de teste por cargo/UF e registrar as URLs oficiais EA14, EA15 e EA20 observadas na janela, sem alterar a produção.
2. Salvar uma cópia sanitizada de cada payload em `tests/fixtures/`, ampliar os aliases de normalização apenas se o contrato o exigir e executar testes de contrato.
3. Conferir `total_votes`, número de seções, ranking, duas vagas de Senado e hash do payload contra o painel oficial.
4. Interromper a origem por alguns minutos e confirmar que API, bloco e CDN continuam exibindo o último snapshot válido com cabeçalho cacheável.
5. Executar `k6 run -e BASE_URL=https://homolog.example tests/load/results.js`; o alvo inicial é p95 abaixo de 400 ms e erro abaixo de 1% sob 200 VUs.
6. Registrar o resultado, URL, hash, versão do plugin e decisão de aprovar/rejeitar em `ae_logs`/runbook.

## Limitações conhecidas desta fase

- Não há runtime PHP/WordPress neste workspace, portanto não foi possível rodar lint, PHPUnit, ativação ou `dbDelta` contra MySQL.
- A interface administrativa ainda é um painel de saúde; configuração e fila são expostas pela REST API até a fase editorial.
- Os layouts e os campos exatos de EA14/EA15/EA20 devem ser confrontados com arquivos dos simulados; os aliases atuais são deliberadamente isolados para essa adaptação.
- CSV/ZIP pressupõe o delimitador `;` empregado nos dados abertos do TSE e um único CSV de candidatos por arquivo. Uma fonte com outro layout deve ser adicionada como adaptador e coberta por fixture antes da importação.
