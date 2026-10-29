<?php

namespace Omnilex\JusticeAdministrative;

use Omnilex\Exception\InvalidConfigException;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\ProviderException;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Court;
use Omnilex\Model\Decision;
use Omnilex\Model\Identifier;
use Omnilex\Model\Identifiers;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Reference;
use Omnilex\Model\Results;
use Omnilex\Model\Scheme;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\HttpSource;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The decisions of the Conseil d'État, the administrative courts of appeal
 * and the administrative tribunals, from the open data of the
 * administrative justice (opendata.justice-administrative.fr).
 *
 * The site documents monthly archives to download; what is asked here is
 * the interface its own search engine (/recherche) calls: no published
 * contract, routes verified on 2026-10-04. It searches by text, court, kind
 * of decision and dates, and reads one decision; it does not page (a query
 * gives its first results, up to a limit), nor filter by subject, nor link
 * decisions to texts.
 *
 * The decisions are pseudonymised by the Conseil d'État before they are
 * published: they are given as they are, and nothing here tries to put a
 * name back on a person.
 */
final class JusticeAdministrativeSource extends HttpSource implements SearchInterface, DecisionReaderInterface, RecentInterface
{
    public const BASE_URI = 'https://opendata.justice-administrative.fr/recherche/api';
    public const SHARE_URI = 'https://opendata.justice-administrative.fr/recherche/shareFile';

    /** The most results asked in one call: the search engine's own default. */
    public const MAX = 200;

    /** The date a decision was read in open court. */
    public const DECIDED = 'Date_Lecture';
    /** The date a decision was added to the base. */
    public const ADDED = 'lastModified';

    private const TYPES = ['decision' => 'Décision', 'décision' => 'Décision', 'ordonnance' => 'Ordonnance'];

    /** The classification of the Conseil d'État's decisions (the site's help page). */
    private const PUBLICATION = [
        'A' => 'Publié au recueil Lebon',
        'B' => 'Mentionné aux tables du recueil Lebon',
        'C' => 'Inédit au recueil Lebon',
    ];

