# TSE Apuração — pendências e pontos em aberto

Estado em **01/10/2026**, versão **2.4.1**. Este arquivo é a lista do que ainda **não** foi feito, do que foi validado só em parte e do que depende do ambiente de cada projeto. O que já foi entregue está em [DOCUMENTACAO-PLUGIN-APURACAO.md](DOCUMENTACAO-PLUGIN-APURACAO.md).

Está dividido em: **A.** código do plugin, **B.** validação que ficou parcial, **C.** implantação em cada projeto, **D.** decisões já tomadas, **E.** como publicar e retomar.

## A. Pendências do código do plugin

Valem para qualquer projeto que use o plugin. Esforço e risco são estimativas.

| # | Item | Por que importa | Esforço | Risco |
| --- | --- | --- | --- | --- |
| A1 | **Tornar a coleta pesada mais barata** (inserção em lote em `ae_result_rows`, pular o upsert de candidato que não mudou) | Um job pesado em andamento segura o lock do worker até acabar; Deputado Federal do RJ chegou a 2min30s numa coleta. A regra de intervalo da 2.4.0 só reduz a frequência e dá prioridade às leves, não encurta uma coleta que já começou. | 0,5 a 1 dia | médio (é o caminho central da coleta) |
| A2 | **Duas pistas de worker com locks separados** | Só vale se, depois do A1, um site com muitas UFs ainda atrasar as majoritárias. Faria as pesadas nunca bloquearem as leves. | cerca de 1 dia | médio a alto (concorrência e mudança de esquema) |
| A3 | **Candidato que sai do CSV** (renúncia, substituição) | A importação só insere e atualiza, nunca remove. Quem saiu continua no catálogo. Marcar como "não consta mais na última importação" em vez de apagar. | 2 a 3 h | baixo |
| A4 | **Reimportação agendada** (4x/dia) e data da última importação e da geração do CSV na tela | O TSE regenera o CSV todos os dias. Hoje só importa quem clica em "Buscar e importar candidatos". | 3 a 4 h | baixo a médio |
| A5 | **Aviso na Seleção de disputas** quando há disputas da UF do site desligadas | Foi assim que as 27 disputas de Deputado Federal ficaram paradas em 24/09, sem nenhum erro visível. | 1 a 2 h | baixo |
| A6 | **Remover o que é fixo de uma eleição ou de um projeto** | "Eleições 2026" fixo no cabeçalho do catálogo; `seed_2026` em `class-plugin.php`; formulário de configuração com ano `2026`, opção "Simulado 2026" e placeholder `ES`. A documentação mistura manual de uso com histórico de um projeto (homolog, GitLab). | 3 a 5 h | baixo |
| A7 | **Avisar se as tabelas não são InnoDB** | A transação de `persist_result` (2.4.0) depende de InnoDB e o esquema não fixa o engine. Em MyISAM o `ROLLBACK` não faz nada e ninguém é avisado. Incluir na saúde. | 1 h | baixo |
| A8 | **Consertar o PHPUnit e versionar o teste em WordPress real** | `tests/bootstrap.php` carrega `apuracao-eleitoral.php`, que não existe (o arquivo do plugin é `tse-apuracao.php`), então o PHPUnit não sobe. O roteiro de teste com Docker está só na documentação; deveria ser um script em `tests/`. | 3 a 4 h | baixo |
| A9 | **`contest_id` guarda uma disputa só por candidato** | Num 2º turno o candidato pertence à disputa do 1º e à do 2º. O EA20 do 2º turno reescreve o vínculo. Revisar quando houver 2º turno. | a avaliar | médio |
| A10 | **Validar o 120 s e o atraso de 5 s na fila com carga real** | Foram escolhidos por raciocínio, não por medição. São ajustáveis (`ae_heavy_interval`, constante `HEAVY_QUEUE_DELAY`). | 2 a 3 h | baixo |
| A11 | **Duas constantes de versão** | `TSE_APURACAO_VERSION` e `AE_VERSION` estão iguais agora, mas já divergiram. Unificar numa só. | 30 min | baixo |
| A12 | **Catálogo em base nacional** | A busca usa `LIKE '%termo%'` sem índice e há uma consulta de contagem por página. Sem problema para dezenas de milhares de linhas; reavaliar se a base crescer. | a avaliar | baixo |

Planejado desde antes e ainda não feito (prioridade P2): fotos com cache próprio, páginas individuais de candidato mais completas, EA14/EA15 e mapas municipais, assinatura X.509 dos JSON, monitor de mudança de contrato EA11/EA20, política de retenção de snapshots e remoção das classes legadas.

## B. Validação que ficou parcial

**Testado em WordPress real (Docker, PHP 8.2 e 7.4):** paginação do catálogo (inclusive homônimos e página fora do intervalo), regra de intervalo e `apply_all`, a tela de Seleção de disputas, ajuste manual preservado, ordem da fila, `tick_status`, linha "Último tick" na Visão geral.

