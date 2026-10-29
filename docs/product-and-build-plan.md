# Taxonomy Tidy — Product roadmap and MVP plan

Updated: 2026-10-29

## Product position

Taxonomy Tidy 1.1.1 already implements the core cleanup workflow: read-only scans, reviewed merge/rename/removal actions, durable local audit records, batch rollback, and optional archive responses. The WordPress.org listing is approved, per the maintainer. This roadmap focuses on making the existing workflow more thoroughly tested and dependable on larger sites before adding broad new features.

Keep the product name, slug, namespace, text domain, and established green colors (#155b45 and #104735). Keep the product local to the site: no accounts, analytics, telemetry, remote AI, or external service calls. Every mutation remains manual, previewed, confirmed, and recoverable.

## What 1.1.1 already does

- Scans manageable, admin-visible registered taxonomies for empty terms and exact or formatting-only duplicate labels.
- Keeps operations inside one site and one taxonomy, and uses the taxonomy’s capabilities for changes.
- Previews assignment counts, sample objects for single changes, hierarchy changes, and archive URLs before applying.
- Supports rename, merge into an explicitly chosen canonical term, and removal of empty terms.
- Rechecks preview fingerprints before applying; writes local audit state before mutation; preserves partial-batch recovery and conflict-aware rollback.
- Optionally records same-site 301 redirects for merged archives and HTTP 410 responses for removed empty-term archives.
- Exports audit summaries as CSV or JSON. No term data is sent off-site.

## Code review findings that shape the roadmap

| Area | Current state | Next improvement |
| --- | --- | --- |
| Correctness evidence | Current Actions run syntax checks, strict Plugin Check, and one focused bulk-preview smoke check. Core apply, rollback, failure recovery, upgrades, and multisite are not covered by automated tests in the repository. | Bring the core behavior into repeatable WordPress integration tests and CI before adding new mutation paths or detection modes. |
| Compatibility | The plugin declares WordPress 6.4 and PHP 7.4 minimums. Actions lint PHP 7.4 and 8.5, but syntax checks do not prove runtime compatibility. | Test the declared minimum and the current stable WordPress/PHP versions at runtime; include MySQL and MariaDB coverage. |
| Taxonomy scans | Scanner reads 500 rows at a time with OFFSET, but retains a key for each unique normalized term name for the duration of the scan. It materializes up to 100 groups and 100 empty terms; the UI already tells users when those result lists are capped. | Benchmark large taxonomies, then reduce offset and memory costs without changing the conservative match rules or hiding capped results. |
| Merge cost | The batch limit is 100 term operations, not 100 affected assignments. Snapshot creation checks target membership once per related object and writes one journal row per relationship; merge then calls WordPress term APIs per affected object. A term with many assignments can therefore make one admin request long even in a small batch. | Measure query count, time, and memory by assignment count. Batch the membership checks and add a measured workload guard. Use resumable processing only if benchmarks show synchronous requests are unsafe. |
| Audit growth | Activity loads the latest 50 batches without pagination. Export loads all operations and serialized snapshots into memory before producing output. | Add keyset pagination and stream exports so retained rollback history does not make the admin or export request grow without bound. |
| Failure reporting | Schema installation returns a success value, but the upgrade hook does not inspect it or expose a clear admin health message. | Report missing or failed schema setup clearly and make recovery safe and repeatable. |
| Front-end 404 path | Redirect rules are queried only on front-end 404 GET/HEAD requests, using an indexed path hash. Sites with no matching rule still pay for that lookup. | Measure the cost; optimize only if 404 traffic makes it material, while retaining correct rule invalidation and redirect behavior. |

The 1.1.0 QA document records extensive local checks, but the current GitHub workflow does not reproduce that full suite. Keep those results as historical evidence; do not treat them as automated coverage for later code.

## Recommended next MVP: reliability first

The current feature set is sufficient for a useful core product. The next MVP should prove that it continues to behave correctly across supported WordPress environments and larger taxonomies. Do not expand into automatic cleanup or fuzzy matching yet.

### Stage 1 — Make core behavior a CI release gate

Add a repeatable WordPress integration suite and run it in GitHub Actions. Cover:

- Install, activation, schema upgrade from 1.1.0, deactivation, reactivation, and opt-in uninstall; confirm default uninstall preserves audit data.
- Exact and formatting-only duplicate detection, result caps, custom taxonomy capabilities, private taxonomies, and non-English labels.
- Merge, rename, and empty-term removal; default-category protection; assignment preservation; child hierarchy changes; term metadata; and same-taxonomy isolation.
- Stale or expired previews, missing capabilities, invalid nonces, failed writes, interrupted apply, partial-batch recovery, rollback conflicts, and exact-ID restoration.
- 301, 410, query-string normalization, same-site restriction, rollback removal of route rules, and behavior when no saved route exists.
- CSV and JSON export, including spreadsheet-formula safety for untrusted term labels and URLs.
- Multisite table isolation and uninstall behavior.

Add WordPress-Extra PHPCS to CI. Test runtime behavior at WordPress 6.4 and the current stable release, on PHP 7.4 and a current supported PHP 8.x release, with MySQL and MariaDB. Keep Plugin Check, coding standards, syntax checks, runtime tests, and ZIP/package verification as separate checks.

### Stage 2 — Measure and improve scale

Before changing algorithms, add repeatable fixtures for 1,000, 10,000, and 50,000 terms, plus merges with increasing assignment counts. Record wall time, peak memory, and database queries for scan, preview, apply, rollback, Activity, export, and the 404 route lookup. Set pass limits from the checked-in CI runner baseline before optimizing; avoid hardware-independent time claims.

Then:

1. Replace OFFSET paging with a stable keyset cursor. Keep duplicate comparison conservative. Avoid retaining full term objects for all unique names; choose a compact index or temporary per-scan storage only after the benchmark shows which is needed.
2. Batch target-membership checks for relationship snapshots. Keep WordPress APIs for object-term mutations so registered hooks still run. Verify that the saved journal remains complete before any mutation starts.
3. Set a maximum safe workload based on affected assignments as well as operation count. Explain the limit during review and leave terms unchanged when it is exceeded.
4. Make Activity and exports paginated/streamed. Never prune applied rollback records automatically; any future retention control must show what becomes non-restorable and require explicit administrator action.
5. If a measured operation still exceeds safe request limits, add resumable progress with idempotent steps and a clear recovery action. Do not add a background queue preemptively.

### Stage 3 — Improve operator workflow

After the reliability and scale gates pass:

- Add filters and pagination to Activity, with status, taxonomy, and date filters.
- Add a read-only rollback preflight that lists conflicts before the user confirms restoration.
- Add scan progress and cancellation if large-taxonomy tests show scans exceed a safe request duration.
- Verify keyboard-only use, screen-reader announcements for selection and progress, responsive tables, and status indicators that do not rely on color alone.

Keep the current admin design and brand palette. Avoid a React rewrite or a new design system unless a demonstrated product requirement needs it.

## MVP options

| Option | Scope | Recommendation |
| --- | --- | --- |
| A. Reliability-first | Stage 1 plus measured Stage 2 fixes. No new cleanup modes. | Recommended. It reduces risk in the code paths that can change site data and gives future performance work a baseline. |
| B. Convenience-first | Stage 1, then Activity filters, rollback preflight, and scan progress. | Do after A; the interface improvements are useful, but they do not replace mutation and recovery tests. |
| C. Expanded workflows | Read-only WP-CLI scan/export, saved ignore rules, or other agency workflow tools. | Evaluate later from support requests. Keep any initial CLI commands read-only; require the same preview and confirmation safeguards before considering mutations. |

## Keep out of the MVP

- Fuzzy or typo-based automatic matches. They can merge terms users intended to keep separate.
- Automatic or scheduled merges/deletions. The product’s safety model depends on review and confirmation.
- Cross-taxonomy operations. Same names can mean different things in categories, product attributes, and custom taxonomies.
- Cloud accounts, remote analytics, remote AI suggestions, or telemetry. If a future service is proposed, it needs a real user benefit, explicit consent, clear readme disclosure, and a separate privacy review.
- Automatic audit pruning that removes the only rollback record.

## Next-release acceptance gate

A future code release is ready only when:

1. The integration matrix passes at the declared minimum and current stable WordPress/PHP versions, on MySQL and MariaDB.
2. Plugin Check and WordPress-Extra pass; the test suite covers apply, rollback, recovery, exports, routes, upgrade, and uninstall.
3. Large-data benchmarks publish their fixture sizes, query counts, peak memory, and measured budgets; a batch above the safe workload limit makes no changes.
4. Scan caps and failure states are visible. Schema errors are actionable. Rollback stops on conflicts and does not overwrite later user changes.
5. The install ZIP matches source and manifest, the readme and plugin headers agree on version, and listing assets remain outside the install package.
6. Plugin name, slug, and brand colors remain unchanged.

## WordPress.org references

- [Plugin Developer Handbook](https://developer.wordpress.org/plugins/)
- [WordPress security principles](https://developer.wordpress.org/apis/security/)
- [WordPress.org detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [WordPress.org common review issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WordPress plugin testing guide](https://make.wordpress.org/core/handbook/testing/automated-testing/writing-phpunit-tests-for-wordpress/)

Maintain the existing source-first release process. Update the plugin version and WordPress.org stable tag only when code for the next release passes this gate; keep SVN publishing manual and verify the committed trunk and version tag afterward.
