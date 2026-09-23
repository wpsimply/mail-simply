<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * Attachments uploaded for a message that has not been sent yet.
 *
 * Each session has a directory of its own, named after a random key held in
 * the session, so an upload is only ever reachable from the session that made
 * it. Uploads are deleted once their message is sent, and anything nobody
 * came back for is deleted after a day.
 */
final class Uploads
{
    private const int MAX_AGE = 86400;

    public function __construct(private readonly string $root, private readonly string $key) {}

    /**
     * Keep an uploaded file.
     *
     * @return array{id: string, name: string, type: string, size: int}
     */
    public function store(string $path, string $name, string $type): array
    {
        $directory = $this->directory();
        $id = bin2hex(random_bytes(12));

        if (! @move_uploaded_file($path, $directory.'/'.$id) && ! @rename($path, $directory.'/'.$id)) {
            throw new UserError('The file could not be stored.', 500);
        }

        @chmod($directory.'/'.$id, 0600);

        $meta = ['name' => self::safeName($name), 'type' => $type, 'size' => (int) filesize($directory.'/'.$id)];
        file_put_contents($directory.'/'.$id.'.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        return ['id' => $id, ...$meta];
    }

    /**
     * @return array{name: string, type: string, size: int, path: string}|null
     */
    public function find(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id) !== 1) {
            return null;
        }

        $path = $this->directory().'/'.$id;
        $meta = is_file($path.'.json') ? json_decode((string) file_get_contents($path.'.json'), true) : null;

        if (! is_file($path) || ! is_array($meta)) {
            return null;
        }

        return ['name' => (string) $meta['name'], 'type' => (string) $meta['type'], 'size' => (int) $meta['size'], 'path' => $path];
    }

    /**
     * The bytes this session has uploaded and not yet sent.
     */
    public function total(): int
    {
        $total = 0;

        foreach (glob($this->directory().'/*.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            $total += is_array($meta) ? (int) ($meta['size'] ?? 0) : 0;
        }

        return $total;
    }

    public function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
            @unlink($this->directory().'/'.$id);
            @unlink($this->directory().'/'.$id.'.json');
        }
    }

    /**
     * Remove every upload older than a day, from every session.
     */
    public function prune(): void
    {
        $cutoff = time() - self::MAX_AGE;

        foreach (glob($this->root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $files = glob($directory.'/*') ?: [];

            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }

            @rmdir($directory);
        }
    }

    private function directory(): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $this->key) !== 1) {
            throw new UserError('Your session has ended. Sign in again.', 401);
        }

        $directory = $this->root.'/'.$this->key;

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new UserError('The file could not be stored.', 500);
        }

        return $directory;
    }

    /**
     * A filename with no path in it and nothing a header or a filesystem
     * would trip over.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f"]/u', '', $name));

        return $name === '' || $name === '.' || $name === '..' ? 'attachment' : mb_substr($name, 0, 200);
    }
}
