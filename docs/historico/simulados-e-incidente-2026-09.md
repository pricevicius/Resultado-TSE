# Histórico — simulados e incidente de 15/09/2026

Registro datado do projeto de origem (Tribuna Online). Não descreve o comportamento atual do plugin; para isso, leia [../arquitetura.md](../arquitetura.md).

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