**Testado só com stubs, sem banco real:** a transação de `persist_result`, o vínculo `contest_id` na importação, o filtro de só titulares, a limpeza de `#NE`/`#NULO`.

**Não exercitado:**
- o POST real de "Salvar seleção" (nonce e redirecionamento); foi validada a lógica equivalente;
- a importação real do ZIP do TSE dentro de um WordPress;
- a coleta real contra o EA20 depois das mudanças da 2.3.8 à 2.4.1;
- o `bin/tse-tick-loop.sh` com Docker de verdade (só o caso sem `php` e sem container);
- qualquer teste de carga da **coleta** (o teste de 22/09 mediu só a REST servindo snapshot em cache).

## C. Depende do ambiente de cada projeto

Não se resolve no código do plugin. Serve de checklist de implantação.

- [ ] **Cron de sistema** rodando `bin/tse-tick-loop.sh`, com `TSE_APURACAO_CONTAINER` (se usar Docker), `TSE_APURACAO_PLUGIN_PATH` e `TSE_APURACAO_LOG_FILE` declaradas **dentro do crontab** (ele não herda variáveis do shell). Confirmar que a Visão geral mostra "Último tick … (cron do sistema)" e que o log cresce.
- [ ] **Versão e ponteiro:** o site está na versão esperada. Se o plugin é submódulo, o ponteiro no repositório do site foi atualizado e a branch é a certa (`main` para PHP 8.1+, `php7.4` para PHP 7.4).
- [ ] **PHP e banco:** extensão `php-zip` ativa e tabelas InnoDB.
- [ ] **Cache de página e CDN:** `ae_pagina`, `ae_busca`, `ae_cargo`, `ae_uf` e `ae_partido` não podem ser ignorados na chave de cache; as REST `apuracao/v1/results` e `tse/v1/resultado` mandam `Cache-Control` público para a borda guardar.
- [ ] **Decidir** Redis/Memcached e CDN.
- [ ] **Reimportar os candidatos** perto da eleição (o CSV muda todo dia).
- [ ] **Conferir a Seleção de disputas** e os intervalos; ao ligar disputas de Câmara de várias UFs, fazer em lotes pequenos e fora do pico.
- [ ] **Checklist de simulado** ainda aberto: comparar a parcial com o portal oficial, confirmar 100% (`and`, `tf`, `md`) e as vagas do Senado, medir requisições e pico no IP de saída, confirmar que o navegador não acessa domínio do TSE, anexar fixtures sanitizadas com hash e horário.
- [ ] **Alerta externo:** o plugin não envia nada para Slack ou outro serviço (decisão da 2.4.0). Quem precisar consome `GET /wp-json/apuracao/v1/admin/health` (usuário com `manage_options`), cujo campo `tick` mostra `stale`, `cli_stopped` e `cli_never`.
- [ ] **Observabilidade, rollback e retenção** de snapshots, definidos por redação e infraestrutura.

No projeto de origem (Tribuna Online), em 01/10/2026: os ponteiros dos submódulos em ES e PE estão atrás (ES em 2.4.0, PE a conferir) e o push do ES não foi feito, porque dispara o deploy do homolog.

## D. Decisões já tomadas

- **Só titulares** são importados (Presidente, Governador, Senador, Deputados). Vice e suplentes ficam de fora, de propósito.
- **Sem Slack:** o plugin não se comunica com nenhum serviço além do TSE.
- **Intervalo por tipo de disputa**, só com mais de uma UF ligada: majoritárias 60 s e primeiro na fila; deputados 120 s e 5 s de espera. Com uma UF só, tudo em 60 s. Ajuste manual na Seleção de disputas nunca é sobrescrito, nem por novo sync.
- **"Dados atrasados"** proporcional ao intervalo: `max(3 min, 3 × intervalo)`.
- **Paginação** do catálogo por `ae_pagina` (não `paged`), 24 por página, com âncora `#ae-catalog-lista`.
- **Snapshot em transação** (exige InnoDB).
- **Duas branches** recebem os mesmos commits: `main` (PHP 8.1+) e `php7.4`. Na `php7.4` não usar `match`, `throw` em expressão nem o tipo `mixed`.

## E. Como publicar e retomar

**Publicar uma mudança** (nas duas branches e nos dois remotes):

```bash
git checkout main      # commit aqui
git checkout php7.4 && git cherry-pick <commit>   # conflito esperado só no cabeçalho de versão: manter a versão nova e "Requires PHP: 7.4"
# lint nas duas versões:
docker run --rm -v "$PWD":/p php:7.4-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
docker run --rm -v "$PWD":/p php:8.2-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
git push origin main php7.4          # GitLab
git push github main:master php7.4:php7.4   # GitHub
```

Depois, em cada site que usa o plugin como submódulo, atualizar o ponteiro e conferir a versão na tela **Apuração**.

**Ordem sugerida para retomar, depois da eleição:** A1, A5, A4 junto com A3, A7 junto com A8, A6, A10, e A2 só se ainda for preciso.
