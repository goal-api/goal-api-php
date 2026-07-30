<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

/**
 * Unauthenticated status and coverage. No API key needed.
 *
 * These five don't use the `['success', 'data']` envelope -- they return bare objects, so
 * read `$goal->status->get()['status']`, not `['data']['status']`.
 */
final class Status extends Resource
{
    /** @return array<string, mixed> `{status, updatedAt, measurement, components[]}` */
    public function get(): array
    {
        return $this->t->get('/public/status');
    }

    /** @return array<string, mixed> `{leagues, countries, teams, players, fixtures, ...}` */
    public function coverage(): array
    {
        return $this->t->get('/public/coverage');
    }

    /**
     * `{leagues[], total, page, limit, pages}`
     *
     * Paginates with `page`/`limit`, not `offset`, so `GoalApi::paginate()` won't work
     * here. Also accepts `q` and `country`.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function coverageLeagues(?array $params = null): array
    {
        return $this->t->get('/public/coverage/leagues', $params);
    }

    /** @return array<string, mixed> `{countries[], total}` */
    public function coverageCountries(): array
    {
        return $this->t->get('/public/coverage/countries');
    }

    /** @return array<string, mixed> A bare league object. The 404 body is `{error, code}`. */
    public function coverageLeague(string|int $leagueId): array
    {
        return $this->t->get('/public/coverage/leagues/' . $this->seg($leagueId));
    }
}
