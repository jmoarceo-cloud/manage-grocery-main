# Performance implementation and measurements

Updated 2026-10-26. These are local build measurements, not load-test results.

Original PHP recovery measurement, later on 2026-10-26: shared MariaDB system
table corruption stalled its greeting, blocking PHP before HTML output. After
repair, `Invoke-WebRequest` measured `/project/index` HTTP 200 in 0.361 seconds
and `/project/catalog` HTTP 200 in 0.344 seconds. Chromium confirmed visible
homepage content and DOMContentLoaded in 0.641 seconds. These single local
observations are not percentiles, full asset-load timings or a load test.
Three synthetic stalled-greeting probes now stop in 3.12/3.08/3.07 seconds.
Public mysqlnd network reads are limited to three seconds, including queries;
POS/HR-only network-read settings are unchanged. Earlier outage measurements
below describe the pre-repair state, not current availability.

| Artifact | Before frontend cleanup | After cleanup |
|---|---:|---:|
| Register/application JavaScript | 59.20 kB / gzip 22.13 kB | 7.66 kB / gzip 2.84 kB |
| Application CSS | 69.88 kB / gzip 16.44 kB | 67.25 kB / gzip 16.12 kB |

Removed the unused Axios/bootstrap import and dependency because the native
register uses fetch. Tailwind automatic scanning is disabled; explicit Blade,
application JavaScript and pagination sources remain. `bin/npm.cmd run build`
with Vite 7.3.7 and Node 24.22.1 produced the smaller assets in 294 ms on one
local run. Timing varies with cache/host; this is not an end-user latency claim.

Catalog and staff tables use bounded pagination; public/POS lookup returns
only allowed fields and at most 12 lookup results. Availability uses aggregated
batch subqueries rather than one batch query per card. Product images use
fixed product IDs, MIME checks and local delivery. Current index contracts are
preserved; no speculative indexes were added to live business tables.

Checkout locks products in ascending ID order and uses a single transaction
with up to three deadlock attempts. Durable request keys prevent duplicate
sales. Separate-process tests verify contention for the last unit and an
identical simultaneous retry. These are correctness checks, not throughput
benchmarks. Stock-log failures have an explicit rollback test.

Unmeasured: peak concurrent registers, slow SQL plans, cold/warm HTTP percentiles,
memory/OPcache configuration, large product-option lists, long date ranges,
production mail latency and all advanced reports. The shared MariaDB failure
currently prevents a fresh live latency measurement. Do not infer a capacity
target, server tuning result or deployment readiness from bundle size.

Outage-only measurement: the native live home returned HTTP 503 in 12.24 seconds with the shared DB stopped. A direct PDO attempt with the configured timeout failed in 3.013 seconds; framework retries extend full-request duration. No normal-traffic latency baseline is inferred.

Manual-approval checkpoint: current assets are CSS41.43 kB (gzip10.14) and JS10.36 kB (gzip3.53). The earlier table records the cleanup stage; removing the unused skeleton welcome view further reduced Tailwind output, while the request/approval selector added useful JavaScript. Latest local build took 152 ms. No extra dependency or speculative DB index was added.

Label checkpoint: CSS43.95 kB (gzip10.63), JS10.53 kB (gzip3.58), Vite151 ms on one local run. Label pages query up to96 active products without per-product DB queries; QR generation is local PHP/SVG. The decoder is reused only in tests and adds no production bundle. No mass96-label CPU, physical-print or normal-load benchmark is claimed.

Verified-facts checkpoint: comparison loads at most100 ID/name choices and three
selected fact rows. Product details use a fixed bounded facts/related query set,
without per-related-product fact queries. Known default.png placeholders avoid
image HTTP/DB lookups. Current build: CSS46.17 kB/gzip11.06, JS10.67 kB/gzip3.61;
Vite155 ms on one local run. No peak-traffic or external-source latency is claimed.

Account-security checkpoint: CSS46.53 kB/gzip11.14 and JS11.00 kB/gzip3.72;
Vite560 ms on one local run. Password hashing runs only after valid credential
proof and policy checks; failed proofs have a five-attempt/15-minute account
limit. Same-server password transactions lock indexed usernames in fixed domain
order. Hash latency and concurrent identity workload are not benchmarked.

Public-code checkpoint: at most two existing-index ID lookups, no aggregate
availability/HR query before redirect, and no catalog query on blank/invalid
input. CSS47.48 kB/gzip11.32, JS11.00 kB/gzip3.72; Vite171 ms on one run. With
separate startup recovery complete, single public requests returned original
home200/352 ms, native home200/335 ms and lookup200/382 ms. These are availability
samples, not latency percentiles, throughput or an authenticated workload test.

Product-discount checkpoint: CSS 47.65 kB/gzip 11.35, JS 11.00 kB/gzip 3.72;
one 170 ms Vite run. Choices are bounded to 100 active products and history to
20 rows/page. No speculative index was added to the old request table (currently
primary-key only); pending-product/status scans and large history need query-plan
measurement. Transaction locks preserve native decision correctness, without a
claim about throughput, all legacy concurrency or unbounded request histories.

