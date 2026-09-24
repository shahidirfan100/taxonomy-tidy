# Taxonomy Tidy 1.1.0 — Release QA

Prepared: 2026-09-24

## Release artifact

- Install ZIP: `taxonomy-tidy-1.1.0.zip`
- Archive size: 44,154 bytes; SHA-256: `af014ee3027b40655172c5ce200f26c2304d5cb802f0b569cf94fb765bb280a8`.
- Contents: 14 install files under `taxonomy-tidy/`; development documentation, tests, and listing media are excluded.
- The exact ZIP was installed and activated in the validation site. Archive integrity, expected paths, source-file hashes, installed-file hashes, and plugin/readme version parity are recorded in `../dist/taxonomy-tidy-1.1.0-manifest.json`.
- Plugin header version, `TAXONOMY_TIDY_VERSION`, schema version, readme stable tag, and WordPress.org contributor `shahidirfan100` match the release metadata.

## Validation results

- WordPress Plugin Check 2.1.0 against the installed release ZIP: PASS, no errors found.
- PHP_CodeSniffer with `WordPress-Extra`: PASS, no findings.
- PHP syntax: all 10 plugin PHP files pass `php -l`.
- JavaScript syntax: `assets/js/admin.js` passes `node --check`.
- Runtime suite against the installed ZIP: PASS, all 23 checks on WordPress 7.1.2 / PHP 8.5.8 using SQLite integration. Coverage includes default-category protection, taxonomy-specific operations, impact fingerprints, assignment and hierarchy restoration, metadata preservation, conflict-safe recovery, the 500-term scan boundary, JSON export, 20 merge/rollback cycles, bulk compensation, and 410-route rollback.
- Lifecycle checks: PASS for deactivation/reactivation preserving audit data, default uninstall preserving data, and opt-in uninstall removing the local tables/settings followed by successful schema recreation.
- Live HTTP checks: a merged source archive returns 301 to the canonical archive; the canonical archive returns 200; a removed empty-term archive returns 410 with `X-Robots-Tag: noindex`.
- Live admin workflow: both bulk actions are disabled with no selection and enable after selecting rows. Merge and empty-term removal each open a consolidated impact review; neither action applies from selection or from the review button. Apply requires the separate, required confirmation checkbox. A one-term UI apply and rollback restored the original term and assignment.

## Listing media

- All four screenshot files are real captures of the running WordPress admin UI. They correspond to the four `readme.txt` screenshot captions. The dashboard capture shows selected duplicate groups with the Merge review action enabled and the Remove review action disabled while no empty term is selected. The Activity image shows an applied batch with its rollback control. The Settings image includes the merge redirect default.
- Banners: `banner-1544x500.png` (1544×500) and `banner-772x250.png` (772×250).
- Icons: `icon-128x128.png` (128×128), `icon-256x256.png` (256×256), and `icon.svg` with PNG fallbacks.
- Screenshots: 1 (1600×1700), 2 (1600×1050), 3 (1600×1100), and 4 (1600×900). Every asset is below the handbook's 10 MB screenshot limit. The assets remain outside the install ZIP.

## Prior review risks checked

- Default category safety and source-only assignment behavior pass the regression suite.
- Each scan/action is scoped to one registered taxonomy; custom-taxonomy scan and merge behavior pass.
- The dashboard explicitly labels the canonical term to keep and distinguishes bulk merge review from empty-term removal review.
- Slug conflicts, stale previews, source/target conflicts, recovery conflicts, and partial batch failure are covered by the runtime suite.
- The selection controls stage work only. A separate review and required confirmation stand between selection and mutation.

## WordPress.org submission boundary

- The publisher confirmed the WordPress.org username `shahidirfan100`; it is listed in `Contributors` and the plugin author fields.
- The final `readme.txt` was checked with the [official Readme Validator](https://wordpress.org/plugins/developers/readme-validator/) after metadata edits. It reports no missing contributor or upgrade notice; its only note is the optional absence of a donate link. No donation URL was supplied.
- The exact first-review notice was not available in the workspace. The known regression risks above were tested, but this report cannot claim a line-by-line response to an unseen review notice. Compare the original reviewer notice before submitting again.
- The previous exact-name/candidate-slug lookup was preliminary. A fresh direct lookup could not be confirmed in this run, and WordPress.org assigns the final slug during submission. This is not trademark clearance.
- No WordPress.org submission, SVN publication, or approval has occurred. This is a locally validated release candidate.

## Evidence limits

Runtime testing used WordPress 7.1.2 / PHP 8.5.8 with SQLite integration. MySQL/MariaDB, WordPress 6.4, PHP 7.4, and a real multisite network were not available, so compatibility and isolation on those environments were not established here. The PHP 8.5 WP-CLI process emits upstream CLI color-library deprecation notices; no plugin-related notice was emitted by the test suite, and Plugin Check reported no plugin errors.
