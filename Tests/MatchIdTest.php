<?php

namespace Omnistate\MatchId\Tests;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\MatchId\MatchId;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Model\Sex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** On answers recorded from deces.matchid.io on 2026-10-04. */
final class MatchIdTest extends TestCase
{
    public function testWhatItCovers(): void
    {
        $matchid = new MatchId(new MockHttpClient());

        self::assertSame('matchid', $matchid->name());
        self::assertSame(['FR'], $matchid->countries());
        self::assertSame([CivilRecordKind::DEATH], $matchid->kinds());
        self::assertSame(1970, $matchid->period()->from?->year);
        self::assertFalse($matchid->hasImages());

        self::assertTrue($matchid->supports(new CivilQuery(familyName: 'Chirac')));
        self::assertTrue($matchid->supports(new CivilQuery(familyName: 'Chirac', country: 'fr', kinds: [CivilRecordKind::DEATH, CivilRecordKind::BIRTH])));
        self::assertFalse($matchid->supports(new CivilQuery(familyName: 'Chirac', country: 'NL')), 'another country');
        self::assertFalse($matchid->supports(new CivilQuery(familyName: 'Chirac', kinds: [CivilRecordKind::BIRTH])), 'births are not in the file');
        self::assertFalse($matchid->supports(new CivilQuery(familyName: 'Chirac', died: Period::years(1900, 1950))), 'before 1970');
        self::assertFalse($matchid->supports(new CivilQuery(born: Period::year(1932))), 'no name');
    }

    public function testADeathFoundByNameAndYearOfBirth(): void
    {
        $asked = null;
        $matchid = new MatchId(new MockHttpClient(function (string $method, string $url, array $options) use (&$asked) {
            $asked = [$method, $url, $options['headers']];

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/search.json'));
        }));
        $records = $matchid->search(new CivilQuery(familyName: 'Chirac', givenName: 'Jacques', born: Period::year(1932), limit: 2));

        self::assertNotNull($asked);
        self::assertSame('GET', $asked[0]);
        self::assertSame('https://deces.matchid.io/deces/api/v1/search?lastName=Chirac&firstName=Jacques&birthDate=1932&size=2', $asked[1]);
        self::assertIsArray($asked[2]);
        self::assertNotContains('Authorization', array_map(static fn (mixed $h) => \is_string($h) ? strtok($h, ':') : $h, $asked[2]), 'no token, no header');

        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('fohpDS17K0Ez', $record->identifier);
        self::assertSame(CivilRecordKind::DEATH, $record->kind);
        self::assertSame('matchid', $record->source);
        self::assertSame('2019-09-26', (string) $record->date);
        self::assertNotNull($record->place);
        self::assertSame('Paris 6e Arrondissement', $record->place->name, 'the most precise of the names given');
        self::assertSame('75106', $record->place->code);
        self::assertSame('75006', $record->place->postcode);
        self::assertSame('75', $record->place->region);
        self::assertSame('FR', $record->place->country, 'FRA');
        self::assertSame('129', $record->number);
        self::assertSame('https://deces.matchid.io/id/fohpDS17K0Ez', $record->url);
        self::assertSame([], $record->images);
        self::assertSame('fichier 2019, ligne 505032', $record->archive?->reference);

        $person = $record->principal();
        self::assertNotNull($person);
        self::assertSame('Jacques Rene Chirac', $person->name());
        self::assertSame(['Jacques', 'Rene'], $person->givenNames);
        self::assertSame(Sex::MALE, $person->sex);
        self::assertSame('1932-11-29', (string) $person->birthDate);
        self::assertSame('Paris 5e Arrondissement', $person->birthPlace?->name);
        self::assertSame(86, $person->age);
        self::assertIsArray($record->raw['links']);
        self::assertSame('http://www.wikidata.org/entity/Q2105', $record->raw['links']['wikidata']);
    }

