<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

/**
 * Effective connection settings for one device (global defaults merged with the device override).
 */
final class ConnectionConfig
{
    public const REST_HTTPS = 'rest_https';
    public const REST_HTTP = 'rest_http';
    public const API = 'api';
    public const API_SSL = 'api_ssl';
    public const SSH = 'ssh';

    /** Transport => [label, default port] */
    public const TRANSPORTS = [
        self::REST_HTTPS => ['REST API over HTTPS (www-ssl)', 443],
        self::REST_HTTP => ['REST API over HTTP (www) - unencrypted', 80],
        self::API_SSL => ['RouterOS API-SSL (api-ssl)', 8729],
        self::API => ['RouterOS API (api) - unencrypted', 8728],
        self::SSH => ['SSH', 22],
    ];

    public function __construct(
        public readonly string $host,
        public readonly string $transport,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $sshKey,
        public readonly bool $verifyTls,
        public readonly int $timeout,
    ) {
    }

    public static function defaultPort(string $transport): int
    {
        return self::TRANSPORTS[$transport][1] ?? 443;
    }

    public static function validTransport(string $transport): bool
    {
        return isset(self::TRANSPORTS[$transport]);
    }

    /** Host formatted for a URL or socket (IPv6 literals in brackets). */
    public function urlHost(): string
    {
        return filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $this->host . ']' : $this->host;
    }

    public function describe(): string
    {
        return sprintf('%s %s:%d as %s', $this->transport, $this->host, $this->port, $this->username === '' ? '(no user)' : $this->username);
    }
}
