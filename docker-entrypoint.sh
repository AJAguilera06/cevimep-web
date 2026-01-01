#!/usr/bin/env bash
set -euo pipefail

: "${PORT:=8080}"

# Ensure only one MPM is loaded (PHP + Apache should use prefork).
a2dismod mpm_event >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# Ensure Apache listens on Railway's PORT.
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
