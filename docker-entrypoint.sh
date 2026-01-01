#!/usr/bin/env bash
set -euo pipefail

: "${PORT:=8080}"

# Ensure Apache listens on Railway's PORT.
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
