<?php

namespace SirZeruks\LibrenmsRouterosBgp\Tests;

use PHPUnit\Framework\TestCase;
use SirZeruks\LibrenmsRouterosBgp\BgpSession;
use SirZeruks\LibrenmsRouterosBgp\Transport\ApiTransport;
use SirZeruks\LibrenmsRouterosBgp\Transport\RestTransport;
use SirZeruks\LibrenmsRouterosBgp\Transport\SshTransport;
use SirZeruks\LibrenmsRouterosBgp\Transport\TransportException;

class ParsingTest extends TestCase
{
    public function testAddressNormalisation(): void
    {
        $this->assertSame('10.0.0.1', BgpSession::normaliseAddress('10.0.0.1'));
        $this->assertSame('10.0.0.1', BgpSession::normaliseAddress('10.0.0.1:179'));
        $this->assertSame('2001:db8::1', BgpSession::normaliseAddress('[2001:0db8::1]:179'));
        $this->assertSame('2001:db8::1', BgpSession::normaliseAddress('2001:DB8:0::1'));
        $this->assertSame('fe80::1', BgpSession::normaliseAddress('fe80::1%ether1'));
        $this->assertSame('', BgpSession::normaliseAddress('not-an-ip'));
        $this->assertSame('', BgpSession::normaliseAddress(''));
    }

    public function testRestParse(): void
    {
        $body = json_encode([
            ['.id' => '*1', 'name' => 'a', 'remote.address' => '192.0.2.1', 'prefix-count' => '1200', 'established' => 'true'],
            ['.id' => '*2', 'name' => 'b', 'remote.address' => '192.0.2.5', 'established' => 'false'],
            ['.id' => '*3', 'name' => 'c'],
        ]);
        $s = RestTransport::parse($body, 200);
        $this->assertCount(2, $s);
        $this->assertSame(1200, $s[0]->prefixCount);
        $this->assertTrue($s[0]->established);
        $this->assertNull($s[1]->prefixCount);
        $this->assertFalse($s[1]->established);
        $this->assertSame('ipv4', $s[0]->afi());
    }

    public function testRestErrors(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('401');
        RestTransport::parse('{"error":401,"message":"Unauthorized"}', 401);
    }

    public function testRestRejectsNonList(): void
    {
        $this->expectException(TransportException::class);
        RestTransport::parse('{"name":"x"}', 200);
    }

    public function testSshParse(): void
    {
        $out = "\r\nRBGP|peer1|192.0.2.1|1200|true\r\nRBGP|peer2|2001:db8::1||false\r\nnoise line\r\n";
        $s = SshTransport::parse($out);
        $this->assertCount(2, $s);
        $this->assertSame(1200, $s[0]->prefixCount);
        $this->assertSame('ipv6', $s[1]->afi());
        $this->assertNull($s[1]->prefixCount);
    }

    public function testSshParsesAllProperties(): void
    {
        // RBGP|name|remote.address|prefix-count|established|remote.as|local.address|uptime|remote.messages|local.messages|stopped
        $out = "RBGP|v6-peer|2001:db8::2|42|true|64512|2001:db8::1|1w2d03:04:05|900|880|false\n";
        $s = SshTransport::parse($out)[0];
        $this->assertSame('2001:db8::2', $s->remoteAddress);
        $this->assertSame('ipv6', $s->afi());
        $this->assertSame(42, $s->prefixCount);
        $this->assertSame(64512, $s->remoteAs);
        $this->assertSame('2001:db8::1', $s->localAddress);
        $this->assertSame(604800 + 2 * 86400 + 3 * 3600 + 4 * 60 + 5, $s->uptimeSeconds);
        $this->assertSame(900, $s->inMessages);
        $this->assertSame(880, $s->outMessages);
        $this->assertFalse($s->stopped);
    }

    public function testDurationFormats(): void
    {
        $this->assertSame(3 * 86400 + 14 * 3600 + 20 * 60 + 55, BgpSession::duration('3d14h20m55s640ms'));
        $this->assertSame(604800 + 2 * 86400 + 3 * 3600 + 4 * 60 + 5, BgpSession::duration('1w2d3h4m5s'));
        $this->assertSame(12 * 60 + 34, BgpSession::duration('00:12:34'));
        $this->assertSame(86400 + 3600, BgpSession::duration('1d01:00:00.5'));
        $this->assertSame(0, BgpSession::duration(''));
        $this->assertSame(0, BgpSession::duration('garbage'));
    }

    public function testSshRejectsV6Router(): void
    {
        $this->expectException(TransportException::class);
        SshTransport::parse('bad command name session (line 1 column 29)');
    }

    public function testSshCommandIsFixed(): void
    {
        $cmd = SshTransport::command();
        $this->assertStringStartsWith(':foreach s in=[/routing/bgp/session find]', $cmd);
        // every RouterOS menu command in the script is a read on /routing/bgp/session (`:set` only assigns a script variable)
        preg_match_all('#\[(/[a-z/-]+) (\w+)#', $cmd, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $menu, $verb]) {
            $this->assertSame('/routing/bgp/session', $menu);
            $this->assertContains($verb, ['find', 'get']);
        }
        foreach (['add', 'remove', 'disable', 'enable', 'unset', 'export', 'import', 'reset', 'reboot', 'shutdown'] as $verb) {
            $this->assertDoesNotMatchRegularExpression('#/[a-z/-]+ ' . $verb . '\b#', $cmd, $verb);
        }
    }

    public function testApiLengthEncodingRoundTrip(): void
    {
        foreach ([0, 1, 0x7F, 0x80, 0x3FFF, 0x4000, 0x1FFFFF, 0x200000, 0xFFFFFFF, 0x10000000] as $len) {
            $enc = ApiTransport::encodeLength($len);
            $b = ord($enc[0]);
            $dec = match (true) {
                $b < 0x80 => $b,
                $b < 0xC0 => (($b & 0x3F) << 8) | ord($enc[1]),
                $b < 0xE0 => (($b & 0x1F) << 16) | unpack('n', substr($enc, 1, 2))[1],
                $b < 0xF0 => (($b & 0x0F) << 24) | (ord($enc[1]) << 16) | unpack('n', substr($enc, 2, 2))[1],
                default => unpack('N', substr($enc, 1, 4))[1],
            };
            $this->assertSame($len, $dec, "length $len");
        }
    }

    public function testApiSentenceParse(): void
    {
        $r = ApiTransport::parseSentence(['!re', '=name=a=b', '=remote.address=10.0.0.1', '.tag=1']);
        $this->assertSame('!re', $r['type']);
        $this->assertSame('a=b', $r['attrs']['name']);
        $this->assertSame('10.0.0.1', $r['attrs']['remote.address']);
    }
}
