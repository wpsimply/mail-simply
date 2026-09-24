<?php

declare(strict_types=1);

namespace MailSimply;

use RuntimeException;

/**
 * A line-oriented TCP connection, as IMAP and SMTP both speak, with implicit
 * TLS or an upgrade to it part way (STARTTLS).
 *
 * Certificates are verified unless the configuration says otherwise. A peer
 * on the same host or a private network may present a certificate for its
 * public name; that is what the verify switch and a CA file are for, rather
 * than anything that quietly skips the check.
 */
final class Socket
{
    /** @var resource */
    private $stream;

    /**
     * @param  array{host: string, port: int, encryption: string, verify: bool, ca_file: ?string, timeout: int}  $options
     */
    public function __construct(private readonly array $options)
    {
        $context = stream_context_create(['ssl' => $this->tlsOptions()]);
        $scheme = $options['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $address = sprintf('%s://%s:%d', $scheme, $options['host'], $options['port']);

        $stream = @stream_socket_client($address, $errno, $error, $options['timeout'], STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            // $error is routinely empty for a timeout or a DNS failure, so the
            // number rides along or the log line says nothing at all.
            throw new RuntimeException(sprintf('Could not connect to %s:%d (%d) %s', $options['host'], $options['port'], $errno, $error));
        }

        stream_set_timeout($stream, max(1, $options['timeout']));
        $this->stream = $stream;
    }

    /**
     * Upgrade the connection to TLS, after the peer agreed to STARTTLS.
     */
    public function startTls(): void
    {
        stream_context_set_options($this->stream, ['ssl' => $this->tlsOptions()]);

        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
            // OpenSSL's own words say whether it was the certificate (a name
            // that does not match the host, an unknown authority) or the
            // handshake itself, which is the whole difference in the fix.
            $reason = trim(str_replace('stream_socket_enable_crypto(): ', '', (string) (error_get_last()['message'] ?? '')));

            throw new RuntimeException(sprintf('TLS negotiation with %s:%d failed. %s', $this->options['host'], $this->options['port'], $reason));
        }
    }

    /**
     * Read one line, CRLF included.
     */
    public function readLine(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            $this->failRead();
        }

        return $line;
    }

    /**
     * Read exactly the given number of bytes.
     */
    public function read(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->stream, min(65536, $length - strlen($data)));

            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                $this->failRead();
            }

            if ($chunk === '' && stream_get_meta_data($this->stream)['timed_out']) {
                $this->failRead();
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Read the given number of bytes, handing them over in chunks rather than
     * holding them all, for payloads too big to keep in memory.
     *
     * @param  callable(string): void  $sink
     */
    public function stream(int $length, callable $sink): void
    {
        while ($length > 0) {
            $chunk = $this->read(min(65536, $length));
            $length -= strlen($chunk);
            $sink($chunk);
        }
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($this->stream, $data);

            if ($written === false || $written === 0) {
                throw new RuntimeException(sprintf('The connection to %s was lost while writing.', $this->options['host']));
            }

            $data = substr($data, $written);
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function failRead(): never
    {
        $timedOut = is_resource($this->stream) && stream_get_meta_data($this->stream)['timed_out'];

        throw new RuntimeException(sprintf(
            '%s %s.',
            $this->options['host'],
            $timedOut ? 'stopped answering' : 'closed the connection',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function tlsOptions(): array
    {
        $verify = $this->options['verify'];
        $options = [
            'peer_name' => $this->options['host'],
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => ! $verify,
            'SNI_enabled' => true,
        ];

        if (($this->options['ca_file'] ?? '') !== '' && $this->options['ca_file'] !== null) {
            $options['cafile'] = $this->options['ca_file'];
        }

        return $options;
    }
}
