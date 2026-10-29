<?php

namespace Omnilex\JusticeAdministrative\Tests;

use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\ProviderException;
use Omnilex\Exception\UnavailableException;
use Omnilex\JusticeAdministrative\JusticeAdministrativeSource;
use Omnilex\JusticeAdministrative\JusticeAdministrativeSourceFactory;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Identifier;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Scheme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from opendata.justice-administrative.fr/recherche/api on
 * 2026-10-04 (the full texts cut to their first lines): a search by text,
 * court, kind and dates; by text and court; by case number; what was added
 * since a date for one court; one decision of the Conseil d'État and one of
 * the administrative court of appeal of Lyon; an unknown decision.
 */
final class JusticeAdministrativeSourceTest extends TestCase
{
    /** @var list<string> the route of each call, decoded */
    private array $calls = [];

    private function source(array $options = []): JusticeAdministrativeSource
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $route = rawurldecode(substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/recherche/api/')));
            $this->calls[] = $route;
            $fixture = match (true) {
                str_starts_with($route, 'model_ABCD/openData/Date_Lecture/urbanisme/Décision/CE/') => 'search-urbanisme-ce.json',
                str_starts_with($route, 'model_search_juri/openData/TA75/') => 'search-ta75.json',
                str_starts_with($route, 'Simple_Search/openData/505010/') => 'search-number-505010.json',
                str_starts_with($route, 'Simple_Search/openData/DCA_24LY03335_20260930/') => 'search-file-dca.json',
                str_starts_with($route, 'model_date_juri/openData/lastModified/CAA69/') => 'recent-caa69.json',
                'elastic/decisions/CE/DCE_505010_20261002' === $route => 'decision-ce-505010.json',
                'elastic/decisions/CAA69/DCA_24LY03335_20260930' === $route => 'decision-caa69.json',
                default => 'decision-unknown.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => ['Content-Type: application/json; charset=utf-8']]);
        });

