# TSE Apuração — pendências e pontos em aberto

Estado em **01/10/2026**, versão **2.5.1**. Este arquivo é a lista do que ainda **não** foi feito, do que foi validado só em parte e do que depende do ambiente de cada projeto. O que já foi entregue está em [DOCUMENTACAO-PLUGIN-APURACAO.md](DOCUMENTACAO-PLUGIN-APURACAO.md).

Está dividido em: **A.** código do plugin, **B.** validação que ficou parcial, **C.** implantação em cada projeto, **D.** decisões já tomadas, **E.** como publicar e retomar.

## A. Pendências do código do plugin

Valem para qualquer projeto que use o plugin. Esforço e risco são estimativas.

| # | Item | Por que importa | Esforço | Risco |
| --- | --- | --- | --- | --- |
| A2 | **Duas pistas de worker com locks separados** | Com a coleta pesada em ~0,3 s (A1, medido), a necessidade deixou de ser evidente. Só vale se, medindo contra o TSE real, a busca de rede de um deputado ainda segurar o lock por vários segundos. Decidir depois de A10. | cerca de 1 dia | médio a alto (concorrência e mudança de esquema) |
| A6 | **O que ainda é fixo** (resto) | Os valores de formulário, o cabeçalho do catálogo, o seed e o ano do fallback do shortcode foram removidos na 2.5.0. Sobram o caminho do simulado do TSE (`simulado/simulado2026`), os padrões `eleicoes-2026` do bloco Gutenberg e do `[apuracao]` legado, e a documentação, que mistura manual de uso com histórico de um projeto (homolog, GitLab). | 1 a 2 h | baixo |
| A9 | **`contest_id` guarda uma disputa só por candidato** | Num 2º turno o candidato pertence à disputa do 1º e à do 2º. O EA20 do 2º turno reescreve o vínculo. Revisar quando houver 2º turno (exige decidir se o vínculo vira tabela própria). | a avaliar | médio |
| A10 | **Validar o 120 s e o atraso de 5 s na fila contra o TSE real** | A parte local foi medida (coleta de 1.100 candidatos: ~0,3 s e ~20 queries; 304 em ~10 ms). Falta o tempo de rede do TSE (download do JSON) e o comportamento com várias UFs. Se a rede for rápida, o intervalo das pesadas pode voltar para 60 s. São ajustáveis (`ae_heavy_interval`, constante `HEAVY_QUEUE_DELAY`). | 1 a 2 h | baixo |

| A13 | **Manter a branch `php7.2`** | Um projeto roda PHP 7.2.34 (Ubuntu 24.04, repositório sury). A branch `php7.2` existe desde a 2.5.0 (release `v2.5.0-php7.2`) e foi testada em PHP 7.2 com WordPress 4.9.8 e 5.6. O custo é contínuo, mas pequeno: toda mudança entra em `main`, vai por cherry-pick para `php7.4` e a `php7.2` é **regenerada por script** (ver "Versões de PHP" em E). Código novo na `main` deve evitar o que o script não sabe converter (ele para com erro, sem gerar nada errado). O PHP 7.2 está sem correção de segurança desde 2020: o projeto deve ter um plano de migrar para 7.4 ou superior, e esta branch deve ser aposentada quando isso acontecer. | 10 a 15 min por release | baixo (o script falha em voz alta; o lint no 7.2 e as suítes em WordPress confirmam) |

