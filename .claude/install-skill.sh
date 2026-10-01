#!/bin/sh
# Instala a skill "tse-apuracao" como skill global do Claude Code (~/.claude/skills),
# para ela valer em qualquer projeto, não só dentro deste repositório.
#
#   .claude/install-skill.sh           copia a skill (padrão)
#   .claude/install-skill.sh --link    cria um link simbólico (atualiza junto com o repositório)
#
# Variável opcional: CLAUDE_SKILLS_DIR (padrão: $HOME/.claude/skills).
# Se já existir uma skill com esse nome, ela é guardada como tse-apuracao.bak-<data>.
set -eu

NAME="tse-apuracao"
SRC="$(cd "$(dirname "$0")" && pwd)/skills/$NAME"
DEST_DIR="${CLAUDE_SKILLS_DIR:-$HOME/.claude/skills}"
DEST="$DEST_DIR/$NAME"
MODE="copy"

case "${1:-}" in
	"") ;;
	--link) MODE="link" ;;
	-h|--help) sed -n '2,10p' "$0"; exit 0 ;;
	*) echo "Opção desconhecida: $1 (use --link ou nenhuma)" >&2; exit 2 ;;
esac

if [ ! -f "$SRC/SKILL.md" ]; then
	echo "ERRO: $SRC/SKILL.md não encontrado." >&2
	exit 1
fi

mkdir -p "$DEST_DIR"

if [ -e "$DEST" ] || [ -L "$DEST" ]; then
	BACKUP="$DEST.bak-$(date +%Y%m%d-%H%M%S)"
	mv "$DEST" "$BACKUP"
	echo "Skill anterior guardada em: $BACKUP"
fi

if [ "$MODE" = "link" ]; then
	ln -s "$SRC" "$DEST"
else
	cp -R "$SRC" "$DEST"
fi

echo "Skill '$NAME' instalada em $DEST ($MODE). Abra uma nova sessão do Claude Code para ela aparecer."
