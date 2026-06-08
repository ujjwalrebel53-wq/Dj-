#!/bin/bash
# Dj AI — AlwaysData VPS one-click install
# SSH se chalao: bash install.sh

set -euo pipefail

GITHUB="https://raw.githubusercontent.com/ujjwalrebel53-wq/Dj-/main"
INSTALL_DIR="${HOME}/dj-ai"
PUBLIC_DIR="${INSTALL_DIR}/alwaysdata/public"

echo "=== Dj AI AlwaysData Install ==="
echo "Install dir: ${INSTALL_DIR}"

mkdir -p "${PUBLIC_DIR}" "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data"

echo "[1/5] dj-ai.php download..."
wget -q "${GITHUB}/dj-ai.php" -O "${INSTALL_DIR}/dj-ai.php"

echo "[2/5] public files download..."
wget -q "${GITHUB}/alwaysdata/public/index.php" -O "${PUBLIC_DIR}/index.php"
wget -q "${GITHUB}/alwaysdata/public/.htaccess" -O "${PUBLIC_DIR}/.htaccess"
wget -q "${GITHUB}/alwaysdata/public/.user.ini" -O "${PUBLIC_DIR}/.user.ini"

echo "[3/5] .env setup..."
if [ ! -f "${INSTALL_DIR}/.env" ]; then
  wget -q "${GITHUB}/.env.example" -O "${INSTALL_DIR}/.env"
  {
    echo ""
    echo "WORKSPACE_ROOT=${INSTALL_DIR}/workspace"
    echo "DATA_DIR=${INSTALL_DIR}/.data"
  } >> "${INSTALL_DIR}/.env"
fi

echo "[4/5] permissions..."
chmod 755 "${INSTALL_DIR}" "${PUBLIC_DIR}" "${INSTALL_DIR}/workspace" "${INSTALL_DIR}/.data"
chmod 644 "${INSTALL_DIR}/dj-ai.php" "${PUBLIC_DIR}/index.php" "${INSTALL_DIR}/.env" 2>/dev/null || true

echo "[5/5] PHP check..."
php -v | head -1
php -m | grep -qi curl && echo "curl: OK" || echo "WARN: php-curl missing — AlwaysData panel mein enable karo"

echo ""
echo "=== INSTALL DONE ==="
echo ""
echo "AlwaysData panel mein ye settings karo:"
echo "  1. Web > Sites > Add a site"
echo "  2. Type: PHP"
echo "  3. PHP version: 8.2+"
echo "  4. Root directory: ${PUBLIC_DIR}"
echo "  5. Domain/subdomain apna set karo"
echo ""
echo "Test:"
echo "  curl https://YOUR-DOMAIN/health"
echo ""
echo "API URL VS Code extension ke liye:"
echo "  https://YOUR-DOMAIN"
