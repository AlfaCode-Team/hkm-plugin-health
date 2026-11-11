# hkm-plugin-health

> HKM Kernel plugin — provides **`health.probes`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

HTTP liveness and readiness probes for the dependencies of the **running
process**. Declares no `requires[]`, so it installs anywhere.

## This is not `hkm doctor`

| Question | Authority |
| --- | --- |
| Can this machine run the kernel? Extensions, permissions, PHP version, directories | **`hkm doctor`** — the single authority, and it stays that way |
| Are the dependencies of this running process reachable right now? | this plugin |

They do not overlap. A load balancer cannot shell into a container to ask
doctor, and doctor cannot see which ports a materialised container actually
bound.

## Point the two probes at the two different routes

```yaml
livenessProbe:  { httpGet: { path: /health,       port: 8080 } }
readinessProbe: { httpGet: { path: /health/ready, port: 8080 } }
```

**This distinction is the whole design**, because an orchestrator does different
things with the answers: a failed **liveness** probe **restarts** the container,
a failed **readiness** probe only takes it out of the load balancer and leaves it
running.

So a liveness probe pointed at the database means that during a database blip
Kubernetes kills every replica at once — turning a recoverable dependency outage
into a dependency outage *plus* a restart storm, with no warm capacity left when
the database returns. The restart also fixed nothing, because the application
was never the broken part.

`GET /health` therefore touches **nothing external**. Its answer is proven by the
response arriving at all: routing, the pipeline, the container and PHP are all
working if you got a reply. A test asserts it runs zero probes, and it stays 200
even when every dependency is down.

## Routes

| Route | Answers | Needs the token? |
| --- | --- | --- |
| `GET /health` | Liveness. Always 200 while PHP can answer | no |
| `GET /health/ready` | Readiness. 200 / **503** | status always; **detail** only with it |
| `GET /health/{probe}` | One dependency | yes, when one is configured |

`/health/ready` and `/health/{probe:alpha}` coexist because the kernel resolves
static routes before dynamic ones, so `/health/ready` never reaches the
one-probe handler.

Every response carries `Cache-Control: no-store`. A proxy holding a 200 for
sixty seconds keeps a dead instance in the load balancer for sixty seconds after
it died, and the dashboard stays green throughout.

## Four statuses, and `Degraded` is still 200

| Status | Meaning | Effect on readiness |
| --- | --- | --- |
| `up` | Answered in time | 200 |
| `degraded` | Reachable but slow, or a backlog over the ceiling | **200** |
| `down` | Unreachable, or answered nonsensically | **503** |
| `skipped` | The port is not bound, so nothing was checked | ignored |

**Degraded answers 200 on purpose.** A slow dependency is worth alerting on, but
pulling the instance out of the load balancer for it removes capacity at exactly
the moment the remaining instances are already struggling — which is how a
slowdown becomes an outage.

**`skipped` is neither up nor down**, and both distinctions matter: reporting an
absent dependency as `up` is a lie an operator will act on, and reporting it as
`down` makes every application without a queue permanently unhealthy. The row is
still *present* in the report, so "fine" is distinguishable from "nobody wired
it".

## What each probe does, and what it refuses to do

| Probe | Does | Deliberately does not |
| --- | --- | --- |
| `database` | `SELECT 1` | touch a table, check migrations, or measure replication lag — a pending migration must not empty the load balancer |
| `cache` | **writes** a namespaced key, reads it back, deletes it | trust a read alone |
| `queue` | `size()` | push a probe job onto a real queue that a real worker then handles |
| `storage` | `exists()` on a random path under `.health-probe/` | **write anything** |

**The cache probe is the one that writes, and that is the point.** The failure it
exists to catch is a cache that *accepts* a write and cannot return it — a full
Redis whose eviction policy drops the key instantly, a replica in read-only
mode, a misconfigured cluster slot. All of those answer a read with a miss,
which is indistinguishable from a cold cache, so a read-only probe calls them
healthy while every cached value in the application silently evaporates. The key
is unique per check so two replicas cannot read each other's and mask it.

**The storage probe treats `false` as success.** The question is "did the backend
answer", not "is the file there". Probing a sentinel file that must exist would
need that file deployed everywhere and would turn a missing artefact into a
fleet-wide outage.

