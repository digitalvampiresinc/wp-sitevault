#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
PACKAGE_DIR="${BUILD_DIR}/wp-sitevault"
ZIP_FILE="${BUILD_DIR}/wp-sitevault.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_DIR}/admin/views" "${PACKAGE_DIR}/admin/assets/css" "${PACKAGE_DIR}/includes"

cp "${ROOT_DIR}/sitevault.php" "${PACKAGE_DIR}/"
cp "${ROOT_DIR}/admin/class-admin.php" "${PACKAGE_DIR}/admin/"
cp "${ROOT_DIR}/admin/views/dashboard.php" "${PACKAGE_DIR}/admin/views/"
cp "${ROOT_DIR}/admin/assets/css/admin.css" "${PACKAGE_DIR}/admin/assets/css/"
cp "${ROOT_DIR}/includes/"*.php "${PACKAGE_DIR}/includes/"

cd "${BUILD_DIR}"
zip -qr "wp-sitevault.zip" "wp-sitevault"

echo "Built ${ZIP_FILE}"
