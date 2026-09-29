#!/usr/bin/env bash
# Deploy web panel from repo checkout on lab VPS (run as root on host).
set -euo pipefail
REPO=/root/Proxy_Email
PANEL=/var/www/mail-proxy
TARGET="${PANEL_DEPLOY_BRANCH:-feature/panel-ui-modernization}"
LOG=/tmp/panel_ui_deploy.log

exec > >(tee -a "$LOG") 2>&1
echo "=== PANEL UI DEPLOY $(date -u +%Y-%m-%dT%H:%M:%SZ) branch=$TARGET ==="

cd "$REPO"
echo "PRE_HEAD=$(git rev-parse HEAD)"
git fetch origin "$TARGET"
git checkout --detach "origin/$TARGET"
echo "DEPLOYED_REV=$(git rev-parse HEAD)"

if [ -f "$PANEL/config.php" ]; then
  cp -a "$PANEL/config.php" /tmp/mail-proxy-config.php.bak
fi
rsync -a --delete --exclude config.php web/ "$PANEL/"
if [ -f /tmp/mail-proxy-config.php.bak ]; then
  cp -a /tmp/mail-proxy-config.php.bak "$PANEL/config.php"
fi
chown -R www-data:www-data "$PANEL" 2>/dev/null || true

php -l "$PANEL/index.php"
php -l "$PANEL/includes/panel_modals.php"
php -l "$PANEL/includes/dashboard_ui.php"

php "$REPO/tests/panel_log_viewer_test.php"
php "$REPO/tests/panel_list_render_test.php" || true
php "$REPO/tests/panel_routing_test.php" || true

code=$(curl -k -s -o /dev/null -w "%{http_code}" -H "Host: panel.testvps.loc" "https://127.0.0.1/index.php")
echo "index_unauth_http=$code"
code2=$(curl -k -s -o /dev/null -w "%{http_code}" -H "Host: panel.testvps.loc" "https://127.0.0.1/assets/panel-modal.css")
echo "panel_modal_css_http=$code2"
test -f "$PANEL/assets/panel-modal.css" && echo "panel_modal_css_present=ok"
grep -q dashboard_ui "$PANEL/index.php" && echo "dashboard_marker=ok"
echo "LOG=$LOG"
echo "=== DONE ==="
