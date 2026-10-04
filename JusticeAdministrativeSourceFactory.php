<?php

namespace Omnilex\JusticeAdministrative;

use Omnilex\Config;
use Omnilex\Source\SourceFactory;
use Omnilex\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The open data of the administrative courts - no key.
 *
 *   options:
 *     recent: lastModified      # the date recent() reads: lastModified (added to the base) or Date_Lecture (decided)
 *     throttle: 1.0             # seconds between two calls
 *     base_uri: 'https://opendata.justice-administrative.fr/recherche/api'
 */
final class JusticeAdministrativeSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilex.factory_name' => 'justice-administrative',
            'omnilex.required_options' => [],
            'recent' => JusticeAdministrativeSource::ADDED,
            'throttle' => 1.0,
            'base_uri' => JusticeAdministrativeSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new JusticeAdministrativeSource(
            $this->http ?? HttpClient::create(['timeout' => 60]),
            (string) $c->get('base_uri', JusticeAdministrativeSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c['throttle'],
            (string) $c->get('recent', JusticeAdministrativeSource::ADDED),
        );
    }
}
