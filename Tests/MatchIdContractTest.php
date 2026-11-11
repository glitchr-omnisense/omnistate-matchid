<?php

namespace Omnistate\MatchId\Tests;

use Omnistate\Model\CivilQuery;
use Omnistate\Model\Period;
use Omnistate\MatchId\MatchId;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on the register's recorded answers (Tests/Fixtures). */
final class MatchIdContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new MatchId($http);
    }

    protected function recordedAnswers(): array
    {
        return ['*' => static fn (string $method, string $url) => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match (true) {
            str_contains($url, '/id/') => 'id.json',
            default => 'search.json',
        }), ['response_headers' => ['content-type' => 'application/json']])];
    }

    protected function civilQuery(): CivilQuery
    {
        return new CivilQuery(familyName: 'Chirac', givenName: 'Jacques', born: Period::year(1932));
    }

    protected function civilIdentifier(): string
    {
        return 'fohpDS17K0Ez';
    }
}
