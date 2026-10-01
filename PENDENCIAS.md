# TSE Apuração — pendências e pontos em aberto

Estado em **01/10/2026**, versão **2.6.0**. Este arquivo é a lista do que ainda **não** foi feito, do que foi validado só em parte e do que depende do ambiente de cada projeto. O que já foi entregue está em [docs/](docs/README.md).

Está dividido em: **A.** código do plugin, **B.** validação que ficou parcial, **C.** implantação em cada projeto, **D.** decisões já tomadas, **E.** como publicar e retomar.

## A. Pendências do código do plugin

Valem para qualquer projeto que use o plugin. Esforço e risco são estimativas. O que dependia só de código e de ambiente local já foi feito (ver "Entregue" abaixo); o que sobrou **depende do TSE real ou de uma decisão**.

| # | Item | Depende de | Por que importa | Esforço | Risco |
| --- | --- | --- | --- | --- | --- |
| A10 | **Ler a medição de rede do TSE real** | primeiras coletas oficiais | O custo local está medido (leve ~50 ms, pesada ~250 ms) e a projeção diz que o worker satura perto de 0,6 s de rede por requisição no cenário nacional. Falta a latência real: a Visão geral e `/admin/health` (campo `perf`) já mostram média, p95 e máximo do download. Regra: p95 abaixo de ~0,4 s, nada a mudar; entre 0,4 e 0,6 s, menos UFs de Câmara ou `ae_heavy_interval` maior; acima, A2. | 15 min na primeira hora | baixo |
| A2 | **Duas pistas de worker com locks separados** | resultado do A10 | Só vale se o p95 do download passar de ~0,6 s com muitas UFs ligadas. Com poucas UFs a folga é grande. | cerca de 1 dia | médio a alto (concorrência e mudança de esquema) |
| A15 | **Ver o turno automático com o TSE real** | 2º turno publicado | O resolvedor e a troca ao vivo foram testados com dados sintéticos; falta ver o widget virar sozinho quando o EA20 do 2º turno sair e conferir o texto "2º turno" na home. | 30 min | baixo |
| A14 | **Confirmar que o sync cria a disputa do 2º turno** | EA11 do TSE com 2º turno publicado (a partir de 04/10) | O código do 2º turno (vínculo por turno, `segundo_turno`, `e=s` sem virar eleito) está testado com dados sintéticos; falta ver o EA11/EA20 reais do 2º turno e conferir cargo, UF e `round_no` da disputa nova. | 1 h | baixo |
| A13 | **Manter a branch `php7.2` (suporte contínuo, cliente específico)** | cliente no PHP 7.2 | A `php7.2` terá uso longo, então tem o mesmo nível das outras: gerada da `php7.4` por `tools/regen-php72.sh`, conferida por `tools/check-branches.sh` e testada com `tests/run-matrix.sh` (PHP 7.2.34 + WP 5.6 e PHP 7.2.12 + WP 4.9.8) a cada release, com tag `vX.Y.Z-php7.2`. Política completa em [docs/compatibilidade.md](docs/compatibilidade.md#suporte-contínuo-à-php72). O PHP 7.2 está sem correção de segurança desde 2020: o risco é do ambiente do cliente (recomendar isolamento de rede e CDN/WAF na frente e registrar a decisão de migrar); a branch só se aposenta quando o cliente confirmar a migração. | 15 a 20 min por release | baixo (o script falha em voz alta) |

**Entregue na 2.6.0:** A6 (documentação reorganizada em `docs/`: permanente, versões, testes e histórico separados, com regras em [docs/README.md](docs/README.md)), A9 (`ae_candidate_contests` e `contest_id` estável entre turnos: nada ficava vivo, então a migração foi trivial), medição de tempo da coleta (`AE_Perf`) e modelo de ocupação do worker, importação de ZIP real testada (e o bug do contador duplicado achado por ela), concorrência de 4 workers na mesma fila, carga na REST sem k6, conferência do front no navegador (desktop e celular). Detalhes em [docs/versoes.md](docs/versoes.md#versão-260--turnos-medição-da-coleta-e-testes-de-carga).

**Entregue na 2.5.0:** A1 (gravação do snapshot em lote), A3 (candidato que sai do CSV), A4 (última importação na tela e reimportação agendada, opt-in), A5 (aviso de disputas da UF desligadas), A7 (aviso de tabelas fora do InnoDB), A8 (`tests/run-in-docker.sh` e `tests/wp-integration.php`; bootstrap do PHPUnit corrigido), A11 (versão única), a maior parte do A6 e o A12 (catálogo: consulta enxuta, 1.275 → 104 ms na página 1 com 62 mil candidatos). Detalhes em [docs/versoes.md](docs/versoes.md#versão-250--importação-saúde-e-coleta-mais-barata).

Planejado desde antes e ainda não feito (prioridade P2): fotos com cache próprio, EA14/EA15 e mapas municipais, assinatura X.509 dos JSON, monitor de mudança de contrato EA11/EA20, política de retenção de snapshots e remoção das classes legadas.

## B. Validação que ficou parcial

**Testado em WordPress real (Docker, PHP 8.2), pelo `tests/run-in-docker.sh`:**
- todas as abas do admin, avisos de UF desligada e de InnoDB (com tabela MyISAM temporária);
- **os POSTs do admin de verdade** (`tests/http-admin.sh`: cookie de administrador, nonce lido da própria página): "Salvar" da reimportação automática e "Salvar seleção", inclusive nonce inválido (403), sem login, eleição inexistente, liga/desliga da disputa com o aviso aparecendo e sumindo, ajuste manual de intervalo; a tabela `ae_contests` volta idêntica ao final;
- **a coleta ponta a ponta com um TSE falso** (`tests/wp-collect.php`, HTTP interceptado, sem rede): zerado, parcial, 2º turno (`e=s` sem virar eleito, e agora o vínculo do candidato às duas disputas), divergência `and`/`tf`, final, HTTP condicional (304), 404 com backoff que se desfaz sozinho, 429 com pausa de 10 min e nenhuma requisição durante ela, 500, JSON inválido e sem estrutura; REST pública (cache, ETag, 304), shortcode e card (escape de HTML, "Ao vivo", "Dados atrasados", "Apuração concluída"); a REST continua servindo o último snapshot durante as falhas;
- importação de candidatos com CSV sintético, marcação e retorno de removidos, agendamento da reimportação, `persist_result` em lote e o rollback da transação com falha forçada;
- **importação do ZIP real** (`tests/wp-import-zip.php` pelo `tests/run-with-zip.sh`, container descartável com `php-zip`): CSV em ISO-8859-1 por UF dentro do ZIP, Brasil à parte, vice e suplente fora, UF fora da seleção, mais de 250 linhas numa UF (cursor entre arquivos), reimportação que marca quem saiu, HTTP 500 e arquivo que não é ZIP;
- **concorrência** (`tests/run-concurrency.sh`): 4 processos na mesma fila de 24 coletas, cada job uma vez só, sem snapshot duplicado, lock nunca compartilhado;
- **custo local e modelo de ocupação** (`tests/wp-latency.php`): leve ~50 ms, pesada de 1.100 candidatos ~250 ms, e a projeção do cenário nacional por latência de rede;
- **carga na REST** (`tests/load/results-node.js`): origem ~4 a 5 req/s por WordPress (qualquer rota); com cache de página, 120 a 640 req/s;
- **front no navegador** (Chromium headless, 1280 e 390 px): resumo, card e tabela, "Ao vivo" e "Dados atrasados";
- o `bin/tse-tick-loop.sh` com Docker de verdade: 4 ticks, saída 0, batimento com origem `cli`, `cli_age` de segundos (com a pausa preventiva ligada para não consultar o TSE);
- catálogo com 62 mil candidatos.

Os testes de coleta foram verificados também no sentido contrário: tirando de propósito a proteção do 2º turno, ou a regra que mantém a disputa de referência, o teste falha.

**PHP 7.4 e 7.2:** as suítes rodam em containers descartáveis com `tests/run-matrix.sh` (PHP 7.4 + WordPress 6.1, PHP 7.2 + WordPress da imagem e PHP 7.2 + WordPress 4.9). O resultado da última rodada está em [docs/compatibilidade.md](docs/compatibilidade.md). O `http-admin.sh` só roda no ambiente do site (precisa de HTTP e cookie).

**Ainda não exercitado (só o TSE real ou a infraestrutura resolvem):**
- o comportamento do **TSE de verdade**: tempo de rede (agora medido pelo `AE_Perf` assim que houver coleta oficial), bloqueio por IP e o formato exato dos arquivos de hoje (não há mais simulado; o `tests/wp-collect.php` reproduz o formato documentado, não o tráfego real);
- o **ZIP oficial** de 2026 (o teste usa um ZIP sintético no mesmo formato: um CSV por UF, `;`, ISO-8859-1);
- **carga em produção**: picos de leitores com o CDN/cache escolhido na frente, muitas UFs ligadas ao mesmo tempo contra o TSE real.

## C. Depende do ambiente de cada projeto

Não se resolve no código do plugin. O checklist foi separado em [PENDENCIAS-INFRAESTRUTURA.md](PENDENCIAS-INFRAESTRUTURA.md) (servidor, cache, cron, alertas) e [PENDENCIAS-DIA-DA-APURACAO.md](PENDENCIAS-DIA-DA-APURACAO.md) (o que só se fecha com o TSE publicando). A lista abaixo fica como referência histórica.

- [ ] **Cron de sistema** rodando `bin/tse-tick-loop.sh`, com `TSE_APURACAO_CONTAINER` (se usar Docker), `TSE_APURACAO_PLUGIN_PATH` e `TSE_APURACAO_LOG_FILE` declaradas **dentro do crontab** (ele não herda variáveis do shell). Confirmar que a Visão geral mostra "Último tick … (cron do sistema)" e que o log cresce.
- [ ] **Versão e ponteiro:** o site está na versão esperada. Se o plugin é submódulo, o ponteiro no repositório do site foi atualizado e a branch é a certa (`main` para PHP 8.1+, `php7.4` para PHP 7.4).
- [ ] **PHP e banco:** extensão `php-zip` **instalada antes de ativar** (requisito de ativação desde a 2.5.1: sem ela o plugin recusa ativar; `php -m | grep -i zip`). O container Docker local **não** a tem: adicione `php8.2-zip` ao `docker/Dockerfile` (arquivo do projeto, fora do plugin) para a importação do ZIP rodar ali; enquanto isso, `tests/run-with-zip.sh` testa a importação num container descartável. Tabelas InnoDB (a Visão geral avisa).
- [ ] **Cache de página e CDN:** `ae_pagina`, `ae_busca`, `ae_cargo`, `ae_uf` e `ae_partido` não podem ser ignorados na chave de cache; as REST `apuracao/v1/results` e `tse/v1/resultado` mandam `Cache-Control` público para a borda guardar.
- [ ] **Decidir** Redis/Memcached e CDN.
- [ ] **Reimportar os candidatos** perto da eleição (o CSV muda todo dia): clicar em "Buscar e importar candidatos" ou ligar a reimportação automática (aba Importar e coletar). Desligar na noite da eleição.
- [ ] **Conferir a Seleção de disputas** e os intervalos; ao ligar disputas de Câmara de várias UFs, fazer em lotes pequenos e fora do pico.
- [ ] **Checklist de simulado** ainda aberto: comparar a parcial com o portal oficial, confirmar 100% (`and`, `tf`, `md`) e as vagas do Senado, medir requisições e pico no IP de saída, confirmar que o navegador não acessa domínio do TSE, anexar fixtures sanitizadas com hash e horário.
- [ ] **Cache de página ou CDN obrigatório na frente da REST e das páginas com `[tse_apuracao]`:** a origem aguenta ~5 req/s por WordPress (medido, qualquer rota), então sem cache um pico de leitores derruba o site. O plugin já manda `Cache-Control` público (`s-maxage=60`, `stale-while-revalidate`); confira que a borda o respeita.
- [ ] **Primeira hora da apuração:** abrir a Visão geral e ler "Download do TSE" e "Lock preso por tick" (p95). Regra no A10.
- [ ] **Alerta externo:** o plugin não envia nada para Slack ou outro serviço (decisão da 2.4.0). Quem precisar consome `GET /wp-json/apuracao/v1/admin/health` (usuário com `manage_options`), cujo campo `tick` mostra `stale`, `cli_stopped` e `cli_never`.
- [ ] **Observabilidade, rollback e retenção** de snapshots, definidos por redação e infraestrutura.

## D. Decisões já tomadas

- **Só titulares** são importados (Presidente, Governador, Senador, Deputados). Vice e suplentes ficam de fora, de propósito.
- **Sem Slack:** o plugin não se comunica com nenhum serviço além do TSE.
- **Intervalo por tipo de disputa**, só com mais de uma UF ligada: majoritárias 60 s e primeiro na fila; deputados 120 s e 5 s de espera. Com uma UF só, tudo em 60 s. Ajuste manual na Seleção de disputas nunca é sobrescrito, nem por novo sync.
- **"Dados atrasados"** proporcional ao intervalo: `max(3 min, 3 × intervalo)`.
- **Paginação** do catálogo por `ae_pagina` (não `paged`), 24 por página, com âncora `#ae-catalog-lista`.
- **Snapshot em transação** (exige InnoDB).
- **A skill do Claude Code vive no repositório** (`.claude/skills/tse-apuracao/`, com `.claude/install-skill.sh` para torná-la global) e muda no mesmo commit que o comportamento que ela descreve. Fica fora de pacotes gerados com `git archive` (`.gitattributes`).
- **`ae_candidates.contest_id` é a disputa de referência (menor turno)**; todas as disputas do candidato ficam em `ae_candidate_contests`. O EA20 do 2º turno não move a referência (2.6.0).
- **A origem não é feita para tráfego direto:** a REST precisa de cache de página ou CDN na frente (medido: ~5 req/s por WordPress).
- **Três branches** recebem os mesmos commits: `main` (PHP 8.1+), `php7.4` e `php7.2`. Na `php7.4` não usar `match`, `throw` em expressão nem o tipo `mixed`; na `php7.2`, além disso, nada do que a lista "O que a `php7.2` troca" (em E) proíbe.

- **Sem página de candidato mais completa:** o plugin não é o canal oficial do TSE, e sim um facilitador de informação para portais. Biografia e patrimônio ficam no DivulgaCand; o perfil atual (dados da disputa, votos e turnos) basta.

## E. Como publicar e retomar

**Versões de PHP (três branches):** `main` exige PHP 8.1+, `php7.4` exige 7.4+ e `php7.2` exige 7.2+. Não há branch para 7.3 (use a `php7.2`, que roda nele também). O PHP 7.2 e o 7.3 estão sem correção de segurança, então a `php7.2` existe só para projetos que ainda não conseguiram migrar.

**A `php7.2` é gerada, não escrita à mão.** Ela sai da `php7.4` por `python3 tools/port-php72.py`, que reescreve só a sintaxe que o PHP 7.2 não tem: propriedades tipadas, arrow functions (`fn`), `??=` e `JSON_THROW_ON_ERROR`/`JsonException`, e ajusta o cabeçalho para `Requires PHP: 7.2`. O script para com erro se o código mudar de um jeito que ele não conhece (e confere que nada do 7.3/7.4 sobrou). O que não depende de versão fica em **todas** as branches: `includes/compat.php` (polyfills de `str_contains`, `str_starts_with`, `str_ends_with` e `wp_date`) e a guarda que só registra o bloco Gutenberg quando o WordPress suporta (5.5+; nos mais antigos o shortcode cobre o uso). Assim o plugin não depende da versão do WordPress. Testada em PHP 7.2.12 + WordPress 4.9.8 e em PHP 7.2.34 + WordPress 5.6.

Para atualizar a `php7.2` depois de qualquer mudança na `php7.4`:

```bash
tools/regen-php72.sh      # árvore limpa; gera a variante e avança a php7.2 sem reescrever o histórico
# lint no 7.2 e suítes em WordPress com PHP 7.2; então tag (se for release) e push
```

O script **não usa `reset --hard`**: a `php7.2` é publicada, e reescrevê-la exigiria push forçado. Ele gera a variante num commit e faz a branch avançar por um commit de junção com a mesma árvore.

**Publicar uma mudança** (nas três branches e nos dois remotes):

```bash
git checkout main      # commit aqui
git checkout php7.4 && git cherry-pick <commit>   # conflito esperado só no cabeçalho de versão: manter a versão nova e "Requires PHP: 7.4"
tools/regen-php72.sh   # php7.2 gerada da php7.4, sem cherry-pick e sem push forçado (ver acima)
# lint nas duas versões:
docker run --rm -v "$PWD":/p php:7.4-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
docker run --rm -v "$PWD":/p php:8.2-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
# na php7.2, também: docker run --rm -v "$PWD":/p php:7.2-cli sh -c 'for f in /p/includes/*.php /p/templates/*.php /p/tse-apuracao.php; do php -l $f; done'
git push origin main php7.4 php7.2          # GitLab
git push github main:master php7.4:php7.4 php7.2:php7.2   # GitHub
```

Depois, em cada site que usa o plugin como submódulo, atualizar o ponteiro e conferir a versão na tela **Apuração**.

Ao publicar, confira se a skill em `.claude/skills/tse-apuracao/` reflete a mudança; quem já a instalou precisa rodar `.claude/install-skill.sh` de novo.

**Testes antes de publicar** (cada um restaura o que altera):

```bash
tests/run-in-docker.sh      # suítes no container do site (admin, integração, coleta, latência, POSTs do admin)
tests/run-with-zip.sh       # importação do ZIP real, num container descartável com php-zip
tests/run-concurrency.sh    # 4 workers na mesma fila
tools/check-branches.sh      # as três branches coerentes: commits, php7.2 = port da php7.4, versão e lint em 8.2/7.4/7.2
# PHP e WordPress antigos (git worktree da branch, containers descartáveis):
git worktree add /tmp/plugin-php74 php7.4 && tests/run-matrix.sh php74 /tmp/plugin-php74
git worktree add /tmp/plugin-php72 php7.2 && tests/run-matrix.sh php72 /tmp/plugin-php72
tests/run-matrix.sh php72-wp49 /tmp/plugin-php72
```

**Ordem sugerida:** antes de ir ao ar, publicar as três branches, atualizar os ponteiros dos submódulos, conferir `php-zip` no servidor, o cache na frente da REST e o cron de sistema. Na primeira hora da apuração, ler a medição de rede (A10). Depois da eleição: A14 e A6 (separar o manual do histórico), e A2 só se o A10 mostrar necessidade.
