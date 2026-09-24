# Mail Simply

A small, self-hosted webmail for IMAP and SMTP, built to sit next to a hosting control panel the way Roundcube does: the panel signs the customer in with a one-time link, and the session opens their mailbox through Dovecot's master user, with no mailbox password anywhere. A password form is there too, for everyone else.

- Folders with unread counts, nested folders, special folders found by their SPECIAL-USE flags or their usual names (in English and Hungarian), create, rename and delete, drag messages onto a folder to move them
- Message list newest first, page by page, with previews, attachment, answered and forwarded markers; search sender, recipients and subject (and, on request, the text), filter by unread or flagged
- Select several messages at once: mark read or unread, flag, move, delete; empty the trash and the junk folder
- Read HTML mail safely: cleaned on the server, shown in a sandboxed frame that runs no script, with images from the web blocked until the reader asks for them (for one message, or always for a sender or their whole domain). Plain-text mail is shown with its links, quotes and format=flowed
- Attachments listed with image thumbnails, downloaded in full as they stream from the server; download a whole message as `.eml`
- Write in rich text or plain text: reply, reply all, forward with the original's attachments, paste images straight into the message, attach files by picking or dropping them. Recipients complete from the addresses you have written to
- Drafts save themselves every 30 seconds and when the editor is closed; reopening one brings back its recipients, text, images and attachments
- Sent mail is kept in the Sent folder, the message answered is marked answered
- Your name, a signature, rich or plain text by default, and the language: English and Hungarian
- Quota bar, unread count in the tab title, new mail picked up every 45 seconds
- Works on a phone
- No build step, no runtime dependencies: plain PHP 8.4+ and a vendored copy of Alpine.js. IMAP, SMTP and MIME are spoken by the app itself

## Requirements

- PHP 8.4 or newer with the `dom`, `iconv`, `mbstring`, `openssl`, `session` and `sodium` extensions. `intl` formats dates in quoted replies; `fileinfo` detects attachment types; `curl` is used for sign-on when present
- An IMAP server and a submission server. Mail Simply is written against Dovecot and uses SORT, LIST-STATUS, SPECIAL-USE, MOVE, UIDPLUS, QUOTA, PREVIEW, LITERAL+ and SASL-IR when the server offers them, with plainer fallbacks when it does not
- A web server that serves only the `public/` directory

## Install

