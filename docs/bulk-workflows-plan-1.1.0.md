# Taxonomy Tidy 1.1.0 implementation plan

## User-requested outcome

Let an administrator select several duplicate groups or empty terms, inspect one consolidated impact preview, apply the reviewed changes as one auditable batch, and roll that whole batch back from Activity. A merge can optionally redirect the removed term archive to the canonical archive. Removing an empty term records a `410 Gone` response for its former public archive URL.

The attached WordPress dashboard screenshot is a visual reference for bulk selection and action placement. It contains no implementation instructions.

## Plan

1. **Schema and route records** — upgrade the per-site schema to 1.1.0; add durable redirect/410 route records tied to operation IDs and term-taxonomy IDs. Preserve existing data and include the new table in opt-in uninstall only.
2. **Bulk selection and review** — make each duplicate group select its canonical term and include every other group member as a source; let users select empty terms. Keep each action bounded to 100 operations, explain when scan results are capped, disable each bulk review action until its selection is non-empty, and render one consolidated impact preview before mutation. Selection alone never changes terms.
3. **One audit batch** — bind previews to the current user, taxonomy, term IDs, impact fingerprints, redirect choices, and scan report; recheck on apply. Save every operation snapshot before mutating. Store all actions under one batch UUID, compensate a failing action, retain recovery state for earlier applied actions, and use the existing reverse-order rollback checks for the whole batch.
4. **URL behavior** — offer an explicit 301 redirect choice for merge sources, disabled by default unless the administrator changes the preference. Create 410 records for removed public empty-term archives. Resolve requests only on front-end 404 GET/HEAD requests, match normalized same-site paths, avoid redirect loops, and remove route records as each operation is restored.
5. **Accessible dashboard and settings** — keep the existing brand palette and table layout; add labeled group/term checkboxes, select-visible controls, selected counts, batch review actions, URL behavior controls, and readable preview totals. Ensure keyboard operation and narrow-screen behavior.
6. **Regression and release validation** — test single-operation compatibility, group-to-canonical merge, many-source merge, empty-term batches, stale/forged previews, interrupted/failed batches, full rollback, route status/redirect behavior, upgrade/uninstall, exports, Plugin Check, PHPCS, syntax, exact ZIP inventory, and source/package hash parity. Bump headers, schema, readme, QA report, and ZIP to 1.1.0 only after validation.

## Prior review and known issue register

- The project notes list prior WordPress.org risk cases: reviewer metadata must be real, WordPress.org slug/name approval is not guaranteed, category merges must avoid WordPress default-term reassignment, operations must stay within one taxonomy, source and destination labels must be unmistakable, and reviewer approval must never be implied without evidence.
- The publisher confirmed the WordPress.org username `shahidirfan100`, which is now listed in the readme and plugin author metadata. The exact first-review notice is not present in the current workspace, so this implementation cannot claim a line-by-line resolution of that unavailable notice. Reconcile it before submission.

## Completion evidence

Version 1.1.0 is packaged and validated against WordPress 7.1.2 / PHP 8.5.8 with SQLite integration. The exact ZIP passes Plugin Check, WordPress-Extra PHPCS, runtime, lifecycle, route HTTP, and admin UI checks. The batch buttons stay disabled until selection, and both actions open a review screen before the separate required confirmation. The full test matrix, media audit, exact archive hash, and remaining unavailable reviewer-note gap are recorded in `release-qa-1.1.0.md` and `../dist/taxonomy-tidy-1.1.0-manifest.json`. This local validation does not mean the plugin has been resubmitted or approved.
