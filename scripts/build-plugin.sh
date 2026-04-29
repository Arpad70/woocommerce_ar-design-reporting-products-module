#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="ar-design-reporting-products-module"
VERSION_FILE="${ROOT_DIR}/VERSION"
DISTIGNORE_FILE="${ROOT_DIR}/.distignore"
BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

if [[ ! -f "${VERSION_FILE}" ]]; then
	echo "Missing VERSION file: ${VERSION_FILE}" >&2
	exit 1
fi

VERSION="$(tr -d '[:space:]' < "${VERSION_FILE}")"
ZIP_FILE="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "${STAGING_DIR}"
mkdir -p "${STAGING_DIR}"
mkdir -p "${BUILD_DIR}"

RSYNC_EXCLUDES=()

if [[ -f "${DISTIGNORE_FILE}" ]]; then
	while IFS= read -r line; do
		[[ -z "${line}" ]] && continue
		RSYNC_EXCLUDES+=("--exclude=${line}")
	done < "${DISTIGNORE_FILE}"
fi

rsync -a "${RSYNC_EXCLUDES[@]}" "${ROOT_DIR}/" "${STAGING_DIR}/"

rm -f "${ZIP_FILE}"
(
	cd "${BUILD_DIR}"
	zip -rq "${ZIP_FILE}" "${PLUGIN_SLUG}"
)

echo "Created package: ${ZIP_FILE}"
