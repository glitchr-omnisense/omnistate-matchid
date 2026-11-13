# omnistate/matchid

France's deaths since 1970 - INSEE's file of deceased persons - searched through
[matchID](https://deces.matchid.io), for [omnisense/omnistate](https://github.com/glitchr-omnisense/omnistate).

```php
$registry = new MatchId($httpClient);                       // a token as 2nd argument raises the quota
$records = $registry->search(new CivilQuery(familyName: 'Chirac', givenName: 'Jacques', born: Period::year(1932)));
$records[0]->date;                      // 2019-09-26
$records[0]->place;                     // Paris 6e Arrondissement (INSEE 75106)
$records[0]->principal()->birthDate;    // 1932-11-29
$records[0]->number;                    // the act's number: 129
$registry->find($records[0]->identifier);
```

No framework needed: the package requires `omnisense/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

| | |
|---|---|
| Countries | FR (people who died in France, or French abroad, whose death INSEE recorded) |
| Kinds | deaths only |
| Years | deaths since 1970 |
| Picture of the act | no: the file is an index; the act is at the town hall of the place of death |
| Access | free, no key |

## Install

```sh
composer require omnistate/matchid
```

It brings the family's core, `omnisense/omnistate`, and `omnisense/contracts` with it. No account and no key for a first call; a matchID token raises the quota ([`policy.json`](policy.json)).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by matchID or the INSEE. It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with matchID's terms
([deces.matchid.io/about](https://deces.matchid.io/about)), for obtaining whatever they require (an
account, a key, a licence or subscription, an attribution or a citation, a rate limit to respect),
and for any use made of what it returns. The authors publish it free of charge, make no commercial
use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

Documentation: [docs/](docs/index.md). License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