    /**
     * @param string                $recent   the date recent() reads: self::ADDED (added to the base) or self::DECIDED
     * @param array<string, string> $headers
     */
    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 1.0,
        private readonly string $recent = self::ADDED,
    ) {
        if (!\in_array($recent, [self::ADDED, self::DECIDED], true)) {
            throw new InvalidConfigException(\sprintf('recent() reads "%s" or "%s", not "%s".', self::ADDED, self::DECIDED, $recent));
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'justice-administrative';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [Kind::DECISION],
            identifiers: [Scheme::ECLI],
            criteria: ['text', 'number', 'kind', 'jurisdictions', 'from', 'to', 'types'],
            jurisdictions: ['FR'],
            pageSize: self::MAX,
            pseudonymised: true,
        );
    }

    public function search(Query $query): Results
    {
        $this->accept($query);

        return $this->find($query, self::DECIDED, $query->from, $query->to);
    }

    public function recent(\DateTimeInterface $since, ?Query $query = null): Results
    {
        $query ??= new Query();
        $this->accept($query, ['from', 'to']);

        return $this->find($query, $this->recent, Query::day($since), null);
    }

    public function decision(Identifier|string $id): ?Decision
    {
        $ecli = self::identify($id, Scheme::ECLI);
        if (null !== $ecli) {
            return $this->byEcli($ecli);
        }
        if ($id instanceof Identifier) {
            throw NotSupportedException::identifier($this->getName(), $id->key(), 'read a decision by');
        }
        // What search() gives: "DCE_505010_20261002.xml_CE", the file and the court.
        if (!preg_match('~^((?:D|OR)[A-Z]{2,3}_[0-9A-Za-z]+_\d{8})(?:\.xml)?(?:_([A-Z]{2,3}\d{0,3}[A-Z]?))?$~', trim($id), $m)) {
            throw NotSupportedException::identifier($this->getName(), $id, 'read a decision by');
        }
        $file = $m[1];
        $court = $m[2] ?? (preg_match('~^(?:D|OR)CE_~', $file) ? 'CE' : null);
        if (null === $court) {
            // The file of a court of appeal or of a tribunal does not say which one: the search does.
            $hit = $this->hits('Simple_Search/openData/'.rawurlencode($file).'/5')['hits'][0] ?? null;
            $court = $hit['_source']['Code_Juridiction'] ?? null;
            if (null === $court) {
                return null;
            }
        }
        $hit = $this->hits('elastic/decisions/'.rawurlencode($court).'/'.rawurlencode($file))['hits'][0] ?? null;

        return null === $hit ? null : $this->toDecision($hit);
    }

    /**
     * An ECLI of the Conseil d'État carries the case number and the day
     * (ECLI:FR:CECHS:2026:505010.20261002): the decision is searched by its
     * number, then recognised by its ECLI. The courts of appeal and the
     * tribunals publish no ECLI here.
     */
    private function byEcli(Identifier $ecli): ?Decision
    {
        if ('FR' !== $ecli->country() || !str_starts_with((string) $ecli->court(), 'CE') || !preg_match('~:(\d+)\.(\d{8})$~', $ecli->value, $m)) {
            throw NotSupportedException::identifier($this->getName(), $ecli->value, 'read a decision by (only the Conseil d\'État\'s ECLIs are published here)');
        }
        foreach ($this->hits('Simple_Search/openData/'.rawurlencode($m[1]).'/20')['hits'] as $hit) {
            if (strtoupper((string) ($hit['_source']['Numero_ECLI'] ?? '')) === $ecli->value) {
                return $this->decision((string) $hit['_id']);
            }
        }

        return null;
    }

    /** One call of the search engine: the route depends on which criteria are set. */
    private function find(Query $query, string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): Results
    {
        if (null !== $query->kind && Kind::DECISION !== $query->kind) {
            throw NotSupportedException::criterion($this->getName(), 'kind', 'only decisions are held');
        }
        if (null !== $query->cursor) {
            throw NotSupportedException::operation($this->getName(), 'page its results: narrow the dates, or raise the limit (up to '.self::MAX.')');
        }
        if (\count($query->jurisdictions) > 1) {
            throw NotSupportedException::criterion($this->getName(), 'jurisdictions', 'one court at a time (CE, CAA69, TA75...)');
        }
        if (\count($query->types) > 1) {
            throw NotSupportedException::criterion($this->getName(), 'types', 'one at a time (Décision or Ordonnance)');
        }
        if (null !== $query->text && null !== $query->number) {
            throw NotSupportedException::criterion($this->getName(), 'number', 'a case number is searched as the text, not along with one');
        }
        if (null !== $to && null === $from) {
            throw NotSupportedException::criterion($this->getName(), 'to', 'an end date needs a start date');
        }

        $court = isset($query->jurisdictions[0]) ? self::segment(strtoupper(trim($query->jurisdictions[0]))) : null;
        $type = null;
        if (isset($query->types[0])) {
            $type = self::TYPES[mb_strtolower(trim($query->types[0]))] ?? throw NotSupportedException::criterion($this->getName(), 'types', \sprintf('"%s" is neither Décision nor Ordonnance', $query->types[0]));
            $type = rawurlencode($type);
        }
        $text = null !== $query->text ? self::words($query->text) : $query->number;
        $text = null !== $text && '' !== trim($text) ? self::segment(trim($text)) : null;
        $limit = max(1, min($query->limit, self::MAX));
        // A day alone means "from that day on"; the engine reads "false" where the end would be.
        $dates = null === $from ? null : $from->format('Y-m-d').'/'.(null === $to ? 'false' : $to->format('Y-m-d'));

        $route = match (true) {
            null === $dates && null === $text => \sprintf('model_noTyped/openData/%s/%s', $court ?? 'null', $type ?? 'null'),
            null === $dates && null === $type && null === $court => "Simple_Search/openData/$text",
            null === $dates && null === $type => "model_search_juri/openData/$court/$text",
            null === $dates && null === $court => "Check_Search/openData/$type/$text",
            null === $dates => "model_search_check_juri/openData/$type/$court/$text",
            null === $text && null === $type && null === $court => "model_Dates/openData/$field/$dates",
            null === $text && null === $type => "model_date_juri/openData/$field/$court/$dates",
            null === $text => \sprintf('Date_Checkbox/openData/%s/%s/%s/%s', $field, $type, $dates, $court ?? 'null'),
            null === $type && null === $court => "model_searchANDdates/openData/$field/$text/$dates",
            null === $type => "model_search_date_juri/openData/$field/$text/$court/$dates",
            null === $court => "model_all/openData/$field/$text/$type/$dates",
            default => "model_ABCD/openData/$field/$text/$type/$court/$dates",
        };
        $found = $this->hits($route.'/'.$limit);

        return new Results(array_map($this->toReference(...), $found['hits']), null, $found['total']);
    }

    /** @return array{hits: list<array<string, mixed>>, total: ?int} */
    private function hits(string $route): array
    {
        $data = $this->getJson($route);
        if (null === $data) {
            // The engine answers 404 to a route it does not have: the interface changed.
            throw new ProviderException($this->getName(), 'the search engine no longer answers this route: '.explode('/', $route)[0], 404);
        }
        $hits = $data['decisions']['body']['hits'] ?? null;
        if (!\is_array($hits) || !\is_array($hits['hits'] ?? null)) {
            throw new ProviderException($this->getName(), 'the search engine did not answer with decisions');
        }
        // A case number known to several courts comes wrapped: one message holding the decisions.
        $list = [];
        foreach ($hits['hits'] as $hit) {
            foreach (isset($hit['_message'], $hit['values']) && \is_array($hit['values']) ? $hit['values'] : [$hit] as $one) {
                if (isset($one['_source']['Identification'])) {
                    $list[] = $one;
                }
            }
        }

        return ['hits' => $list, 'total' => isset($hits['total']['value']) ? (int) $hits['total']['value'] : null];
    }

    /** @param array<string, mixed> $hit */
    private function toReference(array $hit): Reference
    {
        $source = $hit['_source'];
        $court = $this->court($source);

        return new Reference(
            kind: Kind::DECISION,
            id: (string) ($hit['_id'] ?? $source['Identification']),
            title: self::title($court, $source),
            identifiers: self::identifiers($source),
            date: self::day($source['Date_Lecture'] ?? null),
            type: $source['Type_Decision'] ?? null,
            number: isset($source['Numero_Dossier']) ? (string) $source['Numero_Dossier'] : null,
            court: $court,
            summary: self::highlight($hit['highlight'] ?? null),
            url: self::url($source),
            source: $this->getName(),
            raw: $source,
        );
    }

    /** @param array<string, mixed> $hit */
    private function toDecision(array $hit): Decision
    {
        $source = $hit['_source'];
        $court = $this->court($source);
        $number = isset($source['Numero_Dossier']) ? (string) $source['Numero_Dossier'] : null;
        $code = $source['Code_Publication'] ?? null;
        // The lines of the decision come joined by "$$$".
        $content = isset($source['paragraph']) ? trim(implode("\n", array_map('trim', explode('$$$', (string) $source['paragraph'])))) : null;
        unset($source['paragraph']);

        return new Decision(
            id: (string) ($hit['_id'] ?? $source['Identification']),
            title: self::title($court, $source),
            identifiers: self::identifiers($source),
            court: $court,
            date: self::day($source['Date_Lecture'] ?? null),
            number: $number,
            numbers: null !== $number ? [$number] : [],
            type: $source['Type_Decision'] ?? null,
            solution: null,
            publication: \is_string($code) ? (self::PUBLICATION[$code] ?? $code) : null,
            summary: null,
            subjects: [],
            content: '' === $content ? null : $content,
            citations: [],
            pseudonymised: true,
            updatedOn: self::day($source['lastModified'] ?? null),
            language: 'fr',
            url: self::url($source),
            source: $this->getName(),
            raw: $source,
        );
    }

    /** @param array<string, mixed> $source */
    private function court(array $source): Court
    {
        $code = isset($source['Code_Juridiction']) ? (string) $source['Code_Juridiction'] : null;
        $name = trim((string) ($source['Nom_Juridiction'] ?? ''));
        // The Conseil d'État's decisions are named after its litigation section.
        $supreme = 'CE' === $code;

        return new Court(
            $supreme ? 'Conseil d\'État' : ('' !== $name ? $name : (string) $code),
            $code,
            'FR',
            $supreme && '' !== $name ? $name : null,
            isset($source['Formation_Jugement']) && '' !== trim((string) $source['Formation_Jugement']) ? trim((string) $source['Formation_Jugement']) : null,
        );
    }

    /** @param array<string, mixed> $source */
    private static function title(Court $court, array $source): string
    {
        $date = self::day($source['Date_Lecture'] ?? null);

        return trim(\sprintf('%s, %s%s', $court->name, null !== $date ? $date->format('d/m/Y').', ' : '', $source['Numero_Dossier'] ?? $source['Identification']), ', ');
    }

    /** @param array<string, mixed> $source */
    private static function identifiers(array $source): Identifiers
    {
        // The courts that publish no ECLI send the word "undefined".
        return Identifiers::of(Identifier::tryOf(Scheme::ECLI, isset($source['Numero_ECLI']) && 'undefined' !== $source['Numero_ECLI'] ? (string) $source['Numero_ECLI'] : null));
    }

    /** @param array<string, mixed> $source */
    private static function url(array $source): ?string
    {
        if (!isset($source['Code_Juridiction'], $source['Identification'])) {
            return null;
        }

        return self::SHARE_URI.'/'.rawurlencode((string) $source['Code_Juridiction']).'/'.rawurlencode((string) preg_replace('~\.xml$~', '', (string) $source['Identification']));
    }

    private static function highlight(mixed $highlight): ?string
    {
        if (!\is_array($highlight)) {
            return null;
        }
        $fragments = [];
        array_walk_recursive($highlight, static function ($value) use (&$fragments): void {
            if (\is_string($value)) {
                $fragments[] = $value;
            }
        });

        return self::plain(implode(' … ', \array_slice($fragments, 0, 3)));
    }

    /**
     * The engine reads several words as "any of them", takes AND between
     * them, and an expression in double quotes as it is: words are sent as
     * "all of them", like the other sources read them.
     */
    private static function words(string $text): string
    {
        $text = trim($text);
        if (preg_match('~^".+"$~su', $text)) {
            return $text;
        }

        return implode(' AND ', (array) preg_split('~\s+~u', str_replace('"', ' ', $text), -1, \PREG_SPLIT_NO_EMPTY));
    }

    /** A value as one segment of the route: the engine reads its criteria from the path. */
    private static function segment(string $value): string
    {
        return rawurlencode(str_replace('/', ' ', $value));
    }
}
