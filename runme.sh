#!/bin/sh

bash ./setup.sh update
bash ./setup.sh deploy
php scripts/migrate.php
