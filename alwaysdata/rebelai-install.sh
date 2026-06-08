#!/bin/bash
# Dj AI — rebelai.alwaysdata.net
# AlwaysData default root = ~/www (panel change ki zaroorat NAHI)
#
# SSH: ssh rebelai@ssh-rebelai.alwaysdata.net
# Run: wget ... && bash rebelai-install.sh

set -euo pipefail

GITHUB="https://raw.githubusercontent.com/ujjwalrebel53-wq/Dj-/main"
SITE_URL="https://rebelai.alwaysdata.net"
INSTALL_DIR="${HOME}/dj-ai"
PUBLIC_DIR="${HOME}/www"

echo "=== Dj AI Install: rebelai.alwaysdata.net ==="
echo "API code:  ${INSTALL_DIR}"
echo "Web root:  ${PUBLIC_DIR}"

mkdir -p "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data" "${PUBLIC_DIR}"

echo "[1/4] API download..."
wget -q "${GITHUB}/dj-ai.php" -O "${INSTALL_DIR}/dj-ai.php"

echo "[2/4] www files download..."
wget -q "${GITHUB}/alwaysdata/www/index.php" -O "${PUBLIC_DIR}/index.php"
wget -q "${GITHUB}/alwaysdata/www/.htaccess" -O "${PUBLIC_DIR}/.htaccess"
wget -q "${GITHUB}/alwaysdata/public/.user.ini" -O "${PUBLIC_DIR}/.user.ini"

echo "[3/4] .env create..."
cat > "${INSTALL_DIR}/.env" <<EOF
LLM_PROVIDER=wormgpt
WORMGPT_API_URL=https://wormgpt.freeapihub.workers.dev/chat
SITE_URL=${SITE_URL}
WORKSPACE_ROOT=${INSTALL_DIR}/workspace
DATA_DIR=${INSTALL_DIR}/.data
EOF

echo "[4/4] permissions..."
chmod 755 "${INSTALL_DIR}" "${PUBLIC_DIR}" "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data"
chmod 644 "${INSTALL_DIR}/dj-ai.php" "${PUBLIC_DIR}/index.php" "${INSTALL_DIR}/.env" 2>/dev/null || true

echo ""
echo "=== DONE ==="
echo ""
echo "Panel check (usually already OK):"
echo "  Web > Sites > rebelai.alwaysdata.net"
echo "  Type: PHP 8.2+"
echo "  Root: ${PUBLIC_DIR}"
echo ""
echo "Test:"
echo "  curl ${SITE_URL}/health"
echo "  curl ${SITE_URL}/"
echo ""
echo "VS Code API URL: ${SITE_URL}"
