#!/bin/bash
# deploy.sh - copy the saved OLED logo into the locations fppoled reads.
# Called from: the boot systemd unit, the lifecycle "startup" callback, and the
# web UI "Apply now" button. Honours the enabled flag in oledlogo.json.
#
# Usage: deploy.sh [boot|apply]

ACTION="${1:-boot}"
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
MEDIADIR="${MEDIADIR:-$(cd "$PLUGINDIR/../.." && pwd)}"
CFGDIR="$MEDIADIR/config"
MASTER="$CFGDIR/oledlogo.xbm"
DEFAULT="$PLUGINDIR/default-logo.xbm"
SETTINGS="$CFGDIR/oledlogo.json"
TARGETS=("$MEDIADIR/tmp/cape-image.xbm" "/var/tmp/cape-image.xbm")

# clear: remove the deployed copies (used on disable / remove)
if [ "$ACTION" = "clear" ]; then
    for T in "${TARGETS[@]}"; do rm -f "$T" 2>/dev/null && echo "oledlogo: cleared $T"; done
    exit 0
fi

# Pick the source: the user's saved logo if present, else the bundled default
# (so the plugin shows a logo out-of-the-box with nothing configured).
if [ ! -f "$MASTER" ]; then
    if [ -f "$DEFAULT" ]; then
        MASTER="$DEFAULT"
        echo "oledlogo: no custom logo, using bundled default"
    else
        echo "oledlogo: no master xbm and no default, nothing to deploy"; exit 0
    fi
fi

# enabled flag (defaults to true if settings missing)
ENABLED=1
if [ -f "$SETTINGS" ]; then
    if grep -qiE '"enabled"[[:space:]]*:[[:space:]]*false' "$SETTINGS"; then
        ENABLED=0
    fi
fi
[ "$ENABLED" = "1" ] || { echo "oledlogo: disabled, skipping deploy"; exit 0; }

for T in "${TARGETS[@]}"; do
    mkdir -p "$(dirname "$T")" 2>/dev/null
    if cp -f "$MASTER" "$T" 2>/dev/null; then
        # 0666 so the web user (fpp) can overwrite a root-created file later,
        # and root can overwrite an fpp-created one. Chown to fpp when we are root.
        chmod 0666 "$T" 2>/dev/null
        [ "$(id -u)" = "0" ] && chown fpp:fpp "$T" 2>/dev/null
        echo "oledlogo: deployed -> $T"
    else
        echo "oledlogo: WARN could not write $T"
    fi
done
exit 0
