<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP-Client für die TeamUp-Kalender-API.
 *
 * Ruft Kalendereinträge über die öffentliche iCal-URL von TeamUp ab.
 */
class TeamUpClient
{
    private const BASE_URL = 'https://api.teamup.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $calendarKey,
    ) {}

    public function fetchEvents(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $response = $this->httpClient->request('GET', sprintf(
            '%s/%s/events',
            self::BASE_URL,
            $this->calendarKey
        ), [
            'headers' => ['Teamup-Token' => $this->apiKey],
            'query' => [
                'startDate' => $from->format('Y-m-d'),
                'endDate' => $to->format('Y-m-d'),
            ],
        ]);

        $data = $response->toArray();
        return $data['events'] ?? [];
    }
}
