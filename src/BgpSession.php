<?php

namespace SirZeruks\LibrenmsRouterosBgp;

/**
 * One row of RouterOS v7 /routing/bgp/session, reduced to what the plugin needs.
 */
final class BgpSession
{
    /** The session properties the plugin reads, in this order (REST/API .proplist and the SSH script). */
    public const PROPERTIES = ['name', 'remote.address', 'prefix-count', 'established', 'remote.as', 'local.address',
        'uptime', 'remote.messages', 'local.messages', 'stopped'];

    public function __construct(
        public readonly string $name,
        public readonly string $remoteAddress,
        public readonly ?int $prefixCount,
        public readonly bool $established,
        public readonly ?int $remoteAs = null,
        public readonly string $localAddress = '',
        public readonly int $uptimeSeconds = 0,
        public readonly ?int $inMessages = null,
        public readonly ?int $outMessages = null,
        public readonly bool $stopped = false,
    ) {
    }

    /**
     * Build from a RouterOS property map (REST JSON object, API !re sentence or SSH line).
     * All RouterOS values arrive as strings.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRouterOs(array $row): ?self
    {
        $address = self::normaliseAddress((string) ($row['remote.address'] ?? ''));
        if ($address === '') {
            return null;
        }

        return new self(
            name: (string) ($row['name'] ?? ''),
            remoteAddress: $address,
            prefixCount: self::int($row['prefix-count'] ?? null),
            established: self::bool($row['established'] ?? false),
            remoteAs: self::int($row['remote.as'] ?? null),
            localAddress: self::normaliseAddress((string) ($row['local.address'] ?? '')),
            uptimeSeconds: self::duration((string) ($row['uptime'] ?? '')),
            inMessages: self::int($row['remote.messages'] ?? null),
            outMessages: self::int($row['local.messages'] ?? null),
            stopped: self::bool($row['stopped'] ?? false),
        );
    }

    /**
     * RouterOS can report the address with a port ("10.0.0.1:179", "[2001:db8::1]:179")
     * or an interface scope ("fe80::1%ether1"). LibreNMS stores the bare IP.
     */
    public static function normaliseAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $address, $m)) {
            $address = $m[1];
        } elseif (substr_count($address, ':') === 1) {
            $address = explode(':', $address)[0];   // IPv4 with :port
        }
        $address = explode('%', $address)[0];
        $address = explode('/', $address)[0];

        $packed = @inet_pton($address);

        return $packed === false ? '' : (string) inet_ntop($packed);
    }

    /**
     * RouterOS durations, in either form it uses:
     *   "3d14h20m55s640ms" / "1w2d3h4m5s" (REST, API, print) and "1w2d03:04:05" / "00:12:34.5" (script :tostr).
     */
    public static function duration(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $seconds = 0;
        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/', $value, $m)) {
            return (int) $m[1] * 604800 + (int) $m[2] * 86400 + (int) $m[3] * 3600 + (int) $m[4] * 60 + (int) $m[5];
        }
        if (preg_match_all('/(\d+)(ms|w|d|h|m|s)/', $value, $parts, PREG_SET_ORDER)) {
            $unit = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1, 'ms' => 0];
            foreach ($parts as $p) {
                $seconds += (int) $p[1] * $unit[$p[2]];
            }
        }

        return $seconds;
    }

    public function afi(): string
    {
        return str_contains($this->remoteAddress, ':') ? 'ipv6' : 'ipv4';
    }

    private static function int(mixed $value): ?int
    {
        return ($value === null || $value === '' || ! is_numeric($value)) ? null : (int) $value;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['true', 'yes', '1'], true);
    }
}