        return (new JusticeAdministrativeSourceFactory($http))->create($options + ['throttle' => 0]);
    }

    public function testWhatItDoes(): void
    {
        $source = $this->source();

        self::assertSame('justice-administrative', $source->getName());
        self::assertSame(['search', 'decision', 'recent'], Capabilities::operations($source), 'no texts, no links between documents');
        $capabilities = $source->capabilities();
        self::assertSame([Kind::DECISION], $capabilities->kinds);
        self::assertTrue($capabilities->pseudonymised);
        self::assertFalse($capabilities->filters('subjects'));
        self::assertSame(200, $capabilities->pageSize);
    }

    public function testASearchByTextCourtKindAndDates(): void
    {
        $results = $this->source()->search(new Query(text: 'urbanisme', jurisdictions: ['ce'], types: ['decision'], from: '2026-09-01', to: '2026-10-03', limit: 3));

        self::assertSame(['model_ABCD/openData/Date_Lecture/urbanisme/Décision/CE/2026-09-01/2026-10-03/3'], $this->calls);
        self::assertCount(3, $results);
        self::assertSame(25, $results->total);
        self::assertNull($results->next, 'the engine does not page');
        $first = $results->items[0];
        self::assertSame(Kind::DECISION, $first->kind);
        self::assertSame('DCE_504016_20261002.xml_CE', $first->id);
        self::assertSame('Conseil d\'État, 02/10/2026, 504016', $first->title);
        self::assertSame('ECLI:FR:CECHS:2026:504016.20261002', $first->identifiers->value(Scheme::ECLI));
        self::assertSame('2026-10-02', $first->date->format('Y-m-d'));
        self::assertSame('Décision', $first->type);
        self::assertSame('504016', $first->number);
        self::assertSame(['Conseil d\'État', 'CE', 'FR', 'Section du Contentieux'], [$first->court->name, $first->court->code, $first->court->country, $first->court->chamber]);
        self::assertSame('https://opendata.justice-administrative.fr/recherche/shareFile/CE/DCE_504016_20261002', $first->url);
    }

    public function testSeveralWordsAreAllWanted(): void
    {
        $results = $this->source()->search(new Query(text: 'responsabilité sans faute', jurisdictions: ['TA75'], limit: 3));

        self::assertSame(['model_search_juri/openData/TA75/responsabilité AND sans AND faute/3'], $this->calls, 'the engine reads bare words as "any of them"');
        self::assertSame(2927, $results->total);
        self::assertSame('Tribunal Administratif de Paris', $results->items[0]->court->name);
        self::assertSame('TA75', $results->items[0]->court->code);
        self::assertCount(0, $results->items[0]->identifiers, 'the tribunals publish no ECLI ("undefined" is not one)');

        $this->source()->search(new Query(text: '"responsabilité sans faute"', jurisdictions: ['TA75']));
        self::assertSame('model_search_juri/openData/TA75/"responsabilité sans faute"/25', end($this->calls), 'an expression in quotes goes as it is');
    }

    public function testEachSetOfCriteriaHasItsRoute(): void
    {
        $source = $this->source();
        $cases = [
            [new Query(), 'model_noTyped/openData/null/null/25'],
            [new Query(jurisdictions: ['CAA69'], types: ['Ordonnance'], limit: 500), 'model_noTyped/openData/CAA69/Ordonnance/200'],
            [new Query(text: 'urbanisme'), 'Simple_Search/openData/urbanisme/25'],
            [new Query(number: '24LY03335'), 'Simple_Search/openData/24LY03335/25'],
            [new Query(text: 'urbanisme', types: ['Décision']), 'Check_Search/openData/Décision/urbanisme/25'],
            [new Query(text: 'urbanisme', types: ['ordonnance'], jurisdictions: ['CE']), 'model_search_check_juri/openData/Ordonnance/CE/urbanisme/25'],
            [new Query(from: '2026-09-01'), 'model_Dates/openData/Date_Lecture/2026-09-01/false/25'],
            [new Query(from: '2026-09-01', to: '2026-09-03', jurisdictions: ['CE']), 'model_date_juri/openData/Date_Lecture/CE/2026-09-01/2026-09-03/25'],
            [new Query(from: '2026-09-01', to: '2026-09-03', types: ['decision']), 'Date_Checkbox/openData/Date_Lecture/Décision/2026-09-01/2026-09-03/null/25'],
            [new Query(from: '2026-09-01', types: ['decision'], jurisdictions: ['CE']), 'Date_Checkbox/openData/Date_Lecture/Décision/2026-09-01/false/CE/25'],
            [new Query(text: 'urbanisme', from: '2026-09-01', to: '2026-09-15'), 'model_searchANDdates/openData/Date_Lecture/urbanisme/2026-09-01/2026-09-15/25'],
            [new Query(text: 'urbanisme', from: '2026-09-01', jurisdictions: ['CE']), 'model_search_date_juri/openData/Date_Lecture/urbanisme/CE/2026-09-01/false/25'],
            [new Query(text: 'urbanisme', from: '2026-09-01', to: '2026-09-15', types: ['decision']), 'model_all/openData/Date_Lecture/urbanisme/Décision/2026-09-01/2026-09-15/25'],
            [new Query(text: 'a/b', kind: Kind::DECISION), 'Simple_Search/openData/a b/25'],
        ];
        foreach ($cases as [$query, $route]) {
            $source->search($query);
            self::assertSame($route, end($this->calls));
        }
    }

    public function testWhatItCannotFilterIsRefused(): void
    {
        $source = $this->source();
        $refused = [
            new Query(text: 'urbanisme', subjects: ['Urbanisme']),
            new Query(text: 'urbanisme', at: '2026-01-01'),
            new Query(title: 'urbanisme'),
            new Query(text: 'urbanisme', kind: Kind::TEXT),
            new Query(text: 'urbanisme', cursor: '2'),
            new Query(jurisdictions: ['CE', 'TA75']),
            new Query(types: ['Décision', 'Ordonnance']),
            new Query(types: ['Avis']),
            new Query(text: 'urbanisme', number: '505010'),
            new Query(to: '2026-09-01'),
        ];
        foreach ($refused as $query) {
            try {
                $source->search($query);
                self::fail('A criterion was dropped: '.implode(', ', $query->criteria()));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls, 'refused before any call');
    }

    public function testWhatWasAddedSinceADate(): void
    {
        $results = $this->source()->recent(new \DateTimeImmutable('2026-10-01'), new Query(jurisdictions: ['CAA69'], limit: 3));

        self::assertSame(['model_date_juri/openData/lastModified/CAA69/2026-10-01/false/3'], $this->calls, 'by the day the decision joined the base');
        self::assertSame(26, $results->total);
        self::assertSame('DCA_24LY03335_20260930.xml_CAA69', $results->items[0]->id);
        self::assertSame('2026-09-30', $results->items[0]->date->format('Y-m-d'), 'read on 30 September, added on 3 October');
        self::assertSame('2026-10-03', $results->items[0]->raw['lastModified']);

        $this->source(['recent' => JusticeAdministrativeSource::DECIDED])->recent(new \DateTimeImmutable('2026-10-01'));
        self::assertSame('model_Dates/openData/Date_Lecture/2026-10-01/false/25', end($this->calls));
    }

    /** A recent() mode it does not know is a misconfigured source, which Registry::usable() leaves out. */
    public function testAnUnknownRecentIsAnInvalidConfig(): void
    {
        $registry = new \Omnilex\Registry([new JusticeAdministrativeSourceFactory(new MockHttpClient())], ['ja' => ['factory' => 'justice-administrative', 'options' => ['recent' => 'yesterday']]]);
        self::assertSame([], $registry->usable());

        $this->expectException(\Omnilex\Exception\InvalidConfigException::class);
        $registry->get('ja');
    }

    public function testADecisionOfTheConseilDEtat(): void
    {
        $decision = $this->source()->decision('DCE_505010_20261002.xml_CE');

        self::assertSame(['elastic/decisions/CE/DCE_505010_20261002'], $this->calls);
        self::assertSame('DCE_505010_20261002.xml_CE', $decision->id);
        self::assertSame('Conseil d\'État, 02/10/2026, 505010', $decision->title);
        self::assertSame('ECLI:FR:CECHS:2026:505010.20261002', $decision->ecli());
        self::assertSame('8ème chambre jugeant seule', $decision->court->formation);
        self::assertSame('2026-10-02', $decision->date->format('Y-m-d'));
        self::assertSame('2026-10-03', $decision->updatedOn->format('Y-m-d'));
        self::assertSame('505010', $decision->number);
        self::assertSame('Décision', $decision->type);
        self::assertSame('Inédit au recueil Lebon', $decision->publication);
        self::assertTrue($decision->pseudonymised);
        self::assertSame([], $decision->citations);
        self::assertSame('fr', $decision->language);
        self::assertStringStartsWith("Vu la procédure suivante :\nPar une requête sommaire, un mémoire complémentaire", $decision->content);
        self::assertStringNotContainsString('$$$', $decision->content);
        self::assertArrayNotHasKey('paragraph', $decision->raw);
        self::assertSame('https://opendata.justice-administrative.fr/recherche/shareFile/CE/DCE_505010_20261002', $decision->url);

        self::assertSame($decision->id, $this->source()->decision('DCE_505010_20261002')?->id, 'a file of the Conseil d\'État names its court');
    }

    public function testADecisionByItsEcli(): void
    {
        $decision = $this->source()->decision(Identifier::ecli('ECLI:FR:CECHS:2026:505010.20261002'));

        self::assertSame(['Simple_Search/openData/505010/20', 'elastic/decisions/CE/DCE_505010_20261002'], $this->calls, 'the ECLI carries the case number: searched, recognised, then read');
        self::assertSame('DCE_505010_20261002.xml_CE', $decision->id);

        self::assertNull($this->source()->decision('ECLI:FR:CECHS:2026:999999.20261002'));
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('only the Conseil d\'État\'s ECLIs are published here');
        $this->source()->decision('ECLI:FR:CCASS:2018:C301117');
    }

    public function testADecisionOfACourtOfAppealByItsFile(): void
    {
        $decision = $this->source()->decision('DCA_24LY03335_20260930');

        self::assertSame(['Simple_Search/openData/DCA_24LY03335_20260930/5', 'elastic/decisions/CAA69/DCA_24LY03335_20260930'], $this->calls, 'the file does not say which court: the search does');
        self::assertSame('COUR ADMINISTRATIVE D\'APPEL DE LYON', $decision->court->name);
        self::assertSame('CAA69', $decision->court->code);
        self::assertNull($decision->court->chamber);
        self::assertSame('3ème chambre - formation à 3', $decision->court->formation);
        self::assertNull($decision->ecli());
        self::assertSame('24LY03335', $decision->number);
    }

    public function testUnknownIsNullAndAStrangeIdentifierRefused(): void
    {
        self::assertNull($this->source()->decision('DCE_999999999_20260101.xml_CE'));

        foreach (['17-18.194', 'JURITEXT000037999394', '505010'] as $id) {
            try {
                $this->source()->decision($id);
                self::fail();
            } catch (NotSupportedException) {
            }
        }
        $this->expectException(NotSupportedException::class);
        $this->source()->decision(Identifier::pourvoi('17-18.194'));
    }

    public function testDownIsNeverAnEmptyList(): void
    {
        $responses = [
            // What the engine answered on 2026-10-04 to a route called with a wrong last segment.
            new MockResponse('{"error":"Internal Server Error"}', ['http_code' => 500]),
            new MockResponse('<!DOCTYPE html><html><body><h1>Not Found</h1><h2>404</h2></body></html>', ['http_code' => 404]),
            new MockResponse('{"unexpected":true}'),
        ];
        $source = (new JusticeAdministrativeSourceFactory(new MockHttpClient($responses)))->create(['throttle' => 0]);

        try {
            $source->recent(new \DateTimeImmutable('2026-10-01'));
            self::fail('A watch read "down" as "nothing new".');
        } catch (UnavailableException $e) {
            self::assertSame(500, $e->status);
        }
        try {
            $source->search(new Query(text: 'urbanisme'));
            self::fail('A route that is gone was read as "no decision".');
        } catch (ProviderException $e) {
            self::assertStringContainsString('the search engine no longer answers this route: Simple_Search', $e->getMessage());
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('the search engine did not answer with decisions');
        $source->search(new Query(text: 'urbanisme'));
    }
}
