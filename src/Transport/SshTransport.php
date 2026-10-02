<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use SirZeruks\LibrenmsRouterosBgp\BgpSession;

/**
 * SSH: runs one fixed, read-only RouterOS script that prints one line per session.
 * Nothing user-supplied is ever put into the command.
 */
final class SshTransport implements Transport
{
    public const MARKER = 'RBGP';

    /**
     * One line per session: RBGP|<value of each BgpSession::PROPERTIES item, in order>. Each property is read inside
     * its own on-error guard, so a property a RouterOS version lacks reads as empty instead of breaking the whole read.
     */
    public static function command(): string
    {
        $props = implode(';', array_map(fn (string $p) => '"' . $p . '"', BgpSession::PROPERTIES));

        return ':foreach s in=[/routing/bgp/session find] do={:local o "' . self::MARKER . '";'
            . ':foreach p in={' . $props . '} do={:local v ""; :do {:set v [/routing/bgp/session get $s $p]} on-error={};'
            . ':set o ($o . "|" . [:tostr $v])}; :put $o}';
    }

    public function __construct(private readonly ConnectionConfig $config)
    {
    }

    public function fetchSessions(): array
    {
        $ssh = new SSH2($this->config->host, $this->config->port, $this->config->timeout);
        $ssh->setTimeout($this->config->timeout);

        try {
            $credential = $this->config->password;
            if ($this->config->sshKey !== '') {
                $credential = PublicKeyLoader::load($this->config->sshKey, $this->config->password !== '' ? $this->config->password : false);
            }
            if (! $ssh->login($this->config->username, $credential)) {
                throw new TransportException('SSH login refused: check the username/password/key and that the user group has the ssh policy');
            }
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('SSH connection failed: ' . $e->getMessage());
        }

        $output = (string) $ssh->exec(self::command());
        $ssh->disconnect();

        return self::parse($output);
    }

    /**
     * @return BgpSession[]
     */
    public static function parse(string $output): array
    {
        if (preg_match('/(syntax error|bad command name|no such item|expected end of command)/i', $output, $m)) {
            throw new TransportException("RouterOS rejected the read command ({$m[1]}) - BGP sessions need RouterOS v7");
        }

        $sessions = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, self::MARKER . '|')) {
                continue;
            }
            $parts = explode('|', $line);
            array_shift($parts);   // the marker
            if (count($parts) < 4) {
                continue;
            }
            $row = [];
            foreach (BgpSession::PROPERTIES as $i => $prop) {
                $row[$prop] = $parts[$i] ?? '';
            }
            $session = BgpSession::fromRouterOs($row);
            if ($session) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }
}
