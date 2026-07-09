#!/usr/bin/env bash
# Switch the active nera-all-in-one plugin checkout for live wp-admin preview.
# Usage: ./tools/preview-button-design.sh {main|v1|v2|v3}
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGINS_DIR="$(cd "${PLUGIN_ROOT}/.." && pwd)"
PLUGIN_LINK="${PLUGINS_DIR}/nera-all-in-one"
BACKUP="${PLUGINS_DIR}/nera-all-in-one.main-backup"
WORKTREES="${PLUGINS_DIR}/.worktrees/nera-aio"

VARIANT="${1:-}"

usage() {
	echo "Usage: $0 {main|v1|v2|v3}" >&2
	echo "" >&2
	echo "  main  Restore the main worktree checkout" >&2
	echo "  v1    Accent Pill   (design/install-btn-v1)" >&2
	echo "  v2    Accent Ghost  (design/install-btn-v2)" >&2
	echo "  v3    Action Bar    (design/install-btn-v3)" >&2
	exit 1
}

case "$VARIANT" in
	main)
		if [[ -L "${PLUGIN_LINK}" && -d "${BACKUP}" ]]; then
			rm -f "${PLUGIN_LINK}"
			mv "${BACKUP}" "${PLUGIN_LINK}"
			echo "Restored main checkout at ${PLUGIN_LINK}"
		elif [[ -d "${PLUGIN_LINK}" && ! -L "${PLUGIN_LINK}" ]]; then
			echo "Already on main checkout: ${PLUGIN_LINK}"
		else
			echo "Cannot restore main — backup directory missing." >&2
			exit 1
		fi
		;;
	v1|v2|v3)
		case "$VARIANT" in
			v1) TARGET="${WORKTREES}/install-btn-v1-accent-pill" ;;
			v2) TARGET="${WORKTREES}/install-btn-v2-accent-ghost" ;;
			v3) TARGET="${WORKTREES}/install-btn-v3-action-bar" ;;
		esac
		if [[ ! -d "${TARGET}" ]]; then
			echo "Target directory not found: ${TARGET}" >&2
			echo "Run git worktree add from the plugin repo first." >&2
			exit 1
		fi
		if [[ ! -L "${PLUGIN_LINK}" && ! -d "${BACKUP}" && -d "${PLUGIN_LINK}" ]]; then
			echo "Backing up main checkout to ${BACKUP}"
			mv "${PLUGIN_LINK}" "${BACKUP}"
			ln -sfn "${TARGET}" "${PLUGIN_LINK}"
		elif [[ -L "${PLUGIN_LINK}" ]]; then
			ln -sfn "${TARGET}" "${PLUGIN_LINK}"
		else
			echo "Unexpected plugin path state at ${PLUGIN_LINK}" >&2
			exit 1
		fi
		echo "Active plugin → ${TARGET}"
		;;
	*)
		usage
		;;
esac

echo "Preview: https://competitions-core.local/wp-admin/admin.php?page=nera-all-in-one"
echo "Hard-refresh wp-admin after switching."
