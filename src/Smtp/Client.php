<?php

declare(strict_types=1);

namespace MailSimply\Smtp;

use MailSimply\Socket;
use RuntimeException;

/**
 * Submits one message over SMTP (RFC 5321, with RFC 6409 submission in mind):
 * EHLO, STARTTLS where configured, AUTH PLAIN, then the envelope and the data.
 */
final class Client
{
    /** @var array<string, string> */
    private array $extensions = [];

    /**
     * The conversation so far, credentials left out, for the log when it
     * goes wrong.
     *
     * @var list<string>
     */
    private array $transcript = [];

    /**
     * The code of the last reply, so that what answers a 334 challenge (a
     * user name or password) never reaches the transcript.
     */
    private int $lastCode = 0;

    private function __construct(private readonly Socket $socket, private readonly string $hostname)
    {
        $this->expect([220]);
        $this->ehlo();
    }

    /**
     * @param  array{host: string, port: int, encryption: string, verify: bool, ca_file: ?string, timeout: int}  $options
     */
    public static function connect(array $options, string $hostname): self
    {
        $client = new self(new Socket($options), $hostname);

        if ($options['encryption'] === 'starttls') {
            if (! isset($client->extensions['STARTTLS'])) {
                throw new RuntimeException('The SMTP server does not offer STARTTLS.');
            }

            $client->command('STARTTLS', [220]);
            $client->socket->startTls();
            $client->ehlo();
        }

        return $client;
    }

    /**
     * Authenticate. With an authorisation identity the user and password are
     * a master user's, submitting on that mailbox's behalf.
     */
    public function authenticate(string $user, string $password, ?string $authorize = null): void
    {
        $mechanisms = explode(' ', strtoupper($this->extensions['AUTH'] ?? ''));

        if (in_array('PLAIN', $mechanisms, true) || $authorize !== null) {
            $this->command('AUTH PLAIN '.base64_encode(($authorize ?? '')."\0".$user."\0".$password), [235]);

            return;
        }

        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334]);
            $this->command(base64_encode($password), [235]);

            return;
        }

        throw new RuntimeException('The SMTP server offers no authentication this client speaks.');
    }

    /**
     * Send a message. Every recipient has to be accepted, or nothing is sent:
     * a message that silently skipped a Cc is worse than one that failed.
     *
     * @param  list<string>  $recipients
     */
    public function send(string $from, array $recipients, string $message): void
    {
        $size = isset($this->extensions['SIZE']) ? ' SIZE='.strlen($message) : '';
        $body = isset($this->extensions['8BITMIME']) ? ' BODY=8BITMIME' : '';

        $this->command('MAIL FROM:<'.$from.'>'.$size.$body, [250]);

        foreach ($recipients as $recipient) {
            try {
                $this->command('RCPT TO:<'.$recipient.'>', [250, 251]);
            } catch (Rejected $e) {
                $this->reset();

                throw new Rejected($e->reply, $recipient);
            }
        }

        $this->command('DATA', [354]);

        // Dot-stuffing: a line that starts with a dot gets another one, so
        // the line holding the lone dot below is the only one that ends it.
        $data = (string) preg_replace('/^\./m', '..', (string) preg_replace('/\r\n?|\n/', "\r\n", $message));
        $this->transcript[] = sprintf('C: <message, %d bytes>', strlen($data));
        $this->socket->write($data.(str_ends_with($data, "\r\n") ? '' : "\r\n").".\r\n");
        $this->expect([250]);
    }

    /**
     * The conversation so far, with every credential redacted.
     *
     * @return list<string>
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    public function quit(): void
    {
        try {
            $this->socket->write("QUIT\r\n");
            $this->socket->readLine();
        } catch (\Throwable) {
            // Delivered or not, it was decided before this.
        } finally {
            $this->socket->close();
        }
    }

    private function reset(): void
    {
        try {
            $this->command('RSET', [250]);
        } catch (\Throwable) {
            // Only tidying up after a failure already reported.
        }
    }

    private function ehlo(): void
    {
        [, $lines] = $this->command('EHLO '.$this->hostname, [250]);
        $this->extensions = [];

        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim($line), 2);
            $this->extensions[strtoupper($parts[0])] = $parts[1] ?? '';
        }
    }

    /**
     * @param  list<int>  $expected
     * @return array{0: int, 1: list<string>}
     */
    private function command(string $command, array $expected): array
    {
        $this->transcript[] = 'C: '.match (true) {
            str_starts_with($command, 'AUTH ') => implode(' ', array_slice(explode(' ', $command), 0, 2)).' [redacted]',
            $this->lastCode === 334 => '[redacted]',
            default => $command,
        };

        $this->socket->write($command."\r\n");

        return $this->expect($expected);
    }

    /**
     * @param  list<int>  $expected
     * @return array{0: int, 1: list<string>}
     */
    private function expect(array $expected): array
    {
        $lines = [];

        do {
            $line = $this->socket->readLine();
            $code = (int) substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4), "\r\n");
        } while (($line[3] ?? ' ') === '-');

        $this->lastCode = $code;
        $this->transcript[] = 'S: '.$code.' '.implode(' | ', $lines);

        if (! in_array($code, $expected, true)) {
            throw new Rejected($code.' '.implode(' ', $lines));
        }

        return [$code, $lines];
    }
}
