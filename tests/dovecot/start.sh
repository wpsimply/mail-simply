#!/bin/sh
#
# Start the test mail server: Dovecot (IMAP on 31143, submission on 31587)
# relaying to Mailpit (web UI on 31080). Stop it with tests/dovecot/stop.sh.
set -eu

dir="$(cd "$(dirname "$0")" && pwd)"
image="${MAIL_SIMPLY_TEST_DOVECOT_IMAGE:-dovecot/dovecot:2.4.4-root}"

docker network inspect mail-simply-test > /dev/null 2>&1 || docker network create mail-simply-test > /dev/null
docker rm -f mail-simply-mailpit mail-simply-dovecot > /dev/null 2>&1 || true

docker run -d --name mail-simply-mailpit --network mail-simply-test --network-alias mailpit \
    -p 127.0.0.1:31080:8025 axllent/mailpit:latest > /dev/null

docker run -d --name mail-simply-dovecot --network mail-simply-test \
    -p 127.0.0.1:31143:143 -p 127.0.0.1:31587:587 \
    --tmpfs /srv/vmail:uid=1000,gid=1000,mode=0700 \
    -v "$dir/dovecot.conf:/etc/dovecot/dovecot.conf:ro" \
    -v "$dir/users:/etc/dovecot/users:ro" \
    -v "$dir/master-users:/etc/dovecot/master-users:ro" \
    "$image" > /dev/null

for _ in $(seq 1 30); do
    if php -r '$s = @stream_socket_client("tcp://127.0.0.1:31143", $e, $m, 1); exit($s && str_starts_with((string) fgets($s), "* OK") ? 0 : 1);'; then
        echo "Test mail server is up. Run the tests with:"
        echo "  MAIL_SIMPLY_TEST_IMAP=127.0.0.1:31143 MAIL_SIMPLY_TEST_SMTP=127.0.0.1:31587 MAIL_SIMPLY_TEST_MAILPIT=http://127.0.0.1:31080 php tests/run.php"
        exit 0
    fi
    sleep 1
done

docker logs mail-simply-dovecot >&2
echo "Dovecot did not come up." >&2
exit 1