Information-review checkpoint: CSS 48.79 kB/gzip 11.53, JS 11.49 kB/gzip 3.89;
one 157 ms build. The product/detail lookup is fixed, recent action output is
limited to ten rows and only latest AI extraction context is inspected. Revision
hashing is in memory and adds no schema/version query. Existing large AI audit
payloads and high-volume review histories have not been benchmarked; no new index
or throughput claim is made. Dirty-form state stays in memory without network
polling, storage writes or camera/source requests.

Request-boundary checkpoint adds no frontend bytes or dependency. Invalid path IDs stop before controllers; invalid restock/supplier IDs skip exists queries via bail. Six regression cases cover these boundaries; their query assertions use cached synthetic actors and do not eliminate normal production authentication reads. No load/throughput benchmark is inferred.

Own-profile queries project only required employment/personal fields. Each write
checks the current account/employee under one transaction and uses a candidate-
email advisory lock (maximum3s acquisition wait), without a new index or whole
table materialization. Existing email duplicate lookup can still scan; no live
latency/load claim. Current Vite build: CSS49.90kB (gzip11.71), JS11.83kB
(gzip3.99),163ms on this workstation. These are compiler reports, not network
or production performance measurements. The full synthetic PHP suite takes
~25.13s and ten Chromium scenarios ~48.4s; neither is a throughput benchmark.

Own schedule makes one eligibility projection and two bounded HR schedule queries, in addition to ordinary authentication reads; it avoids the legacy company-roster per-employee query loop. Existing employee/day and covering/date indexes are preserved. No load/latency claim. Current Vite compiler reports CSS52.64kB/gzip12.17 and JS11.83kB/gzip3.99,461ms; these are build figures rather than network measurements.

Own leave reads projected own eligibility/three credit types and20 history rows with pagination; it does not initialize credits on GET. Date ranges use the existing employee index plus date predicates; no new index or measured live latency claim. Native writes lock one employee, use bounded existence/latest-row vacation queries and avoid iterating over every leave date. Vite reports CSS54.57kB/gzip12.50, unchanged JS11.83kB/gzip3.99 and171ms build time; these are compiler metrics, not production load evidence.

Leave queues apply SQL role/team scope before20-row pagination, using one grouped requester-role projection instead of per-record role lookups. Existing employee/team/credit indexes remain; no new index or live load claim. Write locks serialize one employee credit decision, with normal transaction retries. Vite compiler reports CSS54.73kB/gzip12.53 and JS11.83kB/gzip3.99,172ms; full synthetic PHP ~29.06s and eleven browser scenarios ~58.42s are verification durations, not production throughput.

Own overtime batches attendance for at most20 own request dates and one seven-weekday shift read, avoiding the legacy per-request evidence query loop. The original overtime_requests table still has only its primary key; employee/date filters may scan, and no load/latency improvement is claimed from the batching alone. Vite reports CSS55.16kB/gzip12.59 and JS11.83kB/gzip3.99,178ms; compilation and synthetic suite durations are not network or production throughput measurements.

Overtime approval queues apply team SQL scope before20-row pagination and batch exact authorized attendance pairs plus weekday shifts, avoiding per-request evidence queries. Existing overtime primary-key-only indexing is unchanged; filtered scans remain possible. Write locks serialize each request and its displayed evidence without recalculating payroll. Vite asset bytes are unchanged (CSS55.16kB/gzip12.59, JS11.83kB/gzip3.99);323ms compile and~50.12s full synthetic PHP duration are workstation checks, not production load measurements.

## Original-presentation compatibility, 2026-10-26

Original UI restoration adds separate home/login/catalog Vite entries. Shared
font files are 176,288 bytes (Plus Jakarta Sans) and 360,440 bytes (Fraunces),
cached locally as content-hashed build assets. The original catalog displays
all matching results rather than the earlier native 18-row pagination; this
preserves source behavior but may increase large-catalog payloads. Four bounded
catalog queries provide products, categories, total and grouped counts; image
resolution checks allowlisted local files per displayed product. Home uses the
original two-query random-products/categories contract. No production-load or
live performance improvement is inferred from synthetic browser success.

## Original product assets

The source-style product Vite build outputs12.12kB CSS (3.02kB gzip) and0.62kB JS
(0.34kB gzip), plus the shared theme chunk/fonts. These are build sizes, not a
production timing claim. Native detail queries and up to four related products
remain; empty categories now follow original related selection. Local image path
checks remain bounded to public allowlists. No live load/throughput test ran.

## Original comparison assets

Comparison Vite output is7.22kB CSS (2.12kB gzip),0.62kB JS (0.34kB gzip), plus
the shared theme chunk; it loads no web-font file, matching the source. The
existing verified-data query/100-choice and three-product bounds remain. Cell
formatting scans at most three values per numeric row. These are build/bounds
observations, not live latency, throughput or complete performance-parity proof.
