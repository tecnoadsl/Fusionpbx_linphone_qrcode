#!/bin/bash
echo "Rimozione Linphone QR Code..."
rm -rf /var/www/fusionpbx/app/linphone_qrcode
rm -rf /var/cache/fusionpbx/*
echo ""
echo "✓ App rimossa"
echo ""
echo "Vai su: Advanced > Upgrade > Menu Defaults"
echo "per aggiornare il menu."
echo ""
