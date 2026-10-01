# TSE Apuração

Plugin WordPress para importar candidatos e publicar resultados eleitorais do TSE a partir de snapshots locais auditáveis.

## Requisitos

- **PHP** 8.1+ (`main`), 7.4+ (branch `php7.4`) ou 7.2+ com WordPress 4.9+ (branch `php7.2`, release `*-php7.2`);
- **extensão PHP `zip`** (classe `ZipArchive`): **obrigatória**. O plugin recusa a ativação, com uma mensagem na tela, se ela não estiver instalada, e mostra um aviso permanente no admin se ela sumir depois. É ela que abre o pacote ZIP de candidatos dos Dados Abertos do TSE. Em Debian/Ubuntu: `apt install php-zip` (ou `php7.2-zip`, `php8.2-zip`… conforme a versão) e reinicie o PHP-FPM ou o Apache. Conferir: `php -m | grep -i zip`;
- tabelas **InnoDB** (a Visão geral avisa se não forem);
- saída HTTPS para `*.tse.jus.br`.

Só para desenvolvimento ou teste sem a extensão, defina `define( 'TSE_APURACAO_ALLOW_NO_ZIP', true );` no `wp-config.php` (a importação do ZIP continuará sem funcionar). Não use em produção.

## Instalação e uso

1. Instale e ative a pasta em `wp-content/plugins/tse-apuracao`.
2. Abra **Apuração > Configuração**.
3. Escolha **Simulado 2026** para validar a integracao, ou **Oficial** quando o catalogo de producao estiver disponivel; clique em **Sincronizar configuracao do TSE**.
4. Em **Importar e coletar**, selecione a eleição e clique em **Buscar e importar candidatos**.
5. Insira o bloco **Apuração eleitoral** ou use o shortcode:

```text
[tse_apuracao cargo="governador" uf="es" turno="1" limite="10" atualizar="60"]
```

Não é necessário informar URL, código de pleito ou código de eleição. O plugin lê o EA11 oficial e monta as fontes EA20. Os visitantes consultam somente a API REST do WordPress; o TSE é acessado exclusivamente pela fila do servidor.

## Catálogo de candidatos

```text
[apuracao_candidatos]
[apuracao_candidatos por_pagina="24"]
```

Lista os candidatos importados, com busca por nome/número e filtros de cargo, UF e partido. É **paginado**: 24 por página por padrão, com "Anterior / 1 2 3 … / Próxima" abaixo da lista. Ao buscar ou trocar de página, o navegador desce direto para a lista. Quando o resultado cabe numa página só, a navegação não aparece. Se a paginação não aparecer, veja "Catálogo de candidatos: paginação" na documentação completa.

## Cargos do shortcode

`presidente`, `governador`, `senador`, `deputado-federal`, `deputado-estadual`, `deputado-distrital`, `prefeito` e `vereador`.

Os demais atributos são `uf`, `turno`, `limite`, `atualizar` e `titulo`.

## Proteções

- teto interno de 20 requisições/s, abaixo do limite de 100/s informado pelo TSE;
- ETag/Last-Modified e tratamento de 304;
- pausa preventiva de dez minutos para 403 e 429; 404 usa backoff por fonte (10 min a 6h) sem bloquear as demais disputas;
- retry exponencial e último snapshot válido;
- HTTPS restrito aos domínios oficiais `*.tse.jus.br`;
- payload bruto, SHA-256 e histórico de snapshots.

## Testes

```bash
find . -name '*.php' -type f -print0 | xargs -0 -n1 php -l
wp eval-file wp-content/plugins/tse-apuracao/tests/admin-smoke.php
```

Fixtures EA20 cobrem início zerado, totalização final, eleitos, não eleitos e duas vagas de Senado.

## Skill do Claude Code

O repositório inclui uma skill (`.claude/skills/tse-apuracao/`) que explica o plugin como um todo: ideia, fluxo, fontes do TSE, comportamentos estranhos do TSE, operação e diagnóstico. Para usá-la em qualquer projeto, instale-a como skill global:

```bash
.claude/install-skill.sh          # copia para ~/.claude/skills
.claude/install-skill.sh --link   # ou liga por link simbólico (acompanha o repositório)
```

Depois, numa sessão nova do Claude Code, ela é acionada sozinha quando o assunto é este plugin (ou com `/tse-apuracao`). Rode o instalador de novo ao atualizar o plugin. Ao mudar o comportamento do plugin, atualize a skill no mesmo commit.

## Pendências

O que ainda está em aberto, o que foi validado só em parte e o checklist de implantação por projeto estão em [PENDENCIAS.md](PENDENCIAS.md).

## Documentação completa

Leia [DOCUMENTACAO-PLUGIN-APURACAO.md](DOCUMENTACAO-PLUGIN-APURACAO.md) para arquitetura, fluxo de importação, contratos TSE, simulados, decisões, limitações e plano de mapas EA14/EA15.
