#!/bin/bash
# Dj AI — rebelai.alwaysdata.net ke liye install
# SSH: ssh rebelai@ssh-rebelai.alwaysdata.net
# Phir: wget ... && bash rebelai-install.sh

set -euo pipefail

GITHUB="https://raw.githubusercontent.com/ujjwalrebel53-wq/Dj-/main"
SITE_URL="https://rebelai.alwaysdata.net"
INSTALL_DIR="/home/rebelai/dj-ai"
PUBLIC_DIR="${INSTALL_DIR}/alwaysdata/public"

echo "=== Dj AI Install: rebelai.alwaysdata.net ==="

mkdir -p "${PUBLIC_DIR}" "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data"

wget -q "${GITHUB}/dj-ai.php" -O "${INSTALL_DIR}/dj-ai.php"
wget -q "${GITHUB}/alwaysdata/public/index.php" -O "${PUBLIC_DIR}/index.php"
wget -q "${GITHUB}/alwaysdata/public/.htaccess" -O "${PUBLIC_DIR}/.htaccess"
wget -q "${GITHUB}/alwaysdata/public/.user.ini" -O "${PUBLIC_DIR}/.user.ini"

cat > "${INSTALL_DIR}/.env" <<EOF
LLM_PROVIDER=wormgpt
WORMGPT_API_URL=https://wormgpt.freeapihub.workers.dev/chat
SITE_URL=${SITE_URL}
WORKSPACE_ROOT=${INSTALL_DIR}/workspace
DATA_DIR=${INSTALL_DIR}/.data
EOF

chmod 755 "${INSTALL_DIR}" "${PUBLIC_DIR}" "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data"
chmod 644 "${INSTALL_DIR}/dj-ai.php" "${PUBLIC_DIR}/index.php" "${INSTALL_DIR}/.env"

echo ""
echo "=== FILES READY ==="
echo ""
echo "AlwaysData panel (admin.alwaysdata.com):"
echo "  Web > Sites > rebelai.alwaysdata.net > Edit"
echo "  Type:        PHP"
echo "  PHP version: 8.2+"
echo "  Root dir:    ${PUBLIC_DIR}"
echo ""
echo "Save karo, phir test:"
echo "  curl ${SITE_URL}/health"
echo ""
echo "VS Code extension API URL:"
echo "  ${SITE_URL}"
