#!/bin/bash
# Phame game server at https://frode.ennerd.com/phame (nginx strips the prefix)
cd /home/frode/dev/phame/server
exec php vendor/bin/swerve --http=127.0.0.1:8400 --public=public -w 4 --log=swerve.log swerve.php
