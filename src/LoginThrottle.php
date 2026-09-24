<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * Slows password guessing at the login form down to a crawl.
 *
 * Failed sign-ins are counted per address and per client over a window of a
 * quarter of an hour. Past either limit the form refuses at once, without
 * asking the mail server, until the oldest failures age out. Signing in
 * clears the address's count. The counts are small files named after a hash
 * of what they count, so the directory reveals no address.
 *
 * The client is REMOTE_ADDR. Behind a proxy that is the proxy, and every
 * visitor shares one count: let the web server set the real address (nginx's
 * real_ip module), or turn the per-client limit off.
 */
final class LoginThrottle
{
    private const int WINDOW = 900;

    /**
     * @param  int  $perAddress  failures one address may have in the window; 0 for no limit
     * @param  int  $perClient  failures one client may have in the window; 0 for no limit
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $perAddress,
        private readonly int $perClient,
    ) {}

    public function blocked(string $address, string $client): bool
    {
        return ($this->perAddress > 0 && count($this->failures('address', strtolower($address))) >= $this->perAddress)
            || ($this->perClient > 0 && $client !== '' && count($this->failures('client', $client)) >= $this->perClient);
    }

    public function failed(string $address, string $client): void
    {
        $this->record('address', strtolower($address));

        if ($client !== '') {
            $this->record('client', $client);
        }

        // Now and then, rather than on every request: the directory can grow
        // large while someone is guessing.
        if (random_int(1, 50) === 1) {
            $this->prune();
        }
    }

    public function succeeded(string $address): void
    {
        @unlink($this->file('address', strtolower($address)));
    }

    /**
     * Remove counts whose failures have all aged out.
     */
    public function prune(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (time() - (int) @filemtime($file) > self::WINDOW) {
                @unlink($file);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function failures(string $kind, string $subject): array
    {
        return self::recent((string) @file_get_contents($this->file($kind, $subject)));
    }

    private function record(string $kind, string $subject): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            error_log('mail-simply: the login throttle directory cannot be created: '.$this->directory);

            return;
        }

        $handle = @fopen($this->file($kind, $subject), 'c+');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            $failures = [...self::recent((string) stream_get_contents($handle)), time()];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", array_slice($failures, -100)));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The failure times in a count that are still inside the window.
     *
     * @return list<int>
     */
    private static function recent(string $contents): array
    {
        $cutoff = time() - self::WINDOW;

        return array_values(array_filter(
            array_map(intval(...), preg_split('/\s+/', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn (int $time): bool => $time > $cutoff,
        ));
    }

    private function file(string $kind, string $subject): string
    {
        return $this->directory.'/'.$kind.'-'.hash('sha256', $subject);
    }
}
