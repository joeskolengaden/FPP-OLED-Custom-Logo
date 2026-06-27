#!/bin/bash
# fpp-plugin-oledlogo uninstall script.
# Removes the boot deploy service and clears the deployed logo so fppoled falls
# back to its default (cape image / blank).

. ${FPPDIR}/scripts/common 2>/dev/null

SUDO="sudo"
[ "$(id -u)" = "0" ] && SUDO=""
UNIT=/etc/systemd/system/oledlogo-boot.service
MEDIADIR="${MEDIADIR:-/home/fpp/media}"

echo "oledlogo: removing boot deploy service"
$SUDO systemctl disable oledlogo-boot.service 2>/dev/null
$SUDO rm -f "$UNIT" 2>/dev/null
$SUDO systemctl daemon-reload 2>/dev/null

# Clear deployed copies (leave the master + settings in config/ so a reinstall
# keeps the user's logo).
rm -f "$MEDIADIR/tmp/cape-image.xbm" /var/tmp/cape-image.xbm 2>/dev/null

echo "oledlogo: uninstall complete"
exit 0
