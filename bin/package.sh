#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="stockdesk-for-woocommerce"
DIST_DIR="${ROOT_DIR}/dist"
STAGING_DIR="${DIST_DIR}/${PLUGIN_SLUG}"

cd "${ROOT_DIR}"

VERSION="$(
	grep -m 1 'Version:' "${ROOT_DIR}/${PLUGIN_SLUG}.php" \
		| sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/'
)"

if [[ -z "${VERSION}" ]]; then
	echo "Could not read plugin version from ${PLUGIN_SLUG}.php" >&2
	exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${ROOT_DIR}/${ZIP_NAME}"

echo "Building assets..."
npm run build

echo "Staging plugin files..."
rm -rf "${STAGING_DIR}"
mkdir -p "${STAGING_DIR}"

rsync -a \
	--exclude-from="${ROOT_DIR}/.distignore" \
	"${ROOT_DIR}/" \
	"${STAGING_DIR}/"

echo "Creating ${ZIP_NAME}..."
rm -f "${ZIP_PATH}"

(
	cd "${DIST_DIR}"
	zip -r "${ZIP_PATH}" "${PLUGIN_SLUG}" -x "*.DS_Store"
)

rm -rf "${DIST_DIR}"

echo "Done: ${ZIP_PATH}"
