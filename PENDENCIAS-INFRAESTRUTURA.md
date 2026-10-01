# Pendências de infraestrutura

Tudo aqui **não se resolve no código do plugin**: depende do servidor, da rede, do CDN ou de quem implanta. Fazer **antes** de 04/10/2026 (1º turno) e conferir de novo antes de 25/10 (2º turno). O que depende do TSE no dia está em [PENDENCIAS-DIA-DA-APURACAO.md](PENDENCIAS-DIA-DA-APURACAO.md).

## Servidor e PHP
- [ ] Extensão **`php-zip`** instalada **antes** de ativar o plugin (`php -m | grep -i zip`). Sem ela o plugin recusa ativar.
- [ ] Tabelas em **InnoDB** (a Visão geral avisa). O snapshot é gravado em transação.
- [ ] No Docker local, adicionar `php8.2-zip` ao `docker/Dockerfile` (arquivo do projeto, fora do plugin).
- [ ] Versão do plugin no site é a esperada. Se for submódulo, o ponteiro está atualizado e a branch é a certa (`main` para PHP 8.1+, `php7.4` para PHP 7.4).

## Coleta
- [ ] **Cron de sistema** rodando `bin/tse-tick-loop.sh`, com `TSE_APURACAO_CONTAINER` (se usar Docker), `TSE_APURACAO_PLUGIN_PATH` e `TSE_APURACAO_LOG_FILE` declaradas **dentro do crontab** (ele não herda variáveis do shell).
- [ ] Visão geral mostra "Último tick … (cron do sistema)" e o log cresce.
- [ ] Saída do servidor para o TSE liberada (IP não bloqueado, DNS e TLS funcionando).

## Cache e borda
- [ ] **Cache de página ou CDN obrigatório** na frente da REST e das páginas com `[tse_apuracao]`: a origem aguenta ~5 req/s por WordPress (medido, qualquer rota).
- [ ] Os parâmetros `ae_pagina`, `ae_busca`, `ae_cargo`, `ae_uf` e `ae_partido` (e `ae_turno`, quando existir) **não** podem ser ignorados na chave de cache.
- [ ] A borda respeita o `Cache-Control` público (`s-maxage=60`, `stale-while-revalidate`) de `apuracao/v1/results` e `tse/v1/resultado`.
- [ ] Decidir Redis/Memcached e CDN.

## Operação
- [ ] **Alerta externo** (decisão da 2.4.0: o plugin não envia nada a Slack ou similar): quem precisar consome `GET /wp-json/apuracao/v1/admin/health` (usuário com `manage_options`), campo `tick` (`stale`, `cli_stopped`, `cli_never`).
- [ ] Observabilidade, rollback e retenção de snapshots, definidos por redação e infraestrutura.
- [ ] **Branch `php7.2`** (cliente específico): a cada release, `tools/regen-php72.sh`, `tools/check-branches.sh`, `tests/run-matrix.sh` e tag `vX.Y.Z-php7.2`. O PHP 7.2 está sem correção de segurança: isolar a rede, pôr CDN/WAF na frente e registrar a decisão de migrar.
