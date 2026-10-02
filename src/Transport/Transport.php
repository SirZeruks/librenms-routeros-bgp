<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

use SirZeruks\LibrenmsRouterosBgp\BgpSession;

interface Transport
{
    /**
     * Read /routing/bgp/session from the router. Read-only.
     *
     * @return BgpSession[]
     *
     * @throws TransportException
     */
    public function fetchSessions(): array;
}
