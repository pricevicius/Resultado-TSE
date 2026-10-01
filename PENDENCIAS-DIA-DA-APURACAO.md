# Pendências do dia da apuração

Só dá para fechar **com o TSE real publicando**. Nenhuma depende de código novo (se algo falhar, vira correção). Para o que é infraestrutura, ver [PENDENCIAS-INFRAESTRUTURA.md](PENDENCIAS-INFRAESTRUTURA.md).

## Antes das 17h (1º turno, 04/10/2026)
- [ ] **Reimportar os candidatos** (o CSV muda todo dia): "Buscar e importar candidatos" ou reimportação automática (aba Importar e coletar).
- [ ] Conferir a **Seleção de disputas** e os intervalos. Ao ligar Câmara de várias UFs, fazer em lotes pequenos e fora do pico.
- [ ] **Simulado final:** comparar a parcial com o portal oficial; confirmar 100% (`and`, `tf`, `md`) e as vagas do Senado; confirmar que o navegador não acessa domínio do TSE; guardar fixtures sanitizadas com hash e horário.

## Primeira hora
- [ ] **A10 — ler a latência real do TSE** na Visão geral ("Download do TSE", "Lock preso por tick", p95) ou em `/admin/health` (campo `perf`):
  - p95 abaixo de ~0,4 s: nada a mudar;
  - entre 0,4 e 0,6 s: menos UFs de Câmara ou `ae_heavy_interval` maior;
  - acima de ~0,6 s: abrir o **A2** (duas pistas de worker com locks separados, cerca de 1 dia, risco médio a alto).
- [ ] Medir requisições e pico no IP de saída; ver se há 429.
- [ ] Conferir o ZIP oficial de candidatos de 2026 (o teste usa um sintético).

## Noite da eleição
- [ ] Desligar a reimportação automática de candidatos.
- [ ] **A14 — 2º turno:** quando o EA11/EA20 do 2º turno aparecer, conferir se o sync criou a disputa nova com cargo, UF e `round_no` certos, e se o vínculo em `ae_candidate_contests` está correto.

## Depois do 1º turno, antes de 25/10
- [ ] **A15 — turno automático:** quando o 2º turno começar a apurar, conferir que os widgets (apuração, card, resumo e a faixa da home) viram sozinhos, que o selo "2º turno" aparece, que a faixa mostra só os finalistas e que o seletor de turno da apuração completa surge. Ver [docs/versoes.md](docs/versoes.md).
- [ ] Revisar o que a primeira apuração mostrou no log e na Visão geral.
