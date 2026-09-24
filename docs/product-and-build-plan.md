# Taxonomy Tidy — Build Plan

## Product identity and naming check

- Display name: Taxonomy Tidy
- Candidate WordPress.org slug and text domain: `taxonomy-tidy`
- PHP prefix / namespace: `taxonomy_tidy_` / `TaxonomyTidy`
- Version: 1.0.0 for the first complete release candidate
- License: GPL-2.0-or-later
- Scope of naming search (2026-09-23): exact-phrase web searches and WordPress.org plugin-directory searches found no matching plugin or software brand. The WordPress.org page and SVN path for `taxonomy-tidy`, plus the plugin information API, returned not found. This is a current preliminary slug check; the WordPress.org team assigns and confirms the permalink during review, and the web search is not a formal trademark clearance.

## Evidence of user pain

- A WordPress.org support report says a merge left neither original nor merged tag, with no log error; the user only discovered it on a local copy. This validates preview, durable pre-change snapshots, and a clear rollback path as core product behavior: <https://wordpress.org/support/topic/tag-merge-seems-to-simply-delete-both-tags/>.
- A TaxoPress report describes merge operations assigning posts to the default category as well; the maintainer traced it to WordPress adding the default category between source deletion and replacement. This directly informs operation ordering and a regression fixture where a post has only the source category: <https://wordpress.org/support/topic/caused-even-more-work/>.
- Another TaxoPress report shows same-named values across WooCommerce attribute taxonomies can be combined across taxonomy boundaries, damaging a store. Every suggestion and operation will therefore be scoped to one registered taxonomy and visibly name that taxonomy: <https://wordpress.org/support/topic/feature-request-merge-terms-inside-a-specific-product-attribute-taxonomy/>.
- A support exchange says users misunderstood which merge field was the source and which was the destination. The UI will use explicit “Keep this term” and “Merge these terms into it” labels, not ambiguous pair ordering: <https://wordpress.org/support/topic/merged-tags-being-deleted-issue/>.
- Existing WordPress.org tools cover bulk merging and taxonomy edits (Term Management Tools, 10,000+ installations in the supplied research). The product distinction is visible impact preview, retained pre-change state, batch audit history, and tested restoration rather than a general term spreadsheet editor: <https://en-gb.wordpress.org/plugins/term-management-tools/>.

## MVP acceptance scope

1. Discover registered taxonomies available to the current user; default to public, admin-visible taxonomies. Keep every action inside one taxonomy and one site in multisite.
2. Scan for empty terms, same-label duplicates, and conservative likely duplicates where labels differ only by case, spacing, punctuation, or accents. No speculative typo/fuzzy matching.
3. Show counts, sample affected objects and object types, hierarchy impact, and old/new term archive URLs before an edit.
4. Support merge into a clearly selected canonical term, safe rename, and explicit removal of empty terms. Preview is read-only; every applied operation records a batch ID and enough information to restore the term ID, taxonomy row, metadata, relationships, parent links, and original label/slug.
5. Roll back an applied batch after checking that restoration will not overwrite later edits. Report conflicts instead of forcing them.
6. Export audit summaries as CSV and JSON. Keep audit records local; no accounts, analytics, remote APIs, or front-end code.
7. Provide a focused dashboard, settings page, and activity/rollback page with accessible labels, keyboard operation, clear destructive-action language, responsive tables, and no external UI dependencies.
8. Use dynamic taxonomy capabilities, nonces on mutations, validated inputs, escaped output, prepared SQL, current-site table isolation, translation-ready strings, and conservative uninstall data handling.

## Implementation sequence and files

