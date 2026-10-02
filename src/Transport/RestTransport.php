<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

use SirZeruks\LibrenmsRouterosBgp\BgpSession;

/**
 * RouterOS v7 REST API: GET /rest/routing/bgp/session (needs user group policy read,api,rest-api).
 */
final class RestTransport implements Transport
{
    public const PROPLIST = 'name,remote.address,prefix-count,established,remote.as,local.address,uptime,remote.messages,local.messages,stopped';

    public function __construct(private readonly ConnectionConfig $config)
    {
    }

    public function fetchSessions(): array
    {
        $scheme = $this->config->transport === ConnectionConfig::REST_HTTP ? 'http' : 'https';
        $url = sprintf('%s://%s:%d/rest/routing/bgp/session?.proplist=%s',
            $scheme, $this->config->urlHost(), $this->config->port, rawurlencode(self::PROPLIST));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->config->username . ':' . $this->config->password,
            CURLOPT_CONNECTTIMEOUT => $this->config->timeout,
            CURLOPT_TIMEOUT => $this->config->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new TransportException("REST connection failed: $error");
        }

        return self::parse((string) $body, $status);
    }

    /**
     * @return BgpSession[]
     */
    public static function parse(string $body, int $status): array
    {
        $data = json_decode($body, true);

        if ($status === 401) {
            throw new TransportException('REST login refused (401): check the username/password and that the user group has the rest-api policy');
        }
        if ($status !== 200) {
            $detail = is_array($data) ? trim(($data['message'] ?? '') . ' ' . ($data['detail'] ?? '')) : '';
            throw new TransportException("REST request failed (HTTP $status)" . ($detail !== '' ? ": $detail" : ''));
        }
        if (! is_array($data) || ! array_is_list($data)) {
            throw new TransportException('REST reply was not a JSON list - is this RouterOS v7 with BGP?');
        }

        $sessions = [];
        foreach ($data as $row) {
            if (is_array($row) && ($s = BgpSession::fromRouterOs($row))) {
                $sessions[] = $s;
            }
        }

        return $sessions;
    }
}
