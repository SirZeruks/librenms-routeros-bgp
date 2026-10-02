<?php

namespace SirZeruks\LibrenmsRouterosBgp\Tests;

use PHPUnit\Framework\TestCase;
use SirZeruks\LibrenmsRouterosBgp\Transport\ApiTransport;
use SirZeruks\LibrenmsRouterosBgp\Transport\ConnectionConfig;
use SirZeruks\LibrenmsRouterosBgp\Transport\TransportException;

/**
 * End-to-end against a fake RouterOS API server speaking the real wire protocol.
 */
class ApiTransportTest extends TestCase
{
    private function withServer(string $user, string $pass, callable $fn): void
    {
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/fake_routeros_api.php', (string) $port, 'librenms', 's3cret'], [], $pipes);
        usleep(300000);
        try {
            $fn(new ConnectionConfig('127.0.0.1', ConnectionConfig::API, $port, $user, $pass, '', false, 5));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testFetchSessions(): void
    {
        $this->withServer('librenms', 's3cret', function (ConnectionConfig $c): void {
            $s = (new ApiTransport($c))->fetchSessions();
            $this->assertCount(3, $s);
            $this->assertSame('192.0.2.1', $s[0]->remoteAddress);
            $this->assertSame(1200, $s[0]->prefixCount);
            $this->assertSame('2001:db8::1', $s[1]->remoteAddress);
            $this->assertSame(200, strlen($s[2]->name));   // 2-byte length word
            $this->assertNull($s[2]->prefixCount);
            $this->assertFalse($s[2]->established);
        });
    }

    public function testBadLogin(): void
    {
        $this->withServer('librenms', 'wrong', function (ConnectionConfig $c): void {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('invalid user name or password');
            (new ApiTransport($c))->fetchSessions();
        });
    }

    public function testConnectionRefused(): void
    {
        $c = new ConnectionConfig('127.0.0.1', ConnectionConfig::API, 1, 'u', 'p', '', false, 2);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('API connection');
        (new ApiTransport($c))->fetchSessions();
    }
}
