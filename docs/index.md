# omnistate/matchid

France's deaths since 1970 for [omnisense/omnistate](https://github.com/glitchr-omnisense/omnistate):
INSEE's file of deceased persons (*fichier des personnes décédées*), searched through
[matchID](https://deces.matchid.io), run by the French Ministry of the Interior. One record per
death: the names, the sex, the date and place of birth, the date and place of death, the act's
number. No picture of the act: the file is an index, the act itself is at the town hall.

| Operation | Request |
|---|---|
| `search($query)` | `GET https://deces.matchid.io/deces/api/v1/search?lastName=…&firstName=…&birthDate=…&deathDate=…&deathCity=…&sex=…&size=…&page=…` |
| `find($id)` | `GET https://deces.matchid.io/deces/api/v1/id/{id}` |

The base address is the constant `MatchId::URL` (or the `url` argument); a record's page is
`MatchId::PAGE` + its identifier (`https://deces.matchid.io/id/…`).

## Installation

```sh
composer require omnisense/omnistate omnistate/matchid
```

Requires PHP 8.2, `omnisense/omnistate` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application, `Omnistate\Bridge\Symfony\OmnistateBundle` registers it when it is installed
(tag `omnistate.civil_registry`):

```yaml
# config/packages/omnistate.yaml
omnistate:
    matchid:
        token: '%env(MATCHID_TOKEN)%'   # optional: an account at deces.matchid.io
```

## Credentials

None needed: matchID is open. A token - an account at deces.matchid.io - raises the quota; it is
sent as `Authorization: Bearer <token>`.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `token` | `?string` | `null` | the optional API token (the bundle's `omnistate.matchid.token`) |
| `timeout` | float | `10` | seconds matchID has to answer |
| `url` | string | `https://deces.matchid.io/deces/api/v1` | the API's base address |

## Capabilities

| | |
|---|---|
| Contract | `CivilRegistryInterface` (`name()`: `matchid`) |
| `countries()` | `['FR']` (people who died in France, or French people abroad, whose death INSEE recorded) |
| `kinds()` | `[CivilRecordKind::DEATH]` |
| `period()` | `Period::since(1970)` |
| `hasImages()` | `false` |
| `search()`, `find()` | yes |

`supports()` is false for another country than FR, for kinds without DEATH, for a death looked for
before 1970 (`died`, or `dated` when only deaths are asked, ending before 1970), and without any
name.

## Behaviour

**A search.** From the `CivilQuery`:

| Query | Parameter | |
|---|---|---|
| `familyName`, `givenName` | `lastName`, `firstName` | one of them at least; matchID tolerates spelling variants |
| `born` | `birthDate` | `1932`, `1930-1935` or `29/11/1932` |
| `died` (else `dated`) | `deathDate` | the same three forms |
| `place` | `deathCity` | |
| `sex` | `sex` | `M`, `F` |
| `limit`, `page` | `size`, `page` | `page` sent from 2 on |

A period is sent as a day (`DD/MM/YYYY`) when it is one, else as its years; an open end is 1850
or the current year. Empty parameters are left out. `country` and `kinds` are not sent: they only
decide `supports()`.

**A record.** `find()` takes an identifier of 6 to 40 letters, digits, `_` or `-` (anything else is
`null` without a request) and reads the first person of the answer.

**What comes back.** One `CivilRecord` of kind `DEATH` per person:

- `identifier`: matchID's `id`; `date`: the death's date (`PartialDate`, unknown parts left
  unknown); `place`: the place of death - the town's most precise name (the last of a list: an
  arrondissement), the INSEE code, the postcode, the département's code as `region`, the country
  (ISO alpha-2 from INSEE's alpha-3) and its name, coordinates; `number`: the act's number
  (`certificateId`); `title`: `Décès`;
- one `CivilPerson`: family name, given names, sex, role `Deceased`, date and place of birth, date
  and place of death, age;
- `archive`: `INSEE, fichier des personnes décédées`, code `insee`,
  reference `fichier <source>, ligne <line>`, `https://www.insee.fr/fr/information/4190491`;
- `url`: the record's page at matchID; `raw`: matchID's answer, with its Wikidata link when the
  person has one.

**Caching.** None of its own: the facade's cache keeps its answers.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistate\MatchId\MatchId;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\Period;
use Symfony\Component\HttpClient\HttpClient;

$registry = new MatchId(HttpClient::create(), getenv('MATCHID_TOKEN') ?: null);

$query = new CivilQuery(familyName: 'Chirac', givenName: 'Jacques', born: Period::year(1932));
var_dump($registry->supports($query));
foreach ($registry->search($query) as $record) {
    $person = $record->principal();
    printf("%s  %s (%s), born %s in %s, died %s in %s [INSEE %s, dept %s], age %s, act %s\n",
        $record->identifier, $person->name(), $person->sex->value, $person->birthDate, $person->birthPlace,
        $record->date, $record->place, $record->place?->code, $record->place?->region, $person->age, $record->number);
}

$record = $registry->find('fohpDS17K0Ez');
printf("%s\n%s\n", $record->url, $record->archive);
var_dump($registry->find('nobody-has-this-id'));
```

```
bool(true)
fohpDS17K0Ez  Jacques Rene Chirac (M), born 1932-11-29 in Paris 5e Arrondissement, France, died 2019-09-26 in Paris 6e Arrondissement, France [INSEE 75106, dept 75], age 86, act 129
https://deces.matchid.io/id/fohpDS17K0Ez
INSEE, fichier des personnes décédées, fichier 2019, ligne 505032
NULL
```

(run on 2026-10-25 in omnistate's Docker harness, against matchID, without a token)

Through the facade, with the other civil registers: `$omnistate->civilRecords($query)`, or
`$omnistate->civilRecords($query, 'matchid')` for this one only
([civil registers](https://github.com/glitchr-omnisense/omnistate/blob/1.x/docs/civil.md)).

## Errors

- HTTP 429 or 5xx: `UnavailableException` (`matchID answered 503.`), `retryAfter` from
  `Retry-After`, else 60 s on a 429.
- HTTP 401 or 403: `UnavailableException` (`matchID refused the call (403): the token is missing
  or refused.`).
- No answer, a body that is not JSON: `UnavailableException` (`matchID did not answer: ...`).
- Another HTTP status of 400 or more (400: a date it cannot read): `OmnistateException` with
  matchID's message - but a 404 on `find()`, which is `null`.
- Nobody found: an empty list; an unknown identifier: `null`.

## Verified, and not

Tested offline: `Tests/MatchIdTest.php` (what it covers, a death found by name and year of birth,
the ways a date is asked, the token sent as Bearer, an unknown identifier, down is not nobody, a
question it refuses) and `Tests/MatchIdContractTest.php`, omnistate's conformance suite.

Against the real service on 2026-10-04 (`search` by name and year, by exact date, by range; `/id`;
an unknown identifier; a refused date); the fixtures in `Tests/Fixtures/` are its answers. Run
again on 2026-10-25 (the example above). The token (`Authorization: Bearer`) was not tried: no
account at hand.

## Usage policy

`policy.json` (omnisense/contracts' schema), given by `usagePolicy()`: limits unknown (`rateLimits: null`): the guard counts nothing, a 429 still holds the next calls.
The figures are this manual's, read on 2026-10-25; the service's terms were not read again when the
policy was written (`verifiedAt: null`), so what they say of commercial use, caching and storage is
`unknown`. Through `Omnistate\Omnistate`, a call over a limit is never made and a 429 holds the next
calls for its `retryAfter` (core: docs/policies.md); a site adds its own limits with `policies:`.

## Responsibility

An independent, unofficial client, not affiliated with matchID or the INSEE. Whoever runs it
complies with matchID's terms ([deces.matchid.io/about](https://deces.matchid.io/about)): see the
README's Responsibility section.
