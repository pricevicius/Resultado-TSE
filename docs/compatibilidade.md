# TSE Apuração — versões de PHP e de WordPress

Política: as **três branches são suportadas e recebem as mesmas mudanças** (`main`, `php7.4` e `php7.2`). A `php7.2` não é um favor temporário: há um cliente que a usa por um bom tempo, então ela tem o mesmo nível de teste e de release das outras (ver "Suporte contínuo à `php7.2`" abaixo).


| Branch | PHP | WordPress | Observação |
| --- | --- | --- | --- |
| `main` | 8.1+ | 5.5+ recomendado | código-fonte de referência |
| `php7.4` | 7.4+ | idem | mesmos commits da `main` |
| `php7.2` | **7.2+** | **4.9+** | gerada da `php7.4` por `tools/port-php72.py`; release `v2.5.0-php7.2` |

O plugin não depende da versão do WordPress: `includes/compat.php` define `str_contains`, `str_starts_with`,
`str_ends_with` e `wp_date()` quando não existem, e o bloco Gutenberg só é registrado quando o WordPress o
suporta (5.5+); nas versões antigas o shortcode `[tse_apuracao]` (e os demais) cobre o mesmo uso. Validado em
PHP 7.2.12 + WordPress 4.9.8 (front com todos os shortcodes, telas do admin autenticadas, REST e as suítes de
`tests/`), PHP 7.2.34 + WordPress 5.6, PHP 7.4.33 + WordPress 6.1 e PHP 8.2 + WordPress atual. PHP 7.2 e 7.3
estão sem correção de segurança: a variante existe para projetos que ainda não conseguiram migrar. Como
regenerar a `php7.2` (`tools/regen-php72.sh`, sem reescrever o histórico) está em [PENDENCIAS.md](PENDENCIAS.md) (seção E).

**Rodada da 2.6.0 (`tests/run-matrix.sh`, containers descartáveis com MariaDB próprio, `php-zip` incluído nas imagens oficiais):**
`admin-smoke`, `wp-integration`, `wp-collect`, `wp-latency` e `wp-import-zip` passaram em **PHP 7.4.33 + WordPress 6.1.1**,
**PHP 7.2.34 + WordPress 5.6** e **PHP 7.2.12 + WordPress 4.9.8** (a `php7.2` gerada por `tools/regen-php72.sh`). Em PHP 8.2 (site
local) passaram também `http-admin.sh` e `run-concurrency.sh`. O custo local por coleta ficou na mesma ordem nas três
versões (leve 48–62 ms, pesada de 1.100 candidatos 160–290 ms).

## Suporte contínuo à `php7.2`

- **Origem única:** a `php7.2` nunca é editada à mão. Ela é gerada da `php7.4` por `tools/regen-php72.sh` (que usa `tools/port-php72.py`) e avança sem reescrever o histórico publicado.
- **Código novo na `main`:** deve evitar o que o `port-php72.py` não sabe converter. Se aparecer algo novo, o script **para com erro** em vez de gerar código errado; nesse caso, ensine o script ou reescreva o trecho. A `php7.4` não usa `match`, `throw` em expressão nem o tipo `mixed`.
- **Release:** `main` → cherry-pick na `php7.4` → `tools/regen-php72.sh` → `tools/check-branches.sh` (lint em PHP 7.2, 7.4 e 8.2, versões iguais, árvore da `php7.2` idêntica ao que o script gera) → `tests/run-matrix.sh` nos três alvos → tags `vX.Y.Z`, `vX.Y.Z-php7.4`, `vX.Y.Z-php7.2`.
- **Alvos de teste da `php7.2`:** PHP 7.2.34 + WordPress 5.6 e PHP 7.2.12 + WordPress 4.9.8 (o mais antigo suportado).
- **Segurança:** o PHP 7.2 está sem correção desde 2020. O risco é do ambiente do cliente, não do plugin: o plugin só lê do TSE, não executa entrada de terceiros e escapa a saída. Recomende ao cliente isolar o servidor (rede, atualizações do sistema, WAF/CDN na frente) e registre a decisão de migrar, mesmo sem data.
- **Aposentadoria:** só quando o cliente confirmar a migração para PHP 7.4 ou superior. Até lá, bugs corrigidos na `main` entram nas três branches no mesmo ciclo.