Download the zip from the [latest release](https://github.com/wpsimply/mail-simply/releases/latest). It holds only the files a server needs, inside a single `mail-simply/` directory:

```sh
version=0.1.0
curl -fsSLO "https://github.com/wpsimply/mail-simply/releases/download/v${version}/mail-simply-${version}.zip"
curl -fsSLO "https://github.com/wpsimply/mail-simply/releases/download/v${version}/mail-simply-${version}.zip.sha256"
sha256sum -c "mail-simply-${version}.zip.sha256"
unzip -q "mail-simply-${version}.zip" -d /var/www
cd /var/www/mail-simply && cp .env.example .env
```

### With Composer

```sh
composer create-project wpsimply/mail-simply /var/www/mail-simply
```

This installs the runtime files and leaves out the tests, examples and CI files, like the release zip. It also copies `.env.example` to `.env` and sets the storage directories to `0700`.

To pin Mail Simply in another project instead, `composer require wpsimply/mail-simply`, point the web server at `vendor/wpsimply/mail-simply/public`, and set `MAIL_SIMPLY_HOME` in the real environment (the PHP-FPM pool's `env[...]`, or `fastcgi_param` in nginx) to a directory outside `vendor/` that holds `.env`, `config.php` and `storage/`. `MAIL_SIMPLY_HOME` can't be set in `.env`, because it decides where `.env` is read from.

### Permissions

Make the storage directories writable by the PHP-FPM pool user and nobody else:

```sh
chown -R www-data:www-data storage
chmod 700 storage/sessions storage/users storage/uploads storage/throttle
```

Point the web server at `public/`, and let it run `index.php`, `api.php`, `frame.php`, `attachment.php`, `upload.php`, `login.php`, `sso.php` and `logout.php`. Attachments are uploaded one file per request, so allow request bodies at least as large as `MAIL_SIMPLY_MAX_ATTACHMENTS` (25 MB by default) in the web server and in PHP's `upload_max_filesize` and `post_max_size`. There are examples for nginx and PHP-FPM in [`examples/`](examples).

## Configure

Configuration comes from three layers, each overriding the one before:

1. the defaults in `src/Config.php`
2. `MAIL_SIMPLY_*` environment variables, read from `.env` and the real environment (the real environment wins)
3. `config.php`, if present (copy `config.example.php`)

The settings that matter:

| Variable | Purpose |
| --- | --- |
| `MAIL_SIMPLY_IMAP_HOST`, `_PORT`, `_ENCRYPTION` | The IMAP server. Encryption is `ssl`, `starttls` or `none`. |
| `MAIL_SIMPLY_SMTP_HOST`, `_PORT`, `_ENCRYPTION` | The submission server mail is sent through. |
| `MAIL_SIMPLY_IMAP_VERIFY`, `MAIL_SIMPLY_IMAP_CA` (and `SMTP_`) | Certificate checks. Keep verification on; give a CA file for a private certificate. |
| `MAIL_SIMPLY_SSO_URL`, `MAIL_SIMPLY_SSO_SECRET` | Where sign-on tokens are redeemed, and the secret that proves it is us asking. |
| `MAIL_SIMPLY_SSO_ISSUE_URL` | The panel page `sso.php?start` sends the browser to, for a token bound to it. See below. |
| `MAIL_SIMPLY_SSO_REQUIRE_BINDING` | `true` refuses tokens that are not bound to a browser. Default `false`. |
| `MAIL_SIMPLY_MASTER_USER`, `MAIL_SIMPLY_MASTER_PASSWORD` | The Dovecot master user sign-on sessions authenticate as. |
| `MAIL_SIMPLY_LOGIN_FORM` | The password form. `false` where the panel is the only way in. |
| `MAIL_SIMPLY_LOGIN_MAX_ATTEMPTS`, `_PER_CLIENT` | Failed sign-ins allowed in 15 minutes, per address (default 5) and per client address (default 30), before the form refuses. `0` turns a limit off. |
| `MAIL_SIMPLY_SESSION_SECURE` | Keep `true` in production; `false` only for local HTTP. |
| `MAIL_SIMPLY_MAX_ATTACHMENTS` | Bytes of attachments one message may carry. Default 25 MB. |
| `MAIL_SIMPLY_LANGUAGE` | `en` or `hu`, when neither the user nor the browser asks for one. |

See [`.env.example`](.env.example) for all of them.

## Signing users in

### From a control panel

The panel holds no mailbox password — it stores them hashed — so it vouches for the mailbox instead. Each token is bound to the browser that asked for it, so a link can only be used by the person it was minted for:

1. The panel's "Open webmail" button sends the browser to `https://webmail.example.com/sso.php?start`, with any parameters the panel needs to know which mailbox is meant (`&mailbox=42`). Mail Simply gives the browser a random proof in a cookie and sends it on to `MAIL_SIMPLY_SSO_ISSUE_URL` with those parameters and `binding=<hash of the proof>`.
2. The panel checks that the user is signed in to the panel and that the mailbox is theirs, mints a random token, 32–64 bytes hex-encoded, remembers which mailbox and which `binding` it is for, and redirects the browser to `https://webmail.example.com/sso.php?token=<token>`. Never show the link or let it be copied.
3. Mail Simply redeems it, server to server:

   ```
   POST {MAIL_SIMPLY_SSO_URL}/{token}
   Authorization: Bearer {MAIL_SIMPLY_SSO_SECRET}
   Accept: application/json
   ```

   The panel answers `200` with the mailbox, and spends the token:

   ```json
   { "address": "info@example.com", "name": "Example Ltd", "binding": "<the binding it was sent>" }
   ```

   The token only signs in the browser holding the proof `binding` was made from. Anything else — unknown, expired, spent, a wrong secret — is a refusal. Answering `404` for all of them keeps a caller without the secret from telling a real token from an invented one. `name` is optional; it is the name mail is sent under until the user sets their own.
4. The session opens the mailbox as the Dovecot master user: over SASL PLAIN it authenticates (authcid) on the mailbox's behalf (authzid), for IMAP and for SMTP submission alike. No mailbox password is involved, and the session's username is the plain address, so the From header is the customer's own.

A token for another mailbox replaces whatever session the browser had open. Reloading a spent sign-on URL lands back in the session it opened.

Without the binding, anyone given a link can open it, and whoever minted it can sign someone else into their own mailbox — and read what that person then writes. A token redeemed without `binding` is still accepted, so a panel can move to bound tokens at its own pace; once it binds every token, set `MAIL_SIMPLY_SSO_REQUIRE_BINDING=true` to refuse any that are not.

Dovecot needs a master passdb that continues to the mailbox's own passdb, so a mailbox that may not sign in stays shut even to the master user:

```
passdb master {
  driver = passwd-file
  passwd_file_path = /etc/dovecot/master-users
  master = yes
  result_success = continue
}
```

The master user's credentials are used for sign-on sessions only. The password form always checks the password it is given against the mailbox.

### With a password

The sign-in page asks for the address and password, and checks them against the IMAP server. `index.php?user=info@example.com` fills the address in. The password is kept for the session, encrypted, under a key that lives only in a cookie of its own.

After 5 failed sign-ins for one address, or 30 from one client address, within 15 minutes, the form refuses without asking the IMAP server until the oldest failures age out. The client address is `REMOTE_ADDR`: behind a proxy, let the web server set the real one (nginx's `real_ip` module), or every visitor shares the proxy's count.

## Security notes

- **Message HTML is cleaned on the server** with PHP's HTML5 parser, and written out again from an allowlist of elements and attributes: no script, no event handlers, no forms, frames, SVG or MathML, no `javascript:` or `data:` links, and CSS without anything that runs or loads. Writing a fresh copy, rather than deleting what looks bad, keeps parser differentials out.
- **It is then shown in a sandboxed frame** (`frame.php`) without `allow-scripts`, under a Content-Security-Policy of its own: `default-src 'none'`, images only from this origin and inline, and no forms. Links open in a new tab without a referrer.
- **Remote content is off by default.** Images and styles from the web are left out until the reader allows them — for the message, or always for a sender or domain — and the frame's policy blocks them even if the cleaning were to miss one.
- **Attachments are downloads.** Only images are shown in place; every attachment response carries `Content-Security-Policy: sandbox`, so an HTML file opened in a tab runs nothing and reaches nothing from this origin.
- Sign-on tokens are redeemed server to server with a shared secret, never trusted from the URL alone, and only sign in the browser they were minted for.
- Over HTTPS the session cookies carry the `__Host-` prefix and no domain, so a site on a sibling subdomain (a customer's, on a shared server) cannot plant a session in the user's browser.
- A message quoted into the editor, which is part of the page rather than a frame, stays inside the editor: it cannot be laid over the interface. Pages are sent with `Referrer-Policy: no-referrer`, so the token URL does not leak to other sites.
- Every change needs the session's CSRF token. Sessions end after `MAIL_SIMPLY_SESSION_IDLE_TIMEOUT` seconds of inactivity, or after `MAIL_SIMPLY_SESSION_LIFETIME` seconds regardless.
- A password session's password is encrypted in the session file under a key held only in a cookie; the file alone gives nothing away.
- Uploads belong to the session that made them, in a directory named after a random key in the session, and are deleted once sent, or after a day.
- Settings and collected addresses are kept in `storage/users`, one file per mailbox, named after a hash of the address.
- Failed logins are limited per address and per client address by the app (see [With a password](#with-a-password)), and slowed down by Dovecot's own authentication penalty.

## Keyboard shortcuts

| Key | |
| --- | --- |
| `c` | Write a new message |
| `r` / `a` / `f` | Reply, reply all, forward |
| `j` / `k` | Next / previous message |
| `s` | Flag or unflag |
| `#` or `Delete` | Delete |
| `u` or `Esc` | Back to the list |
| `/` | Search |

## Development

```sh
tests/dovecot/start.sh                  # a throwaway Dovecot and Mailpit in Docker
cp .env.example .env                    # then point it at 127.0.0.1:31143 / :31587, encryption none,
                                        # MAIL_SIMPLY_SESSION_SECURE=false
php -S 127.0.0.1:8080 -t public
```

Sign in as `alice@example.test` / `alice-test-password`. To try sign-on without a panel, set `MAIL_SIMPLY_MASTER_USER=webmail@example.test` and `MAIL_SIMPLY_MASTER_PASSWORD=master-test-password`. Mail sent from it ends up in Mailpit, at http://127.0.0.1:31080.

```sh
php tests/run.php                       # unit tests only
MAIL_SIMPLY_TEST_IMAP=127.0.0.1:31143 MAIL_SIMPLY_TEST_SMTP=127.0.0.1:31587 \
MAIL_SIMPLY_TEST_MAILPIT=http://127.0.0.1:31080 php tests/run.php
tests/dovecot/stop.sh
```

The mail server tests empty the test mailboxes as they go, so never point them at a server holding mail you care about.

## Releasing

Set the new version in `VERSION`, commit, then push a tag:

```sh
git tag v0.1.0 && git push origin v0.1.0
```

The release workflow runs the test suite, then builds `mail-simply-<version>.zip` with `build/release.sh` and attaches it, with its SHA-256 checksum, to a GitHub release. Tags with a suffix, such as `v0.2.0-rc.1`, are published as prereleases.

## Roadmap

- Filters and out-of-office replies over ManageSieve
- An address book beyond remembered recipients
- Conversation view

## License

MIT. Alpine.js is bundled under its own MIT license, see `public/assets/vendor/alpine.LICENSE.md`.
