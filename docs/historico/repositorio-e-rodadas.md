# Histórico — repositório e rodadas de trabalho

Registro datado do projeto de origem (submódulo, homolog, GitLab/GitHub, rodadas).

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
