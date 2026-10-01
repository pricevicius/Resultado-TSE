# Histórico — prontidão, performance e cache (setembro/2026)

Registro datado do projeto de origem. Os números de capacidade atuais estão em [../versoes.md](../versoes.md) (2.6.0) e as pendências em [../../PENDENCIAS.md](../../PENDENCIAS.md).

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
