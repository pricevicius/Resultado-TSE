# TSE Apuração — operação e diagnóstico

Checklist de implantação por projeto e decisões já tomadas: [../PENDENCIAS.md](../PENDENCIAS.md) (seções C e D). Cron de sistema, saúde do disparo e alertas: [versoes.md](versoes.md) (2.3.0, 2.3.8, 2.4.0). Limites de acesso ao TSE: [arquitetura.md](arquitetura.md#limite-de-acesso-e-bloqueios).

## A paginação não aparece: roteiro de diagnóstico

Siga na ordem; o primeiro item explica a maioria dos casos.

1. **O site está com a versão certa?** A paginação existe a partir da **2.4.1**. Veja a versão
   no cabeçalho de `tse-apuracao.php` ou no canto da tela **Apuração**. Se for menor:
   - plugin em pasta comum: atualize os arquivos;
   - plugin como **submódulo git**: atualize o repositório do plugin **e** o ponteiro do
     submódulo no repositório do site (`git add <caminho do plugin>` + commit). Esquecer o
     ponteiro deixa o site com o código velho;
   - confirme a **branch**: `main`/`master` exige PHP 8.1+; `php7.4` é a variante para PHP 7.4.
     As duas recebem as mesmas correções, mas o site precisa estar na branch que usa.
2. **Há mais de 24 resultados no filtro atual?** Com 24 ou menos não existe navegação.
   Teste sem filtros, ou com `por_pagina="6"` para forçar várias páginas.
3. **O shortcode é o certo?** `[apuracao_candidatos]` (plural) é o catálogo paginado.
   `[tse_apuracao]` e `[tse_apuracao_card]` são os placares e não têm catálogo.
4. **Cache de página** (WP Rocket e similares, FastCGI do nginx, Cloudflare). HTML antigo em
   cache continua sem a navegação, e um cache que **ignora a query string** serve a página 1
   para todo `?ae_pagina=N`. Limpe o cache e garanta que `ae_pagina`, `ae_busca`, `ae_cargo`,
   `ae_uf` e `ae_partido` façam parte da chave de cache (ou que essas URLs não sejam
   cacheadas). O polling do placar não é afetado por isso, mas o catálogo é HTML renderizado.
5. **CSS antigo ou minificado.** Os assets são versionados por `AE_VERSION` (`?ver=2.4.1`),
   mas plugins de otimização e CDN podem manter o CSS velho. Sem o CSS novo a navegação ainda
   existe, só aparece como lista simples. Para confirmar que ela foi gerada, procure no HTML:

   ```bash
   curl -s 'https://SEU-SITE/pagina-do-catalogo/?ae_uf=SP' | grep -c 'ae-catalog-pagination'
   ```

   Resultado `0` com mais de 24 candidatos indica código desatualizado ou cache; `1` indica que
   o HTML está certo e o problema é CSS/tema.
6. **O tema esconde ou sobrescreve** `nav`, `.page-numbers` ou `ul` dentro do conteúdo.
   Inspecione o elemento `.ae-catalog-pagination` no navegador.
7. **O catálogo mostra poucos candidatos mesmo assim?** Confira quantos foram importados em
   **Apuração > Visão geral > Candidatos**. A importação traz só o CSV da UF e os cargos das
   disputas ligadas, e só titulares.
