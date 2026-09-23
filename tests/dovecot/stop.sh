#!/bin/sh
docker rm -f mail-simply-mailpit mail-simply-dovecot > /dev/null 2>&1 || true
docker network rm mail-simply-test > /dev/null 2>&1 || true
