<?php

declare(strict_types=1);

namespace MailSimply;

use RuntimeException;

/**
 * Redeems a sign-on token against the control panel.
 *
 * The panel holds no mailbox password -- it stores them hashed -- so it
 * cannot sign anybody in by handing one over. It mints a short-lived,
 * single-use token instead and sends the browser to sso.php?token=<token>.
 * This redeems the token server to server, authenticated by a shared secret,
 * and gets back the address it was minted for:
 *
 *   POST {sso.url}/{token}
 *   Authorization: Bearer {sso.secret}
 *
 *   200 {"address": "info@example.com", "name": "Example Ltd"}
 *
 * Anything else means no. The mailbox is then opened as Dovecot's master user
 * on the address's behalf; no mailbox password is involved anywhere.
 */
final class SignOn
{
    public function __construct(private readonly Config $config) {}

    public static function isToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32,128}$/', $token) === 1;
    }

    /**
     * @return array{address: string, name: string}|null
     */
    public function redeem(string $token): ?array
    {
        if (! $this->config->singleSignOn() || ! self::isToken($token)) {
            return null;
        }

        $url = rtrim($this->config->string('sso.url'), '/').'/'.$token;
        [$status, $body] = $this->post($url, $this->config->string('sso.secret'), max(1, $this->config->int('sso.timeout')));

        if ($status !== 200) {
            // Never the token itself: logs are not as private as it has to be.
            error_log(sprintf('mail-simply: redeeming a sign-on token failed with status %d.', $status));

            return null;
        }

        $payload = json_decode($body, true);
        $address = is_array($payload) && is_string($payload['address'] ?? null) ? trim($payload['address']) : '';

        if ($address === '' || ! str_contains($address, '@') || strlen($address) > 254) {
            return null;
        }

        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';

        return ['address' => $address, 'name' => mb_substr($name, 0, 120)];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function post(string $url, string $secret, int $timeout): array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new RuntimeException('The sign-on URL must be an http(s) URL.');
        }

        $headers = ['Accept: application/json', 'Authorization: Bearer '.$secret, 'Content-Length: 0'];

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            return [is_string($body) ? $status : 0, is_string($body) ? $body : ''];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;

        foreach (http_get_last_response_headers() ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, is_string($body) ? $body : ''];
    }
}
