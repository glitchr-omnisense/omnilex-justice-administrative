# omnilex/justice-administrative

The decisions of the Conseil d'État, the administrative courts of appeal and the administrative
tribunals for [omnisense/omnilex](https://github.com/glitchr-omnisense/omnilex), from the open
data of the administrative justice (<https://opendata.justice-administrative.fr/>). The site
documents monthly archives of XML files to download; this source calls the interface the site's
own search engine (`/recherche`) calls - JSON under
`https://opendata.justice-administrative.fr/recherche/api` (`JusticeAdministrativeSource::BASE_URI`),
no published contract, routes seen and checked by real calls on 2026-10-04. There is no sandbox.

What was read first, on 2026-10-04:

| | |
|---|---|
| <https://opendata.justice-administrative.fr/> | the site: decisions of the Conseil d'État (since 30 September 2021), of the courts of appeal (31 March 2022), of the tribunals (30 June 2022), added daily |
| <https://opendata.justice-administrative.fr/aide.html> | the help page: monthly archives of XML files, and their tags (`Code_Juridiction`, `Numero_Dossier`, `Date_Lecture`, `Numero_ECLI`, `Type_Decision`, `Code_Publication`, `Formation_Jugement`, `Texte_Integral`...) |
| `/DCE/`, `/DCA/`, `/DTA/` | the archives: `/DCE/2021/06/CE_202106.zip`... |
| <https://opendata.justice-administrative.fr/recherche> | the search engine, whose pages call `/recherche/api/...` and get JSON |
| <https://opendata.justice-administrative.fr/cgu.html> | the conditions of use: Licence Ouverte 2.0 and their own rules |

## Installation

```sh
composer require omnilex/justice-administrative
```

Requires PHP 8.2, `omnisense/omnilex` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `OmnilexBundle`, the factory is registered as soon as the package is installed.

## Credentials

None: the open data is open. Calls carry the `User-Agent` of the `user_agent` option.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `recent` | `lastModified` or `Date_Lecture` | `lastModified` (`JusticeAdministrativeSource::ADDED`) | no | the date `recent()` reads: the day a decision joined the base, or the day it was read in open court (`DECIDED`); another value is an `InvalidConfigException` (which `Registry::usable()` leaves out) |
| `throttle` | float | `1.0` | no | seconds between two calls: the interface is the site's own engine, not a service offered to programs |
| `base_uri` | string | `https://opendata.justice-administrative.fr/recherche/api` | no | |
| `user_agent` | string | `omnilex/1.x (+https://github.com/glitchr-omnisense/omnilex)` | no | |

Without an HTTP client, the factory makes one with a 60-second timeout: some routes take a few
seconds (20 s were seen for a range of dates over every court).

## Capabilities

`capabilities()`: holds decisions; reads ECLI (the Conseil d'État's); criteria `text`, `number`,
`kind`, `jurisdictions`, `from`, `to`, `types`; jurisdictions `FR`; 200 per call; pseudonymised.

| Operation | Supported |
|---|---|
| `search()` | yes, one call, no paging |
| `decision()` | yes: a search result's `id`, a file name, a Conseil d'État ECLI |
| `recent()` | yes |
| `citations()` | no: the decisions carry no links to texts nor to other decisions |
| `text()`, `article()` | no |

## Behaviour

**Routes.** Every route answers `{"decisions": {"body": {"hits": {"total": {"value": n}, "hits":
[...]}}}}`, newest decision first; each hit has an `_id` (`DCE_505010_20261002.xml_CE`: the file
and the court) and a `_source` with the tags above plus `lastModified`. A case number known to
several courts comes wrapped in one message holding the decisions: they are unwrapped. The last
segment of a search route is the number of results wanted (`limit`, 1 to 200; the engine's own
default is 200). Which route depends on the criteria set:

| Criteria | Route under `/recherche/api/` |
|---|---|
| none, or court and/or kind | `model_noTyped/openData/<court or null>/<kind or null>/<n>` |
| text | `Simple_Search/openData/<text>/<n>` |
| text, court | `model_search_juri/openData/<court>/<text>/<n>` |
| text, kind | `Check_Search/openData/<kind>/<text>/<n>` |
| text, kind, court | `model_search_check_juri/openData/<kind>/<court>/<text>/<n>` |
| dates | `model_Dates/openData/<field>/<from>/<to or false>/<n>` |
| dates, court | `model_date_juri/openData/<field>/<court>/<from>/<to or false>/<n>` |
| dates, kind (and court) | `Date_Checkbox/openData/<field>/<kind>/<from>/<to or false>/<court or null>/<n>` |
| text, dates | `model_searchANDdates/openData/<field>/<text>/<from>/<to or false>/<n>` |
| text, dates, court | `model_search_date_juri/openData/<field>/<text>/<court>/<from>/<to or false>/<n>` |
| text, dates, kind | `model_all/openData/<field>/<text>/<kind>/<from>/<to or false>/<n>` |
| text, dates, kind, court | `model_ABCD/openData/<field>/<text>/<kind>/<court>/<from>/<to or false>/<n>` |
| one decision | `elastic/decisions/<court>/<file>` |

`<field>` is `Date_Lecture` for `search()` and the `recent` option for `recent()`; `<to>` is
`false` for "from that day on". A value is one path segment (`/` replaced by a space,
URL-encoded). A route that answers 404 is a `ProviderException`: the interface changed.

**Query.**

| | |
|---|---|
| `text` | every word: the engine reads bare words as "any of them", so they are sent joined by `AND`; `"..."`: the exact expression |
| `number` | a case number (`505010`, `24LY03335`), searched as the text; not along with a text |
| `jurisdictions` | one court: `CE`, `CAA` + department (`CAA69`: Lyon, `CAA13`: Marseille), `TA` + department (`TA75`: Paris); an order of courts as a whole (`CAA`, `TA`) answers nothing |
| `types` | one of `Décision` (or `decision`), `Ordonnance` |
| `from`, `to` | the day the decision was read; `to` needs `from` |
| `limit` | 1 to 200; no paging: `Results::$total` says how many match, `Results::$next` is always `null` |
| `sort` | not read: newest first |

`title`, `subjects`, `at`, a `cursor`, several courts or kinds, a `kind` other than `DECISION`,
`to` without `from` are refused (`NotSupportedException`).

**A reference.** `id` the hit's `_id`, `title` (`<court>, <d/m/Y>, <case number>`), the ECLI when
the court publishes one (the others send the word `undefined`, skipped), the date read, `type`
(`Type_Decision`), `number`, the court, a summary from the engine's highlights, `url`.

**A decision.** `decision()` takes the `id` of a search result, the file name alone
(`DCE_505010_20261002`, with or without `.xml`; for a court of appeal or a tribunal the court is
found by one more search, by the file's name), or an ECLI of the Conseil d'État
(`ECLI:FR:CECHS:2026:505010.20261002`: it carries the case number, which is searched, and the
decision is recognised among the results by its ECLI). Another ECLI or identifier is a
`NotSupportedException`. The `Decision` holds the court (`Conseil d'État` with its litigation
section as `chamber`, or the court's name), its `formation`, the date read, the case number, the
kind (`type`), the classification as `publication` (`Code_Publication`: A "Publié au recueil
Lebon", B "Mentionné aux tables du recueil Lebon", C "Inédit au recueil Lebon"; other codes as
they are), `updatedOn` (the day it joined the base), the full text as `content` (the engine's
`$$$` separators become line breaks), `pseudonymised: true`, `language: fr`, and the page that
shows it as `url` (`https://opendata.justice-administrative.fr/recherche/shareFile/<court>/<file>`).
`summary`, `solution`, `subjects`, `citations` are empty.

**Pseudonymisation.** The decisions are pseudonymised by the Conseil d'État before they are
published: they are given as they are, and nothing here tries to put a name back on a person.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;
use Omnilex\Model\Query;
use Symfony\Component\HttpClient\HttpClient;

$administratif = (new JusticeAdministrativeSourceFactory(HttpClient::create(['timeout' => 60])))->create();

$new = $administratif->recent(new DateTimeImmutable('2026-10-20'), new Query(jurisdictions: ['CE'], limit: 3));
echo $new->total, " decisions of the Conseil d'État added since 2026-10-20; the first 3:\n";
foreach ($new as $reference) {
    echo '  ', $reference->id, ' - ', $reference->title, ' - ', $reference->identifiers->value(Omnilex\Model\Scheme::ECLI) ?? 'no ECLI', "\n";
}
$decision = $administratif->decision($new->items[0]->id);
echo "\n", $decision->title, "\n", $decision->court->name, ' / ', $decision->court->chamber, ' / ', $decision->court->formation, "\n", $decision->type, ', ', $decision->publication, ', added ', $decision->updatedOn?->format('Y-m-d'), "\n", $decision->url, "\n";
echo mb_substr((string) $decision->content, 0, 160), "...\n";
```

```
198 decisions of the Conseil d'État added since 2026-10-20; the first 3:
  DCE_516166_20261023.xml_CE - Conseil d'État, 23/10/2026, 516166 - ECLI:FR:CECHS:2026:516166.20261023
  DCE_516364_20261023.xml_CE - Conseil d'État, 23/10/2026, 516364 - ECLI:FR:CECHS:2026:516364.20261023
  DCE_514371_20261022.xml_CE - Conseil d'État, 22/10/2026, 514371 - ECLI:FR:CECHS:2026:514371.20261022

Conseil d'État, 23/10/2026, 516166
Conseil d'État / Section du Contentieux / 7ème chambre jugeant seule
Décision, Inédit au recueil Lebon, added 2026-10-24
https://opendata.justice-administrative.fr/recherche/shareFile/CE/DCE_516166_20261023
Vu la procédure suivante :
Le syndicat CGT d’administration centrale Equipement-Environnement, le syndicat CFDT des territoires, de l’environnement et de la mer...
```

(called live on 2026-10-26 at 11:56 UTC, from the core's harness image)

In Symfony:

```yaml
omnilex:
    sources:
        administratif: { factory: justice-administrative }
```

From the core's harness: `docker compose run --rm omnilex recent administratif 2026-10-01 --jurisdiction CE`.

## Errors

| Case | Result |
|---|---|
| an unknown decision | `null` |
| an identifier it does not read, an ECLI of another court than the Conseil d'État | `NotSupportedException` |
| a criterion refused (above) | `NotSupportedException` |
| a route answering 404, an answer without decisions | `ProviderException` |
| a 5xx, a timeout | `UnavailableException`, never an empty list |
| a 429 | `RateLimitedException` |

## Verified, and not

Tested offline: `Tests/JusticeAdministrativeSourceTest.php` (11 tests), on `MockHttpClient` with
answers recorded on 2026-10-04 (the full texts cut to their first lines): a search by text, court,
kind and dates; several words all wanted; each set of criteria on its route; the criteria refused;
what one court added since a date; a decision of the Conseil d'État, by its ECLI, and one of the
administrative court of appeal of Lyon by its file; an unknown decision; down never an empty list.

Called live: every route of the table on 2026-10-04; the example above on 2026-10-26. No limit is
published. The interface has no published contract: a change of the site may break it, and shows
as a `ProviderException`. The monthly archives are not read: download them for a full copy.

## Responsibility

An independent, unofficial client, not affiliated with the Conseil d'État. Whoever runs it
complies with the open data's conditions of use
([cgu.html](https://opendata.justice-administrative.fr/cgu.html)) and never re-identifies a
person: see the README's Responsibility section.
