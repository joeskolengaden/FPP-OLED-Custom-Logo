#!/bin/bash
# fpp-plugin-oledlogo install script.
# Installs a small systemd unit that re-deploys the custom OLED logo on every
# boot, ordered After=fppinit (so it runs after detect_cape wipes media/tmp and
# extracts any cape image) and Before=fppoled (so the logo is in place before the
# OLED driver reads it).

. ${FPPDIR}/scripts/common 2>/dev/null

PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
SUDO="sudo"
[ "$(id -u)" = "0" ] && SUDO=""

UNIT=/etc/systemd/system/oledlogo-boot.service

echo "oledlogo: installing boot deploy service"
# Ordering notes:
#  - Before=fppoled.service: the logo must be deployed before the OLED driver
#    reads it at startup.
#  - We deliberately do NOT use After=fppinit.service: on FPP, fppinit is ordered
#    after fppoled in a way that makes "After=fppinit + Before=fppoled" a cycle,
#    which systemd resolves by silently dropping our Before=fppoled (so we'd run
#    too late). After=local-fs.target/home-fpp-media.mount is enough.
#  - We deploy to BOTH media/tmp and /var/tmp. fppinit's detect_cape wipes
#    media/tmp/* at boot, but it does NOT touch /var/tmp, and fppoled falls back
#    to /var/tmp/cape-image.xbm - so the /var/tmp copy is what reliably survives.
$SUDO tee "$UNIT" >/dev/null <<EOF
[Unit]
Description=FPP custom OLED boot logo deploy
DefaultDependencies=no
After=local-fs.target home-fpp-media.mount
Wants=home-fpp-media.mount
Before=fppoled.service
ConditionPathExists=$PLUGINDIR/scripts/deploy.sh

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=MEDIADIR=${MEDIADIR:-/home/fpp/media}
ExecStart=$PLUGINDIR/scripts/deploy.sh boot

[Install]
WantedBy=sysinit.target
EOF

chmod +x "$PLUGINDIR/scripts/deploy.sh" "$PLUGINDIR/callbacks.sh" 2>/dev/null

$SUDO systemctl daemon-reload 2>/dev/null
$SUDO systemctl enable oledlogo-boot.service 2>/dev/null

# Deploy immediately if a logo already exists (e.g. reinstall / update).
"$PLUGINDIR/scripts/deploy.sh" install 2>/dev/null

echo "oledlogo: install complete"
exit 0
