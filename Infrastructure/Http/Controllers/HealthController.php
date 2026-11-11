<?php

declare(strict_types=1);

namespace Plugins\Health\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Health\API\Contracts\HealthCheckerContract;

/**
 * The HTTP surface. Three lines of work per action; everything else is in the
 * service.
 *
 * Every response carries `Cache-Control: no-store`. A cached health response is
 * actively dangerous: a CDN or reverse proxy that holds a 200 for sixty seconds
 * keeps a dead instance in the load balancer for sixty seconds after it died,
 * and the operator sees green the whole time.
 */
final class HealthController
{
    public function __construct(private readonly HealthCheckerContract $health) {}

    /**
     * `GET /health` — LIVENESS. Point a container's liveness probe here.
     *
     * Touches no dependency, so a database outage cannot make an orchestrator
     * restart every replica. Always 200 while PHP can answer.
     */
    public function live(Request $request): Response
    {
        return $this->respond($this->health->live()->toArray(), 200);
    }

    /**
     * `GET /health/ready` — READINESS. Point a load balancer here.
     *
     * 200 when the dependencies answer, 503 when they do not. Degraded is still
     * 200 — pulling an instance out for slowness removes capacity exactly when
     * the rest are already struggling.
     */
    public function ready(Request $request): Response
    {
        $report = $this->health->ready();

        // The STATUS and the HTTP code always answer; the per-dependency detail
        // is withheld without the token. A kubelet cannot carry a bearer token,
        // so gating the whole endpoint would mean choosing between a public
        // inventory of the infrastructure and no probe at all.
        $body = $this->health->authorises($this->token($request))
            ? $report->toArray()
            : ['status' => $report->status->value];

        return $this->respond($body, $report->httpStatus());
    }

    /**
     * `GET /health/{probe}` — one dependency.
     *
     * Always requires the token when one is configured: unlike readiness,
     * nothing automated depends on this route, so there is no reason to answer
     * an unauthenticated caller which dependencies exist.
     */
    public function one(Request $request, string $probe): Response
    {
        if (!$this->health->authorises($this->token($request))) {
            // 404, not 401. A 401 confirms the route exists and that a token
            // guards it; this way an unauthenticated prober learns nothing it
            // did not already know from guessing the URL.
            return $this->respond(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404);
        }

        $result = $this->health->probe($probe);

        if ($result === null) {
            return $this->respond([
                'error' => [
                    'code'    => 'unknown_probe',
                    'message' => "No probe named [{$probe}].",
                    'probes'  => $this->health->probeNames(),
                ],
            ], 404);
        }

        return $this->respond(
            ['status' => $result->status->value] + $result->toArray(),
            $result->status->isFailure() ? 503 : 200,
        );
    }

    /**
     * The token, from a header or a query parameter.
     *
     * The header is the right way. The query parameter exists because some
     * monitoring services cannot set headers, and it is documented as the worse
     * option: a token in a URL lands in access logs.
     */
    private function token(Request $request): ?string
    {
        $header = $request->header('X-Health-Token');

        if ($header !== null && trim($header) !== '') {
            return trim($header);
        }

        $query = $request->query('token');

        return \is_string($query) && trim($query) !== '' ? trim($query) : null;
    }

    /** @param array<string, mixed> $body */
    private function respond(array $body, int $status): Response
    {
        return Response::json($body, $status)->withHeader('Cache-Control', 'no-store');
    }
}
