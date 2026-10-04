# omnilex/justice-administrative

## Installation

```sh
composer require omnilex/justice-administrative
```

```php
use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;

$administratif = (new JusticeAdministrativeSourceFactory($httpClient))->create();
```

## What was checked first

On 2026-10-04, by real calls:

| | |
|---|---|
| <https://opendata.justice-administrative.fr/> | the site: decisions of the Conseil d'État (since 30 September 2021), of the courts of appeal (31 March 2022), of the tribunals (30 June 2022), added daily |
| <https://opendata.justice-administrative.fr/aide.html> | the help page: the decisions are published as monthly archives of XML files, and their tags (`Code_Juridiction`, `Numero_Dossier`, `Date_Lecture`, `Numero_ECLI`, `Type_Decision`, `Code_Publication`, `Formation_Jugement`, `Texte_Integral`...) |
| `/DCE/`, `/DCA/`, `/DTA/` | the archives: `/DCE/2021/06/CE_202106.zip`... |
| <https://opendata.justice-administrative.fr/recherche> | a search engine, whose pages call `/recherche/api/...` and get JSON |
| <https://opendata.justice-administrative.fr/cgu.html> | the conditions of use: Licence Ouverte 2.0 and their own rules |

So: no documented API, archives to download, and a search interface that answers queries. This
package is written on that interface, for what it was seen to do.

## Calls

Every route answers `{"decisions": {"body": {"hits": {"total": {"value": n}, "hits": [...]}}}}`,
newest decision first; each hit has an `_id` (`DCE_505010_20261002.xml_CE`: the file and the
court) and a `_source` with the tags above, plus `lastModified`. The last segment of a search
route is the number of results wanted (the engine's own default is 200).

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

`<field>` is `Date_Lecture` (the day the decision was read) for `search()`, and `lastModified`
(the day it joined the base) for `recent()` unless configured otherwise. `<to>` is `false` for
"from that day on".

### Query

| | |
|---|---|
| `text` | every word (the engine reads bare words as "any of them": they are sent joined by `AND`); `"..."` for the exact expression |
| `number` | a case number (`505010`, `24LY03335`), searched as the text; not along with a text |
| `jurisdictions` | one court: `CE`, `CAA` + department (`CAA69`: Lyon, `CAA13`: Marseille), `TA` + department (`TA75`: Paris) |
| `types` | one of `Décision`, `Ordonnance` |
| `from`, `to` | the day the decision was read; `to` needs `from` |
| `limit` | 200 at most; no paging: `Results::$total` says how many decisions match, `Results::$next` is always null |

`title`, `subjects`, `at`, a cursor, several courts or kinds at once are refused.

### Reading a decision

`decision()` takes the `id` of a search result, the file name alone (`DCE_505010_20261002`, with
or without `.xml`; for a court of appeal or a tribunal the court is found by one more call), or
an ECLI of the Conseil d'État (`ECLI:FR:CECHS:2026:505010.20261002`: it carries the case number,
which is searched, then the decision is recognised by its ECLI).

A `Decision` holds the court (`Conseil d'État` with its section as chamber, or the court's
name), its formation, the date read, the case number, the kind, the classification
(`Code_Publication`: A "Publié au recueil Lebon", B "Mentionné aux tables du recueil Lebon", C
"Inédit au recueil Lebon"; other codes as they are), the day it joined the base (`updatedOn`),
the full text (the engine's `$$$` separators become line breaks) and the page that shows it
(`url`: `/recherche/shareFile/<court>/<file>`).

## What is not supported, and why

| | Why |
|---|---|
| Paging | the engine takes a number of results, no offset; narrow the dates or raise the limit (200) |
| `subjects` (matière), `title` | the engine has no such criterion; the decisions carry no subject heading |
| Several courts, or both kinds, in one query | the routes take one of each |
| An order of courts as a whole (`CAA`, `TA`) | answers nothing: a court is named with its department |
| `citations()` | the decisions carry no links to texts nor to other decisions |
| `decision()` by ECLI for the courts of appeal and the tribunals | they publish none here (the field holds "undefined") |
| Texts, articles | decisions only |
| The monthly archives | not read: download them for a full copy |

## Limits

No limit is published. Calls are spaced by `throttle` (1 s by default: the interface is the
site's own search engine, not a service offered to programs); a 5xx or a timeout is an
`UnavailableException`, never an empty list. Some routes take a few seconds (20 s were seen for
a range of dates over every court): give the HTTP client a timeout of a minute.

## Tests

`Tests/JusticeAdministrativeSourceTest.php`, on `MockHttpClient`, with answers recorded on
2026-10-04 (the full texts cut to their first lines): a search by text, court, kind and dates;
by text and court; by case number; what one court added since a date; a decision of the Conseil
d'État and one of the administrative court of appeal of Lyon; an unknown decision. Every route
of the table above was called for real that day. `.samples/live.php` of the workspace runs the
main cases against the real service.
