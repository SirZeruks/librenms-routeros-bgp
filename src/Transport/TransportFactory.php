<?php

namespace SirZeruks\LibrenmsRouterosBgp\Transport;

final class TransportFactory
{
    public static function make(ConnectionConfig $config): Transport
    {
        return match ($config->transport) {
            ConnectionConfig::REST_HTTPS, ConnectionConfig::REST_HTTP => new RestTransport($config),
            ConnectionConfig::API, ConnectionConfig::API_SSL => new ApiTransport($config),
            ConnectionConfig::SSH => new SshTransport($config),
            default => throw new TransportException("Unknown transport '{$config->transport}'"),
        };
    }
}
