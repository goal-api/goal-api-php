<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

/**
 * News articles, newest first.
 *
 * The `leagueId`, `teamId` and `matchId` filters take the news source's ids, which are
 * not guaranteed to have a row behind `/leagues` or `/teams` — the source covers
 * competitions outside our fixture data. Each article carries `teamName` and
 * `leagueName` for that reason, and any of them may be null.
 */
final class News extends Resource
{
    /**
     * @param array<string, mixed>|null $params leagueId, teamId, matchId, from, to,
     *                                          limit (max 100, default 20), offset
     * @return array<string, mixed>
     */
    public function list(?array $params = null): array
    {
        return $this->t->get('/news', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byMatch(string|int $matchId, ?array $params = null): array
    {
        return $this->t->get('/news/match/' . $this->seg($matchId), $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byTeam(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/news/team/' . $this->seg($teamId), $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byLeague(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/news/league/' . $this->seg($leagueId), $params);
    }

    /**
     * One article, by our id or the provider's own news key. 404s when absent.
     *
     * @return array<string, mixed>
     */
    public function get(string|int $id): array
    {
        return $this->t->get('/news/' . $this->seg($id));
    }
}
