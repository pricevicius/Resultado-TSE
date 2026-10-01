# Documentação do TSE Apuração

Índice e regras de organização. O plugin é usado em mais de um projeto: a documentação permanente **não pode** depender de um projeto, de uma data de simulado ou de um host.

## Onde está cada coisa

| Arquivo | Conteúdo | Muda quando |
| --- | --- | --- |
| [../README.md](../README.md) | o que é, instalação, shortcodes, skill | a interface pública muda |
| [arquitetura.md](arquitetura.md) | como o plugin funciona **hoje**: fontes do TSE, banco, painel, importação, normalização, limites, publicação no cliente | o comportamento muda |
| [operacao.md](operacao.md) | diagnóstico e rotinas de operação | aparece um problema recorrente |
| [testes.md](testes.md) | como validar: suítes, comandos, o que cada uma cobre | uma suíte nasce ou muda |
| [compatibilidade.md](compatibilidade.md) | PHP e WordPress suportados, política das branches `php7.4` e `php7.2` | uma versão entra ou sai |
| [versoes.md](versoes.md) | o que mudou em cada versão e por quê, com as medições da época | a cada release |
| [planejado.md](planejado.md) | ideias aceitas e ainda não implementadas | uma ideia nasce ou é entregue |
| [../PENDENCIAS.md](../PENDENCIAS.md) | o que está em aberto, o que depende do TSE ou do ambiente, decisões tomadas, como publicar | toda rodada de trabalho |
| [historico/](historico/) | registros **datados** de um projeto (simulados, incidentes, rodadas, repositório) | só se acrescenta; nunca descreve o comportamento atual |

## Regras

1. **Um assunto, um lugar.** Comportamento atual em `arquitetura.md`; o porquê e a data da mudança em `versoes.md`; o que falta em `PENDENCIAS.md`. Não repita: faça um link.
2. **Permanente é neutro.** `arquitetura.md`, `operacao.md`, `testes.md` e `compatibilidade.md` não citam projeto de origem, host de homologação nem repositório remoto, e não narram simulados ou incidentes. Fatos datados **sobre o comportamento do TSE** ("descoberto ao vivo em 22/09") podem ficar, porque documentam o contrato; a narrativa do projeto vai para `historico/`.
3. **Histórico é datado e não é corrigido.** Se o comportamento mudou, a correção entra em `arquitetura.md` e em `versoes.md`; o registro antigo fica como estava.
4. **Mudou o comportamento, mudou o doc no mesmo commit** (e a skill em `.claude/skills/tse-apuracao/`, se ela descrever a mudança). Vale para as três branches: a doc viaja no cherry-pick e na `php7.2` gerada.
5. **Detalhe de projeto não é pendência do plugin.** Ponteiro de submódulo, `Dockerfile` do site, CDN escolhida: `PENDENCIAS.md` seção C (checklist por projeto), nunca em `arquitetura.md`.
6. **Números têm data e ambiente.** Toda medição diz onde foi feita (versão, PHP, Docker local ou produção) e fica em `versoes.md`.

Estado atual: versão **2.6.1**.