**`DocumentStorePort` is not probed at all.** Firestore bills `count()` per 1,000
index entries and charges for every read, so a probe running from every replica
every few seconds is a standing charge. A document store's health is a
monitoring concern, not a readiness one.

## A probe never throws

A health endpoint that returns 500 on a broken dependency tells an orchestrator
nothing it can act on — a 500 is the one response that cannot be interpreted. So
every probe returns a `down` result instead, the service catches anything that
escapes anyway, and one badly-behaved probe does not stop the others running.

Failure details name the **exception class, never its message**. A PDO message
routinely carries the host, the port, the database name and sometimes the user,
and this response is one of the most reachable things the application serves.

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `HEALTH_TOKEN` | — | Gates probe **detail**, not the probe |
| `HEALTH_CACHE_TTL` | `0` (off) | Seconds to reuse a readiness result |
| `HEALTH_QUEUE_NAME` | `default` | Which queue the depth check reads |
| `HEALTH_QUEUE_MAX_DEPTH` | `0` (off) | Backlog over this is `degraded` |
| `HEALTH_PROBE_DATABASE` / `_CACHE` / `_QUEUE` / `_STORAGE` | `true` | Drop a probe entirely |
| `HEALTH_DEGRADED_AFTER_MS` | `0` | Override every probe's own slow threshold |

**The token gates detail, not access.** With one configured, `/health/ready`
still answers its status and HTTP code to anyone — a kubelet cannot carry a
bearer token, so gating the whole endpoint would mean choosing between a public
inventory of your infrastructure and having no probe at all. What is withheld is
the reconnaissance: which dependencies exist, which one is broken, and how slow
each is. `/health/{probe}` answers **404** rather than 401 without it, so an
unauthenticated prober learns nothing from the attempt. Comparison is
`hash_equals`, because this endpoint is designed to be hit repeatedly and `===`
leaks a prefix through timing.

**Caching is off by default.** A cached readiness answer is a stale one, and the
window during which an orchestrator keeps routing traffic to a broken instance
*is* the TTL.

**The queue depth ceiling is off by default**, and is `degraded` rather than
`down` when it fires. A deep queue means the workers are behind — a reason to
alert and to scale, not a reason to stop the HTTP instances accepting the very
work that would drain it.

## No route filters

This module declares none. A health endpoint behind the application's own auth
or throttle stages stops answering for exactly the reasons it exists to report —
and a throttled probe is a probe that fails under the load you most need to see.

## Testing

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel vendor/bin/phpunit
```

## Verified / not verified

**Verified**: 31 tests, 86 assertions. `ground check --strict` clean. Port
doubles drive every branch — healthy, slow, throwing, absent, and the cache that
accepts a write and loses it. Four behaviours confirmed load-bearing by
reverting them: liveness running no probes (2 failures), `degraded` staying 200
(1), the cache read-back comparison (1), and `worst()` not escalating on
`skipped` (3).

A fifth revert **passed**, and that was the useful one: `HealthReport::of()` was
also filtering `skipped` out before computing the status, and removing the filter
changed nothing — `worst()` already ignored it. The dead filter is gone and the
guarantee is now pinned where it actually lives.

**Not verified:**

- **No probe has run against real infrastructure.** No MySQL, no Redis, no S3,
  no queue. Whether `SELECT 1` answers is the database's business, but the
  *thresholds* — 250ms for a database, 500ms for storage — are guesses that have
  never been compared against a real latency distribution. Expect to tune
  `HEALTH_DEGRADED_AFTER_MS`.
- **No HTTP request has reached the controller.** `HealthController` is covered
  by reading, not by a request: the routes, the token extraction, the
  `no-store` header and the 404-not-401 behaviour have never been exercised
  through the pipeline. This is the largest gap.
- **Never run under an orchestrator.** The liveness/readiness split is the
  reason this plugin exists and no kubelet has ever called either route.
- **The readiness cache is per-process**, so under PHP-FPM each worker has its
  own and the effective probe rate is the TTL times the worker count. Untested
  under Swoole, where one process serves many requests and the cache is
  correspondingly more effective.
- **No concurrency.** Two replicas probing simultaneously is reasoned about in
  the cache probe's unique key, not observed.

## License

MIT — see [LICENSE](LICENSE).
