# Changelog

All notable changes to `hkm-plugin-health` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: HTTP liveness and readiness probes.** `GET /health`,
  `GET /health/ready`, `GET /health/{probe}`. Solves `health.probes` and
  declares no `requires[]`, so it installs into any application — a port that is
  not bound produces a `skipped` probe rather than a boot failure.

- **Liveness and readiness are separate, which is the point.** An orchestrator
  **restarts** a container that fails liveness and merely **de-registers** one
  that fails readiness, so a liveness probe pointed at the database makes
  Kubernetes kill every replica during a database blip — adding a restart storm
  to an outage a restart cannot fix, and leaving no warm capacity for the
  recovery. `/health` therefore touches nothing external and its answer is
  proven by the response arriving at all.

- **`Degraded` answers 200.** A slow dependency is worth alerting on, but
  removing the instance from the load balancer takes capacity away exactly when
  the remaining instances are already struggling.

- **`Skipped` is neither up nor down.** Reporting an unbound dependency as `up`
  is a lie an operator acts on; reporting it as `down` makes every application
  without a queue permanently unhealthy. The row is still reported so "fine" is
  distinguishable from "nobody wired it".

- **The cache probe writes, reads back and deletes.** The failure it exists to
  catch is a cache that *accepts* a write and cannot return it — a full Redis
  evicting instantly, a read-only replica, a bad cluster slot. Those answer a
  read with a miss, indistinguishable from a cold cache, so a read-only probe
  calls them healthy while every cached value evaporates. The key is unique per
  check so two replicas cannot read each other's and mask it.

- **Nothing else writes.** The storage probe is a read (`exists()` on a random
  reserved path, where `false` is a pass because the question is whether the
  backend answered); the queue probe reads `size()` rather than pushing a job a
  real worker would handle; the database probe is `SELECT 1` and does not touch
  a table, check migrations or measure replication lag. These run from every
  replica every few seconds, and `DocumentStorePort` is not probed at all
  because Firestore bills every read.

- **A probe never throws**, and the service catches anything that escapes
  anyway. A 500 is the one response an orchestrator cannot interpret. Failure
  details name the exception **class**, never its message, because a PDO message
  carries the host, port, database and sometimes the user — and this is one of
  the most reachable responses the application serves.

- **`HEALTH_TOKEN` gates detail, not access.** The status and HTTP code always
  answer, because a kubelet cannot carry a bearer token and gating the whole
  endpoint would mean choosing between a public inventory of the infrastructure
  and no probe at all. `/health/{probe}` answers **404** rather than 401 without
  it. Compared with `hash_equals`.

- **Caching and the queue-depth ceiling are both off by default.** A cached
  readiness answer is a stale one and the TTL *is* the window in which traffic
  keeps reaching a broken instance; a depth ceiling chosen without knowing the
  traffic turns a normal burst into a page.

- **`Cache-Control: no-store` on every response.** A proxy holding a 200 keeps a
  dead instance in the load balancer for the length of the cache, with the
  dashboard green throughout.

### Fixed during development

- **A dead filter that looked like a guarantee.** `HealthReport::of()` filtered
  `skipped` results out before computing the overall status — and a revert check
  proved it did nothing, because `HealthStatus::worst()` already escalates only
  on `down` and `degraded`, and an all-`skipped` set reached the same `up`
  either way. The filter is removed and the guarantee is pinned where it
  actually lives, since code that looks like a guarantee and provides none is
  how the real one stops being noticed.

- **The `CachePort` test double was wrong against the real interface.**
  `lock()` takes three parameters, not two, and `restoreLock()` was missing
  entirely — which would have been a fatal the first time the double was
  constructed. Found by reading the port definition rather than by running the
  tests.
