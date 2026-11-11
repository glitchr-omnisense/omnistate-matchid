<?php

namespace Omnistate\MatchId;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Http\Redaction;
use Omnistate\Identifier\CountryCode;
use Omnistate\Model\Archive;
use Omnistate\Model\CivilPerson;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecord;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Model\Place;
use Omnistate\Model\Sex;
use Omnistate\Registry\CivilRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * France's deaths since 1970: INSEE's file of deceased persons, searched
 * through matchID (deces.matchid.io, run by the French Ministry of the
 * Interior). One record per death: the names, the sex, the date and place of
 * birth, the date and place of death, the act's number. No picture of the
 * act: the file is an index, the act itself is at the town hall.
 *
 * Free, no key. A token (an account at deces.matchid.io) raises the quota;
 * it is sent as "Authorization: Bearer".
 */
final class MatchId implements CivilRegistryInterface, DeclaresUsagePolicyInterface
{
    public const URL = 'https://deces.matchid.io/deces/api/v1';
    /** A record's page on matchID's own site. */
    public const PAGE = 'https://deces.matchid.io/id/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $token = null,
        private readonly float $timeout = 10,
        private readonly string $url = self::URL,
    ) {
    }

    /** Its usage policy: policy.json, beside this class. */
    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    public function name(): string
    {
        return 'matchid';
    }

    public function countries(): array
    {
        return ['FR'];
    }

    public function kinds(): array
    {
        return [CivilRecordKind::DEATH];
    }

    public function period(): Period
    {
        return Period::since(1970);
    }

    public function hasImages(): bool
    {
        return false;
    }

    /** A name at least: the file is not browsed by date alone here. Deaths before 1970 are not in it. */
    public function supports(CivilQuery $query): bool
    {
        if (!$query->hasName() || !$query->inCountries('FR') || !$query->wants(CivilRecordKind::DEATH)) {
            return false;
        }
        $died = $query->died ?? ([CivilRecordKind::DEATH] === $query->kinds ? $query->dated : null);

        return null === $died?->to?->year || $died->to->year >= 1970;
    }

    public function search(CivilQuery $query): array
    {
        $died = $query->died ?? $query->dated;
        $answer = $this->ask('/search', array_filter([
            'lastName' => $query->familyName,
            'firstName' => $query->givenName,
            'birthDate' => self::dates($query->born),
            'deathDate' => self::dates($died),
            'deathCity' => $query->place,
            'sex' => match ($query->sex) { Sex::MALE => 'M', Sex::FEMALE => 'F', default => null },
            'size' => $query->limit,
            'page' => $query->page > 1 ? $query->page : null,
        ], static fn (mixed $value) => null !== $value && '' !== $value));

        $persons = self::at($answer, 'response', 'persons') ?? [];
        if (!\is_array($persons)) {
            throw self::malformed();
        }

        return array_values(array_map(fn (mixed $person) => $this->record(\is_array($person) ? $person : throw self::malformed()), $persons));
    }

    public function find(string $identifier): ?CivilRecord
    {
        if (!preg_match('/^[\w-]{6,40}$/', $identifier)) {
            return null;
        }
        $person = self::at($this->ask('/id/'.$identifier, [], true), 'response', 'persons', 0);

        return null === $person ? null : $this->record(\is_array($person) ? $person : throw self::malformed());
    }

    /** "1932", "1930-1935", "29/11/1932": the three ways matchID reads a date. */
    private static function dates(?Period $period): ?string
    {
        if (null === $period || (null === $period->from && null === $period->to)) {
            return null;
        }
        if ($period->isDay() && null !== $period->from) {
            return sprintf('%02d/%02d/%04d', $period->from->day, $period->from->month, $period->from->year);
        }
        $from = $period->from->year ?? 1850;
        $to = $period->to->year ?? (int) date('Y');

        return $from === $to ? (string) $from : $from.'-'.$to;
    }

    /**
     * @param array<string, mixed> $query
     * @param bool                 $find  a lookup: a 404 is no such person (an empty answer)
     *
     * @return array<mixed>
     */
    private function ask(string $path, array $query = [], bool $find = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').$path, ['query' => $query, 'headers' => $headers, 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            if (429 === $status || $status >= 500) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException(sprintf('matchID answered %d.', $status), is_numeric($retry) ? (int) $retry : (429 === $status ? 60 : null), null, $status);
            }
            if (401 === $status || 403 === $status) {
                throw new UnavailableException(sprintf('matchID refused the call (%d): the token is missing or refused.', $status));
            }
            if ($find && 404 === $status) {
                return [];
            }
            $answer = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            // The token, in the request's headers, kept out: the previous a copy of the client's exception, no trace keeping the options.
            $secrets = array_filter([(string) $this->token]);

            throw Redaction::withoutArguments(new UnavailableException('matchID did not answer: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 400) {
            throw new OmnistateException(sprintf('matchID refused the question (%d): %s', $status, implode(' ', array_map(self::cast(...), (array) ($answer['msg'] ?? '')))));
        }

        return $answer;
    }

    /** @param array<mixed> $person */
    private function record(array $person): CivilRecord
    {
        $id = self::cast($person['id'] ?? null);
        $death = $person['death'] ?? [];
        $birth = $person['birth'] ?? [];
        $deathDate = PartialDate::parse(self::string(self::at($death, 'date')));
        $deathPlace = self::object(self::at($death, 'location'));
        $first = self::at($person, 'name', 'first') ?? [];
        $age = self::at($death, 'age');
        $certificate = self::cast(self::at($death, 'certificateId'));

        return new CivilRecord(
            identifier: $id,
            kind: CivilRecordKind::DEATH,
            source: $this->name(),
            date: $deathDate,
            place: self::place($deathPlace),
            persons: [new CivilPerson(
                familyName: self::cast(self::at($person, 'name', 'last')),
                givenNames: array_values(array_filter(\is_array($first) ? $first : [$first], 'is_string')),
                sex: Sex::fromAny($person['sex'] ?? null),
                role: 'Deceased',
                birthDate: PartialDate::parse(self::string(self::at($birth, 'date'))),
                birthPlace: self::place(self::object(self::at($birth, 'location'))),
                deathDate: $deathDate,
                deathPlace: self::place($deathPlace),
                age: is_numeric($age) ? (int) $age : null,
            )],
            archive: new Archive('INSEE, fichier des personnes décédées', 'insee', isset($person['source']) ? trim(sprintf('fichier %s, ligne %s', self::cast($person['source']), self::cast($person['sourceLine'] ?? ''))) : null, 'https://www.insee.fr/fr/information/4190491'),
            url: self::PAGE.$id,
            title: 'Décès',
            number: '' !== $certificate ? $certificate : null,
            raw: $person,
        );
    }

    /** @param array<mixed>|null $location */
    private static function place(?array $location): ?Place
    {
        if (!$location) {
            return null;
        }
        // a town that changed names, or an arrondissement: several names, the most precise last
        $city = $location['city'] ?? null;
        $name = \is_array($city) ? (end($city) ?: null) : $city;
        $postcode = $location['codePostal'] ?? null;

        return new Place(
            name: \is_string($name) && '' !== $name ? $name : null,
            code: isset($location['code']) ? self::cast($location['code']) : null,
            postcode: self::string(\is_array($postcode) ? ($postcode[0] ?? null) : $postcode),
            region: isset($location['departmentCode']) ? self::cast($location['departmentCode']) : null,
            country: CountryCode::alpha2(self::string($location['countryCode'] ?? null)),
            countryName: self::string($location['country'] ?? null),
            latitude: isset($location['latitude']) ? self::number($location['latitude']) : null,
            longitude: isset($location['longitude']) ? self::number($location['longitude']) : null,
        );
    }

    /**
     * A value handed to a ?string parameter, as PHP coerced it: a scalar as
     * its string, null kept; an array where a text belongs is an answer of
     * another shape.
     */
    private static function string(mixed $value): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            default => throw self::malformed(),
        };
    }

    /**
     * A location, as PHP handed it to an ?array parameter: null when absent;
     * anything else where an object belongs is an answer of another shape.
     *
     * @return array<mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        return null === $value || \is_array($value) ? $value : throw self::malformed();
    }

    /**
     * $value[$key][...] ?? null, as PHP reads it: an array's item, a string's
     * character at an integer offset, null for anything else.
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (\is_array($value) || (\is_string($value) && \is_int($key))) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /** (string) of a scalar, as the answer's values were cast: "" for null, and for an array where PHP gave "Array". */
    private static function cast(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** (float) of a scalar, 0.0 for anything else (an array: where PHP gave 1.0 for a non-empty one). */
    private static function number(mixed $value): float
    {
        return \is_scalar($value) ? (float) $value : 0.0;
    }

    /** The family's rule on a body that is not what it should be (docs/providers.md). */
    private static function malformed(): UnavailableException
    {
        return new UnavailableException('matchID answered in another shape than its API\'s.');
    }
}
