#!/usr/bin/env bash
# Jalankan test suite backend dengan database testing TERPISAH.
#
# Kenapa perlu:
#   Database `astaloka_testing` dipakai bersama oleh semua proses test yang
#   jalan di mesin ini. Dua suite yang jalan bersamaan akan saling menabrak
#   saat migrasi (drop table / create table) dan menghasilkan galat palsu
#   seperti "Table 'cache' already exists" atau "Deadlock found when trying
#   to get lock".
#
#   Database ini dipakai khusus oleh proses ini saja, jadi tidak akan
#   bertabrakan dengan suite lain.
set -euo pipefail

export DB_DATABASE="${DB_DATABASE:-astaloka_testing_agent}"
export TENANCY_CENTRAL_DATABASE="${TENANCY_CENTRAL_DATABASE:-astaloka_testing_agent_central}"

cd "$(dirname "$0")/backend"

exec php artisan test "$@"
