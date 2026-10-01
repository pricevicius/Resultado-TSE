#!/bin/sh
# Atualiza a branch php7.2 a partir da php7.4 SEM reescrever o histórico publicado:
# gera a variante num commit (tools/port-php72.py) e faz a php7.2 avançar por um commit de
# junção com a mesma árvore (avanço simples, sem push forçado).
# Rode com a árvore limpa. Depois: lint no PHP 7.2, suítes em WordPress com PHP 7.2, tag e push.
set -eu
cd "$(git rev-parse --show-toplevel)"
[ -z "$(git status --porcelain)" ] || { echo "regen-php72: a árvore tem alterações; faça commit ou stash antes." >&2; exit 1; }
git rev-parse --verify -q php7.4 >/dev/null || { echo "regen-php72: branch php7.4 não encontrada." >&2; exit 1; }
git rev-parse --verify -q php7.2 >/dev/null || { echo "regen-php72: branch php7.2 não encontrada (crie-a a partir da php7.4 na primeira vez)." >&2; exit 1; }
origem=$(git rev-parse --short php7.4)
git checkout -q -B php7.2-gerada php7.4
python3 tools/port-php72.py
git add -A
git commit -q -m "port: variante PHP 7.2 gerada de php7.4 @ $origem (tools/port-php72.py)"
gerado=$(git rev-parse HEAD)
git checkout -q php7.2
juncao=$(git commit-tree "$gerado^{tree}" -p php7.2 -p "$gerado" -m "merge: regenera a php7.2 a partir da php7.4 @ $origem sem reescrever o histórico")
git merge -q --ff-only "$juncao"
git branch -q -D php7.2-gerada
echo "regen-php72: php7.2 em $(git rev-parse --short HEAD) (commit gerado $(git rev-parse --short "$gerado"), origem php7.4 @ $origem)"
