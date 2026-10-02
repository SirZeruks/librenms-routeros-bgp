<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

use SirZeruks\LibrenmsRouterosBgp\BgpSession;

/**
 * RouterOS binary API (api / api-ssl). Minimal read-only client:
 * post-6.43 login, one print command, then quit.
 */
final class ApiTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private readonly ConnectionConfig $config)
    {
    }

    public function fetchSessions(): array
    {
        $this->connect();
        try {
            $this->login();
            $replies = $this->talk(['/routing/bgp/session/print', '=.proplist=' . RestTransport::PROPLIST]);
        } finally {
            $this->close();
        }

        $sessions = [];
        foreach ($replies as $reply) {
            if ($reply['type'] === '!re' && ($s = BgpSession::fromRouterOs($reply['attrs']))) {
                $sessions[] = $s;
            }
        }

        return $sessions;
    }

    private function connect(): void
    {
        $ssl = $this->config->transport === ConnectionConfig::API_SSL;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->config->verifyTls,
            'verify_peer_name' => $this->config->verifyTls,
            'allow_self_signed' => ! $this->config->verifyTls,
            // RouterOS api-ssl without a certificate only offers anonymous DH ciphers
            'ciphers' => $this->config->verifyTls ? 'DEFAULT' : 'DEFAULT:ADH:@SECLEVEL=0',
        ]]);
        $target = sprintf('%s://%s:%d', $ssl ? 'tls' : 'tcp', $this->config->urlHost(), $this->config->port);

        $socket = @stream_socket_client($target, $errno, $errstr, $this->config->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            if ($ssl && $errno === 0) {
                // PHP reports a failed TLS handshake with no error text
                throw new TransportException("API-SSL connection to {$this->config->host}:{$this->config->port} failed in the TLS handshake: "
                    . ($this->config->verifyTls
                        ? 'the router\'s certificate is not trusted (self-signed?). Turn off "Verify TLS certificate", or give the router a certificate this server trusts.'
                        : 'does api-ssl have a certificate on the router? (/ip service print)'));
            }
            throw new TransportException("API connection to {$this->config->host}:{$this->config->port} failed: $errstr ($errno)");
        }
        stream_set_timeout($socket, $this->config->timeout);
        $this->socket = $socket;
    }

    private function login(): void
    {
        try {
            $replies = $this->talk(['/login', '=name=' . $this->config->username, '=password=' . $this->config->password]);
        } catch (TransportException $e) {
            throw new TransportException('API login refused: ' . preg_replace('/^API error: /', '', $e->getMessage())
                . '. Check the username/password and that the user group has the api policy.');
        }
        $last = end($replies);
        if (! $last || $last['type'] !== '!done') {
            throw new TransportException('API login refused: check the username/password and that the user group has the api policy');
        }
    }

    /**
     * Send one sentence and read replies until !done.
     *
     * @param  string[]  $words
     * @return array<int, array{type: string, attrs: array<string, string>}>
     */
    private function talk(array $words): array
    {
        $this->write(self::encodeSentence($words));

        $replies = [];
        while (true) {
            $sentence = $this->readSentence();
            $reply = self::parseSentence($sentence);
            if ($reply['type'] === '!trap' || $reply['type'] === '!fatal') {
                throw new TransportException('API error: ' . ($reply['attrs']['message'] ?? $reply['type']));
            }
            $replies[] = $reply;
            if ($reply['type'] === '!done') {
                return $replies;
            }
        }
    }

    /**
     * @param  string[]  $words
     */
    public static function encodeSentence(array $words): string
    {
        $out = '';
        foreach ($words as $word) {
            $out .= self::encodeLength(strlen($word)) . $word;
        }

        return $out . "\x00";
    }

    public static function encodeLength(int $len): string
    {
        return match (true) {
            $len < 0x80 => chr($len),
            $len < 0x4000 => pack('n', $len | 0x8000),
            $len < 0x200000 => substr(pack('N', $len | 0xC00000), 1),
            $len < 0x10000000 => pack('N', $len | 0xE0000000),
            default => "\xF0" . pack('N', $len),
        };
    }

    /**
     * @param  string[]  $sentence
     * @return array{type: string, attrs: array<string, string>}
     */
    public static function parseSentence(array $sentence): array
    {
        $type = array_shift($sentence) ?? '';
        $attrs = [];
        foreach ($sentence as $word) {
            if (str_starts_with($word, '=')) {
                $pos = strpos($word, '=', 1);
                if ($pos !== false) {
                    $attrs[substr($word, 1, $pos - 1)] = substr($word, $pos + 1);
                }
            }
        }

        return ['type' => $type, 'attrs' => $attrs];
    }

    /**
     * @return string[]
     */
    private function readSentence(): array
    {
        $words = [];
        while (($len = $this->readLength()) > 0) {
            $words[] = $this->read($len);
        }

        return $words;
    }

    private function readLength(): int
    {
        $b = ord($this->read(1));
        if ($b < 0x80) {
            return $b;
        }
        if ($b < 0xC0) {
            return (($b & 0x3F) << 8) | ord($this->read(1));
        }
        if ($b < 0xE0) {
            return (($b & 0x1F) << 16) | unpack('n', $this->read(2))[1];
        }
        if ($b < 0xF0) {
            return (($b & 0x0F) << 24) | (ord($this->read(1)) << 16) | unpack('n', $this->read(2))[1];
        }

        return unpack('N', $this->read(4))[1];
    }

    private function read(int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($this->socket, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new TransportException($meta['timed_out'] ? 'API read timed out' : 'API connection closed by router');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) !== strlen($data)) {
            throw new TransportException('API write failed');
        }
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, self::encodeSentence(['/quit']));
            fclose($this->socket);
        }
        $this->socket = null;
    }
}
