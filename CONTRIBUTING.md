# Contributing

Bug reports and pull requests are welcome on GitHub.

When reporting a problem, include:

- LibreNMS version (`./lnms --version`) and RouterOS version
- the connection method (REST/API/API-SSL/SSH)
- the output of `sudo -u librenms ./lnms routeros-bgp:poll --test <device>` (remove anything private)

Development:

```bash
composer install
composer test
```

The tests run without LibreNMS. They cover the RouterOS parsers and the API client, using a fake RouterOS API server.
