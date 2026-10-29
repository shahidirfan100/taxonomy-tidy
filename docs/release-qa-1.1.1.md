# Taxonomy Tidy 1.1.1 — Release QA

Prepared: 2026-10-29

## Release artifact

- Install ZIP: `taxonomy-tidy-1.1.1.zip`
- Archive size: 44,376 bytes; SHA-256: `34a6ffa58fd2dffb6cfcb845a7a8a0603918f75b2871e6c26b0072e5595dcdf5`.
- Contents: 14 install files under `taxonomy-tidy/`; development documentation and listing media are excluded.
- The ZIP, its source-file hashes, and its version metadata are recorded in `../dist/taxonomy-tidy-1.1.1-manifest.json`.
- Plugin header, `TAXONOMY_TIDY_VERSION`, and readme Stable tag are `1.1.1`. Schema version stays at `1.1.0` because this release makes no schema changes.

## Validation results

- WordPress Plugin Check 2.1.0: PASS against a clean package built from the plugin files, with the action's strict mode enabled. The result artifact reports: “Success: Checks complete. No errors found.” [GitHub Actions run](https://github.com/shahidirfan100/taxonomy-tidy/actions/runs/40829639359).
- PHP syntax: PASS on PHP 7.4 and PHP 8.5 in GitHub Actions.
- JavaScript syntax: `assets/js/admin.js` passes `node --check` in GitHub Actions.
- WordPress runtime smoke check: PASS; bulk preview omits object samples, single preview retains them, and both preview modes produce the same impact fingerprint.
- Package integrity: PASS for ZIP integrity, the single expected top-level directory, all 14 files, archive hash, source-file hash parity, and plugin/readme version parity.
- Brand: plugin name and existing primary colors (`#155b45`, `#104735`) are unchanged.

## Changes covered by this release

- Bulk prepare, review, and apply paths no longer fetch up to eight sample objects per selected term when the bulk interface does not display those samples. Single-change previews still include them.
- The dashboard heading uses a plain description in place of the slogan-like line.
- Schema version remains unchanged.

## WordPress.org submission boundary

- The 1.1.1 source is merged to `main` in commit `a6f64fd70b8ec6ec6256991c88ed486ba9fd30ff`. The WordPress.org SVN tree still has Stable tag `1.1.0`; the manual release workflow will update `trunk` and add `tags/1.1.1` after SVN credentials are configured in the `wordpress.org` GitHub Actions environment.
- Plugin Check, syntax checks, and the focused smoke check passed for this candidate. This run does not repeat the full runtime integration suite or a separate PHPCS run; the successful 1.1.0 results do not validate the changed 1.1.1 source beyond the smoke check above.
- WordPress Plugin Check reports findings from its configured checks but does not itself guarantee WordPress.org approval. Review the candidate and WordPress.org submission requirements before publishing.
