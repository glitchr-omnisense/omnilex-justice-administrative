# omnilex/justice-administrative

The decisions of the Conseil d'État, the administrative courts of appeal and the
administrative tribunals for [omnisense/omnilex](https://github.com/glitchr-omnisense/omnilex), from
the open data of the administrative justice
(<https://opendata.justice-administrative.fr>): a search by text, court, kind of decision and
dates, one decision with its full text, what was added since a date. No key.

```php
$administratif = (new JusticeAdministrativeSourceFactory($http))->create();

$results = $administratif->search(new Query(text: 'permis de construire', jurisdictions: ['CE'], types: ['Décision'], from: '2026-09-01'));
$decision = $administratif->decision($results->items[0]->id);
$decision->court->formation;          // 8ème chambre jugeant seule
$decision->publication;               // Inédit au recueil Lebon
$decision->content;                   // the full text, pseudonymised

$administratif->decision('ECLI:FR:CECHS:2026:505010.20261002');              // a decision of the Conseil d'État by its ECLI
$administratif->recent(new DateTimeImmutable('-7 days'), new Query(jurisdictions: ['CAA69']));
```

```yaml
omnilex:
    sources:
        administratif: { factory: justice-administrative }
```

## What the service is, and is not

The site documents **monthly archives to download** (`/DCE/`, `/DCA/`, `/DTA/`: zipped XML,
one file per decision). It publishes **no API contract**. What this package asks is the JSON
interface the site's own search engine calls (`/recherche/api/...`), read from the engine's
pages and verified call by call on 2026-10-04. It works without a key and gives what the engine
gives; it may change without notice. When a route disappears, the package says so
(`ProviderException`: "the search engine no longer answers this route") instead of answering
with nothing.

The archives are not read by this package: for a full copy of the base, download them.

## Pseudonymised decisions

The decisions are pseudonymised by the Conseil d'État before they are published
(`Decision::$pseudonymised` is `true`). This package gives them as they are and never tries to
put a name back on a person; an application built on it must not either.

The [conditions of use](https://opendata.justice-administrative.fr/cgu.html) (version of 18
January 2022, Licence Ouverte 2.0) ask the re-user, among other things:

- not to alter a decision nor distort its meaning, and to keep with it its number, the court
  and formation that gave it, and its date (an excerpt must be shown as one, with a way to the
  whole decision);
- to name the source - the open data base of the Conseil d'État - and the date the decision was
  loaded from it (`Decision::$url`, `Decision::$updatedOn`);
- to keep the data up to date;
- never to use the identity of the judges and clerks to assess, analyse, compare or predict
  their professional practice (code de justice administrative, art. L. 10: a criminal offence),
and invite the re-user to report to the Conseil d'État any element that would still identify a
person in a decision.

## What you need to obtain

Nothing.

| Option | |
|---|---|
| `recent` | the date `recent()` reads: `lastModified` (the day a decision joined the base, default) or `Date_Lecture` (the day it was read in open court) |
| `throttle` | seconds between two calls (1) |
| `base_uri` | to override the interface's address |

Verified against the real service on 2026-10-04 (see [docs/](docs/index.md)).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Conseil d'État. It reaches their service on behalf of whoever runs it, and it does
not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the conditions of use
above, for obtaining whatever they require (an account, a key, a licence or subscription, an
attribution or a citation, a rate limit to respect), and for any use made of what it returns. The
authors publish it free of charge, make no commercial use of it, and accept no liability for how it
is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
