#!/bin/sh
# Confere que as três branches estão coerentes antes de um release (nada é alterado, nada é publicado):
#   1. a php7.4 contém todos os commits da main (cherry-picks equivalentes);
#   2. a php7.2 é exatamente o que tools/port-php72.py gera a partir da php7.4;
#   3. a versão do plugin é a mesma nas três;
#   4. php -l em todos os arquivos PHP: main em 8.2, php7.4 em 7.4 e php7.2 em 7.2 (containers php:X-cli).
# Rode com a árvore limpa. Requer Docker e Python 3.
set -eu
cd "$(git rev-parse --show-toplevel)"
[ -z "$(git status --porcelain)" ] || { echo "check-branches: a árvore tem alterações; faça commit ou stash antes." >&2; exit 1; }
for b in main php7.4 php7.2; do git rev-parse --verify -q "$b" >/dev/null || { echo "check-branches: falta a branch $b." >&2; exit 1; }; done
fail=0
bad() { echo "FALHA: $1" >&2; fail=1; }
TMP=$(mktemp -d); trap 'git worktree prune; rm -rf "$TMP"' EXIT

# 1) commits da main que faltam na php7.4 (git cherry marca com "+" o que não tem equivalente)
missing=$(git cherry php7.4 main | grep '^+' | wc -l | tr -d ' ')
[ "$missing" = 0 ] && echo "ok   php7.4 contém todos os commits da main" || bad "$missing commit(s) da main sem equivalente na php7.4 (git cherry php7.4 main)"

# 2) php7.2 == port(php7.4)
git worktree add -q --detach "$TMP/gen" php7.4
( cd "$TMP/gen" && python3 tools/port-php72.py >/dev/null )
git worktree add -q --detach "$TMP/p72" php7.2
if diff -rq -x .git "$TMP/gen" "$TMP/p72" >/dev/null; then echo "ok   php7.2 idêntica ao que o port-php72.py gera da php7.4"; else bad "a php7.2 difere do port da php7.4 (rode tools/regen-php72.sh)"; diff -rq -x .git "$TMP/gen" "$TMP/p72" | head -5 >&2; fi

# 3) versões
v() { git show "$1:tse-apuracao.php" | sed -n "s/^ \* Version: *//p"; }
if [ "$(v main)" = "$(v php7.4)" ] && [ "$(v main)" = "$(v php7.2)" ]; then echo "ok   versão $(v main) nas três branches"; else bad "versões diferentes: main=$(v main) php7.4=$(v php7.4) php7.2=$(v php7.2)"; fi

# 4) lint
git worktree add -q --detach "$TMP/m" main
lint() { # pasta imagem
	out=$(docker run --rm -v "$1":/p "$2" sh -c 'find /p -name "*.php" -not -path "*/.git/*" | while read f; do php -l "$f" 2>&1 | grep -v "^No syntax errors"; done' || true)
	[ -z "$out" ] && echo "ok   lint $3" || { bad "lint $3"; echo "$out" >&2; }
}
lint "$TMP/m" php:8.2-cli "main (PHP 8.2)"
lint "$TMP/gen" php:7.4-cli "php7.4 (PHP 7.4)"
lint "$TMP/p72" php:7.2-cli "php7.2 (PHP 7.2)"
exit $fail
