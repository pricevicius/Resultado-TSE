# TSE Apuração — planejado e não implementado


### EA14/EA15 e mapas

Para mapas municipais em escala nacional, a próxima fase deve:

1. consultar EA14 para detectar UFs alteradas;
2. consultar EA15 somente nas UFs alteradas;
3. enfileirar EA20 somente para municípios/cargos alterados;
4. importar EA12 para relacionar código TSE, município, UF e IBGE;
5. criar snapshots e endpoint REST geográfico compacto;
6. carregar geometria estática pelo CDN do publisher e votos pela API local.

### Candidatos

- cache próprio de fotos, se a política editorial exigir independência do CDN TSE;
- páginas individuais, vice/suplentes (hoje fora da importação, por decisão) e dados complementares;
- atualização programada quatro vezes ao dia.

### Segurança e operação

- validar assinatura digital X.509 de cada JSON;
- monitorar alteração dos contratos EA11/EA20;
- alertas externos para fila, bloqueio, atraso e divergência;
- retenção/compactação de snapshots aprovada por redação e infraestrutura.
