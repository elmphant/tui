#!/usr/bin/env sh

set -eu

before=$(stty -g)
php "$(dirname "$0")/pty-smoke.php"
after=$(stty -g)

test "$before" = "$after"
