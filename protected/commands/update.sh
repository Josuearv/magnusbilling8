#!/bin/bash
clear
echo
echo
echo
echo "==================== JOSUEARV MAGNUSBILLING 8 ========================";
echo "_      _                               ______ _ _ _ _  			     ";
echo "|\    /|                               | ___ (_) | (_) 			     ";
echo "| \  / | ___  ____ _ __  _   _   _____ | |_/ /_| | |_ _ __   __ _ 	 ";
echo "|  \/  |/   \/  _ \| '_ \| | | \| ___| | ___ \ | | | | '_ \ /  _ \	 ";
echo "| |\/| |  | |  (_| | | | | |_| ||____  | |_/ / | | | | | | |  (_| |	 ";
echo "|_|  |_|\___|\___  |_| | |_____|_____|  \___/|_|_|_|_|_| |_|\___  |	 ";
echo "                _/ |                                           _/ |	 ";
echo "               |__/                                           |__/ 	 ";
echo "														                 ";
echo "============================== UPDATE =================================";
echo

set -euo pipefail

MBILLING_DIR="/var/www/html/mbilling"
UPDATE_COMMAND="$MBILLING_DIR/protected/commands/updateCommand.sh"
LOCK_FILE="/run/lock/magnusbilling-update.lock"

# Evitar actualizaciones simultaneas
exec 9>"$LOCK_FILE"
flock -n 9 || {
    echo "Another MagnusBilling update is already running."
    exit 1
}

if [[ -e "$MBILLING_DIR/protected/commands/update2.sh" ]]; then
    bash "$MBILLING_DIR/protected/commands/update2.sh"
    exit
fi

cd "$MBILLING_DIR"

# Configurar directorio seguro para Git
git config --global --add safe.directory "$MBILLING_DIR" 2>/dev/null || true

echo "Fetching updates from Git repository (Josuearv/magnusbilling8 - branch main)..."
git fetch origin main
git reset --hard origin/main

if [[ ! -f "$UPDATE_COMMAND" ]]; then
    echo "ERROR: updateCommand.sh was not found."
    exit 1
fi

bash "$UPDATE_COMMAND"

if [[ -e "$MBILLING_DIR/protected/commands/update3.sh" ]]; then
    bash "$MBILLING_DIR/protected/commands/update3.sh"
fi

echo "MagnusBilling updated successfully from Josuearv/magnusbilling8."
