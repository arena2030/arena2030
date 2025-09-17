#!/usr/bin/env bash
set -e

PORT="${PORT:-8080}"

# Imposta la porta in Apache
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:.*>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf

# Hardening base sessione (se usi $_SESSION)
PHP_INI_DIR="/usr/local/etc/php/conf.d"
mkdir -p "$PHP_INI_DIR"
cat > "$PHP_INI_DIR/session.ini" <<'INI'
session.cookie_httponly=1
session.cookie_samesite=Strict
; Abilita se hai TLS end-to-end nel container
; session.cookie_secure=1
INI

# Avvia Apache
exec apache2-foreground