1. Build the WordPress plugin skeleton under `outputs/taxonomy-tidy/`: main bootstrap, `readme.txt`, GPL license, uninstall handler, admin and service classes, scoped admin CSS/JS, and user-facing help.
2. Add versioned per-site audit tables and schema setup. Store snapshots before writes; process a batch with compensating rollback on failure. Never describe the operation as a database transaction because WordPress hooks and storage engines can make that claim false.
3. Implement taxonomy discovery, bounded impact queries, duplicate/empty scan, dry-run preview, merge/rename/delete, audit export, and guarded rollback.
4. Build the dashboard, settings, and activity pages around the preview-first flow; test mobile and keyboard behavior. Avoid external assets or tracking.
5. Run the plugin in a clean WordPress environment with debug notices enabled, capture real plugin screens, and generate WordPress.org icon/banner and screenshots from the running UI. Keep WordPress.org listing assets outside the install ZIP.
6. Validate source and final ZIP: PHP and JavaScript syntax, WordPress coding standards, WordPress.org readme and headers, Plugin Check static/runtime, activation/deactivation/uninstall behavior, multisite isolation, merge/default-category regression, exact-ID rollback, metadata and hierarchy restore, CSV/JSON exports, screenshots/dimensions, archive layout, file inventory, and SHA-256.

## Prior WordPress.org submission lessons to enforce

- Main plugin file, directory, text domain, and candidate slug all use `taxonomy-tidy`; include complete version, WordPress/PHP requirements, GPL license headers, direct-access guards, and a valid readme.
- Re-check the current stable WordPress version at final packaging; WordPress.org currently lists 7.1.2 (released 2026-09-22), so the release target is not a stale beta/RC number: <https://wordpress.org/download/releases/>.
- Put listing screenshots, icons, and banners in a separate top-level WordPress.org `assets/` directory, never inside the plugin's `trunk/assets`; screenshot captions and filenames must match. Capture the actual running plugin UI.
- Keep Plugin Check, PHPCS/WordPress-Extra, PHP compatibility, runtime activation, and package parity as separate evidence. A clean Plugin Check result is not by itself a clean PHPCS result or proof of WordPress.org acceptance.
- Do not bundle third-party adapters or build artifacts that trigger unrelated standards findings. Check the final ZIP itself and record its exact inventory and hash.
- Do not claim a plugin is submitted or approved without a real WordPress.org account/reviewer result. This task prepares a reviewable package and evidence locally.

## Final review outcome — 2026-09-24

- The exact release ZIP installs and activates on WordPress 7.1.2 / PHP 8.5.8. The database in this environment is SQLite-backed; MySQL/MariaDB, WordPress 6.4, PHP 7.4, and a real multisite network were not available for runtime testing.
- The complete WordPress Plugin Check CLI run against the installed ZIP completed with no findings. PHP_CodeSniffer WordPress-Extra completed with exit code 0 and no findings after standards cleanup.
- The regression suite passed against the installed ZIP, including preview fingerprinting, built-in default-term protection, custom taxonomy behavior, relationship/hierarchy/metadata restoration, conflict-safe interrupted recovery, the 500-term page boundary, JSON export, and 20 repeated merge/rollback cycles.
- Lifecycle checks passed for deactivation, reactivation, default data preservation, opt-in uninstall deletion, and schema recreation after uninstall.
- The distributable ZIP has 13 files, uses forward-slash taxonomy-tidy/ paths, excludes development files and WordPress.org listing media, and matches its source file hashes. See ../dist/taxonomy-tidy-release-manifest.json and release-qa-1.0.0.md.
- Listing assets and four real WordPress admin screenshots remain in ../wordpress-org-assets/, separate from the plugin ZIP.
- The current WordPress.org username and the exact first-review notice were not available in the workspace when this report was prepared. Add the correct contributor/author metadata and compare the original notice before submission. The local slug lookup for taxonomy-tidy returned not found on 2026-09-24; WordPress.org assigns the final slug when the plugin is submitted, and this lookup is not trademark clearance.
- This is a locally validated release candidate, not a submitted or approved plugin. Keep the exact WordPress.org reviewer thread attached to the submission when addressing prior feedback.