    public function testTheWaysADateIsAsked(): void
    {
        $urls = [];
        $matchid = new MatchId(new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = urldecode(substr($url, \strlen(MatchId::URL)));

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/none.json'));
        }), 'secret');

        self::assertSame([], $matchid->search(new CivilQuery(familyName: 'Chirac', born: Period::years(1930, 1935), died: Period::year(2019), place: 'Paris', sex: Sex::MALE, limit: 5, page: 2)));
        $matchid->search(new CivilQuery(familyName: 'Chirac', born: Period::day(new PartialDate(1932, 11, 29))));
        $matchid->search(new CivilQuery(givenName: 'Jacques', kinds: [CivilRecordKind::DEATH], dated: Period::since(2015)));

        self::assertSame('/search?lastName=Chirac&birthDate=1930-1935&deathDate=2019&deathCity=Paris&sex=M&size=5&page=2', $urls[0]);
        self::assertSame('/search?lastName=Chirac&birthDate=29/11/1932&size=20', $urls[1]);
        self::assertSame('/search?firstName=Jacques&deathDate=2015-'.date('Y').'&size=20', $urls[2]);
    }

    public function testTheTokenIsSentAsBearer(): void
    {
        $headers = [];
        $matchid = new MatchId(new MockHttpClient(function (string $method, string $url, array $options) use (&$headers) {
            $headers = $options['headers'];

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/id.json'));
        }), 'secret');

        self::assertSame('Jacques Rene Chirac', $matchid->find('fohpDS17K0Ez')?->principal()?->name());
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer secret', $headers);
    }

    public function testAnIdentifierNobodyHas(): void
    {
        $matchid = new MatchId(new MockHttpClient(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/none.json'))));

        self::assertNull($matchid->find('AAAAAAAAAAAA'));
        self::assertNull($matchid->find('../etc'), 'not asked');
    }

    public function testDownIsNotNobody(): void
    {
        try {
            (new MatchId(new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '12']]))))->search(new CivilQuery(familyName: 'Chirac'));
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertSame(12, $e->retryAfter);
        }

        $this->expectException(UnavailableException::class);
        (new MatchId(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']))))->find('fohpDS17K0Ez');
    }

    public function testAnAnswerOfAnotherShapeIsUnavailable(): void
    {
        foreach ([
            '{"response":{"persons":"none"}}',
            '{"response":{"persons":["none"]}}',
            '{"response":{"persons":[{"id":"x","death":{"location":"Paris"}}]}}',
            '{"response":{"persons":[{"id":"x","birth":{"date":["1932"]}}]}}',
            '{"response":{"persons":[{"id":"x","death":{"location":{"codePostal":[["75006"]]}}}]}}',
            '{"response":{"persons":[{"id":"x","death":{"location":{"countryCode":["FRA"]}}}]}}',
        ] as $body) {
            foreach (['search', 'find'] as $call) {
                $matchid = new MatchId(new MockHttpClient(new MockResponse($body)));
                try {
                    'search' === $call ? $matchid->search(new CivilQuery(familyName: 'Chirac')) : $matchid->find('fohpDS17K0Ez');
                    self::fail($call.' unavailable: '.$body);
                } catch (UnavailableException $e) {
                    self::assertSame('matchID answered in another shape than its API\'s.', $e->getMessage());
                }
            }
        }
    }

    public function testAQuestionItRefuses(): void
    {
        $this->expectException(OmnistateException::class);
        $this->expectExceptionMessage('invalid birthDate value: banana');
        (new MatchId(new MockHttpClient(new MockResponse('{"msg":["invalid birthDate value: banana"]}', ['http_code' => 400]))))->search(new CivilQuery(familyName: 'Chirac'));
    }

    /**
     * Not reached: nothing of the credential anywhere in what a log of the whole chain writes - the
     * messages, the previous exception (a copy of the HTTP client's, its class kept, rather than the
     * client's own, whose trace kept the request's headers), the traces' arguments when
     * zend.exception_ignore_args is off.
     */
    public function testNoPartOfAnUnreachableChainHoldsTheCredential(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: deces.matchid.io for "%s".', $url));
        });
        try {
            (new MatchId($http, 'matchid-token-1234567'))->find('abcdef');
            self::fail('no exception');
        } catch (\Omnistate\Exception\UnavailableException $e) {
            self::assertInstanceOf(\Symfony\Component\HttpClient\Exception\TransportException::class, $e->getPrevious());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                self::assertFalse(str_contains($one->getMessage(), 'matchid-token-1234567'), $one->getMessage());
                self::assertFalse(str_contains(print_r($one->getTrace(), true), 'matchid-token-1234567'), 'a trace holds the credential');
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
