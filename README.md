# TSE Apuração

Plugin WordPress para importar candidatos e publicar resultados eleitorais do TSE a partir de snapshots locais auditáveis.

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

## Cargos do shortcode

`presidente`, `governador`, `senador`, `deputado-federal`, `deputado-estadual`, `deputado-distrital`, `prefeito` e `vereador`.

Os demais atributos são `uf`, `turno`, `limite`, `atualizar` e `titulo`.

## Proteções

- teto interno de 20 requisições/s, abaixo do limite de 100/s informado pelo TSE;
- ETag/Last-Modified e tratamento de 304;
- pausa preventiva de dez minutos para 403, 404 e 429;
- retry exponencial e último snapshot válido;
- HTTPS restrito aos domínios oficiais `*.tse.jus.br`;
- payload bruto, SHA-256 e histórico de snapshots.

## Testes

```bash
find . -name '*.php' -type f -print0 | xargs -0 -n1 php -l
wp eval-file wp-content/plugins/tse-apuracao/tests/admin-smoke.php
```

Fixtures EA20 cobrem início zerado, totalização final, eleitos, não eleitos e duas vagas de Senado.

## Documentação completa

Leia [DOCUMENTACAO-PLUGIN-APURACAO.md](DOCUMENTACAO-PLUGIN-APURACAO.md) para arquitetura, fluxo de importação, contratos TSE, simulados, decisões, limitações e plano de mapas EA14/EA15.
