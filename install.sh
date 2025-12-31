#!/bin/bash
#
# Linphone QR Code - Installer per FusionPBX
# Basato su app Sveglie
#

echo ""
echo "========================================"
echo "  Linphone QR Code - Installer"
echo "========================================"
echo ""

# Check root
if [[ $EUID -ne 0 ]]; then
   echo "❌ Eseguire come root"
   exit 1
fi

FUSIONPBX_PATH="/var/www/fusionpbx"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# Check FusionPBX
if [ ! -d "$FUSIONPBX_PATH" ]; then
    echo "❌ FusionPBX non trovato in $FUSIONPBX_PATH"
    exit 1
fi

# Leggo config database
CONFIG_FILE=""
[ -f "/etc/fusionpbx/config.conf" ] && CONFIG_FILE="/etc/fusionpbx/config.conf"
[ -f "/usr/local/etc/fusionpbx/config.conf" ] && CONFIG_FILE="/usr/local/etc/fusionpbx/config.conf"

if [ -z "$CONFIG_FILE" ]; then
    echo "❌ Config FusionPBX non trovato"
    exit 1
fi

DB_HOST=$(grep "^database.0.host" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' ')
DB_PORT=$(grep "^database.0.port" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' ')
DB_NAME=$(grep "^database.0.name" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' ')
DB_USER=$(grep "^database.0.username" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' ')
DB_PASS=$(grep "^database.0.password" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' ')
DB_PORT=${DB_PORT:-5432}

echo "✓ Config database trovato"

# 1. Trovo parent_uuid (stesso di Extensions)
echo ""
echo "1. Cerco parent_uuid del menu..."
PARENT_UUID=$(PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -A -c "SELECT menu_item_parent_uuid FROM v_menu_items WHERE menu_item_title = 'Extensions' LIMIT 1;" 2>/dev/null)

if [ -z "$PARENT_UUID" ]; then
    echo "   ⚠ Parent non trovato da Extensions, provo con Accounts..."
    PARENT_UUID=$(PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -A -c "SELECT menu_item_uuid FROM v_menu_items WHERE menu_item_title = 'Accounts' LIMIT 1;" 2>/dev/null)
fi

if [ -z "$PARENT_UUID" ]; then
    echo "❌ Impossibile trovare parent_uuid"
    exit 1
fi

echo "   ✓ Parent UUID: $PARENT_UUID"

# 2. Copio i file
echo ""
echo "2. Copio i file dell'app..."
rm -rf "$FUSIONPBX_PATH/app/linphone_qrcode"
cp -r "$SCRIPT_DIR/app/linphone_qrcode" "$FUSIONPBX_PATH/app/"
echo "   ✓ File copiati"

# 3. Sostituisco PARENT_UUID_PLACEHOLDER con il valore reale
echo ""
echo "3. Configuro parent_uuid nei file..."
sed -i "s/PARENT_UUID_PLACEHOLDER/$PARENT_UUID/g" "$FUSIONPBX_PATH/app/linphone_qrcode/app_config.php"
sed -i "s/PARENT_UUID_PLACEHOLDER/$PARENT_UUID/g" "$FUSIONPBX_PATH/app/linphone_qrcode/app_menu.php"
echo "   ✓ parent_uuid configurato"

# 4. Imposto permessi
echo ""
echo "4. Imposto permessi file..."
chown -R www-data:www-data "$FUSIONPBX_PATH/app/linphone_qrcode"
chmod -R 755 "$FUSIONPBX_PATH/app/linphone_qrcode"
echo "   ✓ Permessi impostati"

# 5. Verifico sintassi PHP
echo ""
echo "5. Verifico sintassi PHP..."
php -l "$FUSIONPBX_PATH/app/linphone_qrcode/app_config.php" 2>&1 | grep -v "No syntax"
php -l "$FUSIONPBX_PATH/app/linphone_qrcode/app_menu.php" 2>&1 | grep -v "No syntax"
php -l "$FUSIONPBX_PATH/app/linphone_qrcode/qrcode.php" 2>&1 | grep -v "No syntax"
echo "   ✓ Sintassi OK"

# 6. Pulisco cache
echo ""
echo "6. Pulisco cache FusionPBX..."
rm -rf /var/cache/fusionpbx/*
echo "   ✓ Cache pulita"

# 7. Mostra contenuto file per verifica
echo ""
echo "7. Verifica app_config.php:"
grep -A2 "parent_uuid" "$FUSIONPBX_PATH/app/linphone_qrcode/app_config.php"

echo ""
echo "========================================"
echo "  ✓ Installazione completata!"
echo "========================================"
echo ""
echo "PROSSIMI PASSI:"
echo ""
echo "1. Accedi a FusionPBX come superadmin"
echo ""
echo "2. Vai su: Advanced > Upgrade"
echo "   - Clicca 'Menu Defaults'"
echo "   - Clicca 'Permission Defaults'"
echo ""
echo "3. Logout e Login"
echo ""
echo "4. Troverai il menu sotto: Accounts > QR Code Linphone"
echo ""
echo "========================================"
echo ""
