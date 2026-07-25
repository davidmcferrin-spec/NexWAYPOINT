#!/bin/sh
echo "Update Clone of Repository"
bash ./setup.sh update
echo "Deploy Application"
bash ./setup.sh deploy
echo "Run Migrations"
php scripts/migrate.php