**Entregue na 2.5.0:** A1 (gravação do snapshot em lote), A3 (candidato que sai do CSV), A4 (última importação na tela e reimportação agendada, opt-in), A5 (aviso de disputas da UF desligadas), A7 (aviso de tabelas fora do InnoDB), A8 (`tests/run-in-docker.sh` e `tests/wp-integration.php`; bootstrap do PHPUnit corrigido), A11 (versão única) a maior parte do A6 e o A12 (catálogo: consulta enxuta, 1.275 → 104 ms na página 1 com 62 mil candidatos; busca por nome ~200 ms nessa escala, aceitável). Detalhes em [DOCUMENTACAO-PLUGIN-APURACAO.md](DOCUMENTACAO-PLUGIN-APURACAO.md#versão-250--importação-saúde-e-coleta-mais-barata).

Planejado desde antes e ainda não feito (prioridade P2): fotos com cache próprio, páginas individuais de candidato mais completas, EA14/EA15 e mapas municipais, assinatura X.509 dos JSON, monitor de mudança de contrato EA11/EA20, política de retenção de snapshots e remoção das classes legadas.

## B. Validação que ficou parcial

**Testado em WordPress real (Docker, PHP 8.2), pelo `tests/run-in-docker.sh`:**
- todas as abas do admin, avisos de UF desligada e de InnoDB (com tabela MyISAM temporária);
- **os POSTs do admin de verdade** (`tests/http-admin.sh`: cookie de administrador, nonce lido da própria página): "Salvar" da reimportação automática e "Salvar seleção", inclusive nonce inválido (403), sem login, eleição inexistente, liga/desliga da disputa com o aviso aparecendo e sumindo, ajuste manual de intervalo; a tabela `ae_contests` volta idêntica ao final;
- **a coleta ponta a ponta com um TSE falso** (`tests/wp-collect.php`, HTTP interceptado, sem rede): zerado, parcial, 2º turno (`e=s` sem virar eleito), divergência `and`/`tf`, final, HTTP condicional (304), 404 com backoff que se desfaz sozinho, 429 com pausa de 10 min e nenhuma requisição durante ela, 500, JSON inválido e sem estrutura; REST pública (cache, ETag, 304), shortcode e card (escape de HTML, "Ao vivo", "Dados atrasados", "Apuração concluída"); a REST continua servindo o último snapshot durante as falhas;
- importação de candidatos com CSV sintético, marcação e retorno de removidos, agendamento da reimportação, `persist_result` em lote e o rollback da transação com falha forçada;
- o `bin/tse-tick-loop.sh` com Docker de verdade: 4 ticks, saída 0, batimento com origem `cli`, `cli_age` de segundos (com a pausa preventiva ligada para não consultar o TSE);
- medições locais: coleta de 1.100 candidatos ~0,3 s e ~20 queries (1ª coleta ~0,9 s e ~1.100 queries, pelos candidatos novos); catálogo com 62 mil candidatos.

Os testes de coleta foram verificados também no sentido contrário: tirando de propósito a proteção do 2º turno, o teste falha.

**PHP 7.4:** a 2.5.0 foi executada na branch `php7.4` com PHP 7.4.33 e WordPress 6.1.1 em containers descartáveis (smoke do admin, `wp-integration` e `wp-collect`, 83 checks). Isso achou um bug real (shortcode sem atributos recebia `''` em WordPress antigo e dava `TypeError`), já corrigido. O teste do aviso de UF desligada é ignorado nesse ambiente por não haver disputas sincronizadas, e o `http-admin.sh` não foi rodado lá.

**Ainda não exercitado:**
- o comportamento do **TSE de verdade**: tempo de rede, bloqueio por IP, formato exato dos arquivos de hoje (não há mais simulado; o `tests/wp-collect.php` reproduz o formato documentado, não o tráfego real);
- a importação do **ZIP** real: o container local não tem `php-zip` (e, desde a 2.5.1, o plugin não ativa sem ela; no ambiente local ele já estava ativo, por isso só mostra o aviso), então os testes usam CSV (mesmo caminho de leitura e marcação, sem a abertura do ZIP);
- o **JavaScript no navegador de verdade**: a lógica do `tse-resumo.js` foi testada em Node com um DOM mínimo (`tests/js/resumo-dom.test.js`), não num navegador; vale olhar o `[tse_apuracao_resumo]` numa página real (layout, tema e celular);
- **concorrência e carga**: vários workers ao mesmo tempo, muitas UFs ligadas, picos de leitores na REST.

## C. Depende do ambiente de cada projeto

Não se resolve no código do plugin. Serve de checklist de implantação.

- [ ] **Cron de sistema** rodando `bin/tse-tick-loop.sh`, com `TSE_APURACAO_CONTAINER` (se usar Docker), `TSE_APURACAO_PLUGIN_PATH` e `TSE_APURACAO_LOG_FILE` declaradas **dentro do crontab** (ele não herda variáveis do shell). Confirmar que a Visão geral mostra "Último tick … (cron do sistema)" e que o log cresce.
- [ ] **Versão e ponteiro:** o site está na versão esperada. Se o plugin é submódulo, o ponteiro no repositório do site foi atualizado e a branch é a certa (`main` para PHP 8.1+, `php7.4` para PHP 7.4).
- [ ] **PHP e banco:** extensão `php-zip` **instalada antes de ativar** (requisito de ativação desde a 2.5.1: sem ela o plugin recusa ativar; `php -m | grep -i zip`). O container Docker local **não** a tem: adicione `php-zip` ao `docker/Dockerfile` (arquivo do projeto, fora do plugin) para a importação do ZIP rodar ali. Tabelas InnoDB (a Visão geral avisa).
- [ ] **Cache de página e CDN:** `ae_pagina`, `ae_busca`, `ae_cargo`, `ae_uf` e `ae_partido` não podem ser ignorados na chave de cache; as REST `apuracao/v1/results` e `tse/v1/resultado` mandam `Cache-Control` público para a borda guardar.
- [ ] **Decidir** Redis/Memcached e CDN.
- [ ] **Reimportar os candidatos** perto da eleição (o CSV muda todo dia): clicar em "Buscar e importar candidatos" ou ligar a reimportação automática (aba Importar e coletar). Desligar na noite da eleição.
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
- **A skill do Claude Code vive no repositório** (`.claude/skills/tse-apuracao/`, com `.claude/install-skill.sh` para torná-la global) e muda no mesmo commit que o comportamento que ela descreve. Fica fora de pacotes gerados com `git archive` (`.gitattributes`).
- **Três branches** recebem os mesmos commits: `main` (PHP 8.1+), `php7.4` e `php7.2`. Na `php7.4` não usar `match`, `throw` em expressão nem o tipo `mixed`; na `php7.2`, além disso, nada do que a lista "O que a `php7.2` troca" (em E) proíbe.

## E. Como publicar e retomar

**Versões de PHP (três branches):** `main` exige PHP 8.1+, `php7.4` exige 7.4+ e `php7.2` exige 7.2+. Não há branch para 7.3 (use a `php7.2`, que roda nele também). O PHP 7.2 e o 7.3 estão sem correção de segurança, então a `php7.2` existe só para projetos que ainda não conseguiram migrar.

**A `php7.2` é gerada, não escrita à mão.** Ela sai da `php7.4` por `python3 tools/port-php72.py`, que reescreve só a sintaxe que o PHP 7.2 não tem: propriedades tipadas, arrow functions (`fn`), `??=` e `JSON_THROW_ON_ERROR`/`JsonException`, e ajusta o cabeçalho para `Requires PHP: 7.2`. O script para com erro se o código mudar de um jeito que ele não conhece (e confere que nada do 7.3/7.4 sobrou). O que não depende de versão fica em **todas** as branches: `includes/compat.php` (polyfills de `str_contains`, `str_starts_with`, `str_ends_with` e `wp_date`) e a guarda que só registra o bloco Gutenberg quando o WordPress suporta (5.5+; nos mais antigos o shortcode cobre o uso). Assim o plugin não depende da versão do WordPress. Testada em PHP 7.2.12 + WordPress 4.9.8 e em PHP 7.2.34 + WordPress 5.6.

Para atualizar a `php7.2` depois de qualquer mudança na `php7.4`:

```bash
git checkout php7.2 && git reset --hard php7.4 && python3 tools/port-php72.py
# lint no 7.2 e suítes em WordPress com PHP 7.2; então:
git add -A && git commit -m "port: variante PHP 7.2 gerada de php7.4 @ <sha>"
```

**Publicar uma mudança** (nas três branches e nos dois remotes):

```bash
git checkout main      # commit aqui
git checkout php7.4 && git cherry-pick <commit>   # conflito esperado só no cabeçalho de versão: manter a versão nova e "Requires PHP: 7.4"
git checkout php7.2 && git reset --hard php7.4 && python3 tools/port-php72.py && git add -A && git commit -m "port: ..."   # gerada, sem cherry-pick (ver acima)
# lint nas duas versões:
docker run --rm -v "$PWD":/p php:7.4-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
docker run --rm -v "$PWD":/p php:8.2-cli sh -c 'for f in /p/includes/*.php /p/tse-apuracao.php; do php -l $f; done'
# na php7.2, também: docker run --rm -v "$PWD":/p php:7.2-cli sh -c 'for f in /p/includes/*.php /p/templates/*.php /p/tse-apuracao.php; do php -l $f; done'
git push origin main php7.4 php7.2          # GitLab
git push github main:master php7.4:php7.4 php7.2:php7.2   # GitHub
```

Depois, em cada site que usa o plugin como submódulo, atualizar o ponteiro e conferir a versão na tela **Apuração**.

Ao publicar, confira se a skill em `.claude/skills/tse-apuracao/` reflete a mudança; quem já a instalou precisa rodar `.claude/install-skill.sh` de novo.

**Ordem sugerida:** antes de ir ao ar, rodar o cherry-pick para `php7.4` com os testes nas duas versões e conferir `php-zip` no servidor. Depois da eleição: A10 (medir contra o TSE real), A6 (o que sobrou), A9 se houver 2º turno, e A2 só se A10 mostrar necessidade.
