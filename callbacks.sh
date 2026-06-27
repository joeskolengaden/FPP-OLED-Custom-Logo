#!/bin/bash
# FPP plugin callbacks for OLED Boot Logo.
# We register for the "lifecycle" callback and re-deploy the logo on fppd
# startup. The primary boot-time deploy is handled by the oledlogo-boot systemd
# unit (installed by fpp_install.sh); this is a belt-and-braces re-deploy that
# also covers fppd restarts.

PLUGINDIR="$(cd "$(dirname "$0")" && pwd)"

case "$1" in
    --list)
        echo "lifecycle"
        ;;
    --type)
        # invoked as: callbacks.sh --type lifecycle <startup|shutdown>
        if [ "$2" = "lifecycle" ] && [ "$3" = "startup" ]; then
            "$PLUGINDIR/scripts/deploy.sh" startup >/dev/null 2>&1 &
        fi
        ;;
esac
exit 0
