<?php
// Minimal fake RouterOS API server for ApiTransport tests: php fake_routeros_api.php <port> <user> <pass>
[$_, $port, $user, $pass] = $argv;
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (! $srv) { fwrite(STDERR, "$errstr\n"); exit(1); }
$c = stream_socket_accept($srv, 10);
$readLen = function () use ($c) {
    $b = ord(fread($c, 1));
    if ($b < 0x80) return $b;
    if ($b < 0xC0) return (($b & 0x3F) << 8) | ord(fread($c, 1));
    if ($b < 0xE0) return (($b & 0x1F) << 16) | unpack('n', fread($c, 2))[1];
    if ($b < 0xF0) return (($b & 0x0F) << 24) | (ord(fread($c, 1)) << 16) | unpack('n', fread($c, 2))[1];
    return unpack('N', fread($c, 4))[1];
};
$enc = function (int $l) {
    return match (true) { $l < 0x80 => chr($l), $l < 0x4000 => pack('n', $l | 0x8000), default => substr(pack('N', $l | 0xC00000), 1) };
};
$send = function (array $words) use ($c, $enc) {
    $o = ''; foreach ($words as $w) { $o .= $enc(strlen($w)) . $w; } fwrite($c, $o . "\0");
};
while (! feof($c)) {
    $words = [];
    while (($l = $readLen()) > 0) { $s = ''; while (strlen($s) < $l) { $s .= fread($c, $l - strlen($s)); } $words[] = $s; }
    if (! $words) break;
    if ($words[0] === '/login') {
        if (in_array("=name=$user", $words, true) && in_array("=password=$pass", $words, true)) { $send(['!done']); }
        else { $send(['!trap', '=message=invalid user name or password (6)']); $send(['!done']); }
    } elseif ($words[0] === '/routing/bgp/session/print') {
        $send(['!re', '=.id=*1', '=name=peer1-1', '=remote.address=192.0.2.1', '=prefix-count=1200', '=established=true']);
        $send(['!re', '=.id=*2', '=name=peer6-1', '=remote.address=2001:db8::1', '=prefix-count=42', '=established=true']);
        $send(['!re', '=.id=*3', '=name=' . str_repeat('x', 200), '=remote.address=10.0.0.9', '=established=false']);
        $send(['!done']);
    } elseif ($words[0] === '/quit') {
        $send(['!fatal', '=message=session terminated on request']); break;
    }
}
fclose($c);
