# Taxonomy Tidy 1.0.0 — Release QA

Prepared: 2026-09-24

## Release artifact

- Install ZIP: `taxonomy-tidy-1.0.0.zip`
- Size: 34,340 bytes
- SHA-256: `cd64afa5b0c2b147cd8b367c8fe1544e039969d32158b0767107221f464de433`
- Contents: 13 install files under `taxonomy-tidy/`; no development README, tests, or listing media.
- ZIP integrity, forward-slash paths, source hashes, and installed-file hashes all pass. The exact ZIP was installed and activated in the test site.
- The plugin header version and readme stable tag both equal `1.0.0`.

See `../dist/taxonomy-tidy-release-manifest.json` for the per-file sizes and hashes.

## Validation results

- WordPress Plugin Check 2.1.0: completed against the freshly installed ZIP with no findings.
- PHP_CodeSniffer using WordPress-Extra: exit code 0, no findings.
- PHP syntax: all 9 plugin PHP files pass `php -l`.
- JavaScript syntax: `assets/js/admin.js` passes `node --check`.
- Runtime suite: PASS on WordPress 7.1.2 / PHP 8.5.8, with SQLite integration. It covers read-only scans and pagination, custom taxonomies, exact and similar duplicate grouping, empty terms, preview fingerprints, assignment safety, default-category behavior, ID/metadata/hierarchy restoration, slug conflicts, interrupted apply/recovery, audit export, and 20 merge/rollback cycles.
- Lifecycle checks: PASS for deactivation and reactivation preserving audit data; default uninstall preserving data; opt-in uninstall deleting site-local data; and activation recreating the schema afterward.
- WordPress.org media: banner PNGs are 1544×500 and 772×250; icons are 128×128 and 256×256; four real admin screenshots are 1200×900, 1200×1200, 1200×900, and 1200×900. These assets are outside the install ZIP.

## Safety and UX cases covered

- Merge and rename previews show the selected taxonomy, affected objects, hierarchy effects, and archive URL changes before confirmation.
- Operations preserve the source term data needed for recovery. Recovery checks for later assignment or slug conflicts and stops instead of overwriting them.
- Interrupted renames and merges have explicit recovery states; regression checks cover recovery after a mutation but before its saved after-state.
- Source and canonical term choices use explicit labels. Every scan and action is scoped to one registered taxonomy on the current site.
- The known default-category reassignment scenario and repeated merge/rollback cycles pass the runtime suite.

## WordPress.org submission notes

- A current exact-name and candidate-slug check found no existing `Taxonomy Tidy` directory entry or `taxonomy-tidy` slug. This is only a preliminary directory check; WordPress.org derives the final slug from the main plugin name during submission and can raise a conflict or trademark concern during review.
- WordPress.org publisher identity was not supplied. The main plugin header therefore has no `Author`/`Author URI`, and the readme has no `Contributors` username. Do not invent these values; add the actual publisher details before final submission.
- The exact first-review notice was not present in the workspace. The known product safety/regression cases above were addressed, but this report cannot map them to the directory team's specific prior findings or claim those findings are resolved line by line. Keep the existing review email/thread and reply to it if this is an open review.
- No submission or approval has occurred. This is a locally validated release candidate, not a WordPress.org-approved plugin.

## Evidence limits

The runtime environment was WordPress 7.1.2 / PHP 8.5.8 with SQLite integration. It did not include PHP 7.4, WordPress 6.4, MySQL/MariaDB, or a multisite network. Compatibility with those declared minimums and multisite isolation was not established by runtime testing here. The test site's PHP CLI also emits deprecation notices from WP-CLI's own color library under PHP 8.5; Plugin Check reported no plugin findings.
