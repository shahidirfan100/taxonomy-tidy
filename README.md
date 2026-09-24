# Taxonomy Tidy

Taxonomy Tidy is a preview-first WordPress term cleanup plugin. It scans registered taxonomies for empty terms, repeated labels, and formatting variants; previews impact; records changes locally; and offers a guarded rollback.

## Safety model

- Scans are read-only and scoped to taxonomies available to the current user.
- Every change requires a separate preview and a checked confirmation.
- Select multiple duplicate groups or empty terms and inspect one consolidated impact preview before applying a batch.
- The preview is fingerprinted and rejected if its source assignments or hierarchy changes before application.
- A durable audit record is written before each mutation.
- Each batch can be rolled back together; interrupted partial batches retain an Activity recovery action.
- Merges add the chosen canonical term before deleting the source, which avoids a default category being inserted during category cleanup.
- Merge redirects are optional, same-site 301 rules; removed empty-term archives return HTTP 410 Gone. Both URL rules are removed when the associated operation is rolled back.
- Rollback restores saved term and taxonomy IDs, metadata, relationships, and parent links. It refuses a conflicting reused ID.
- All data remains local to the current site; no analytics, accounts, or remote services are used.

## Supported operations

- Merge all non-canonical terms in selected duplicate groups into the chosen term in the same taxonomy.
- Remove multiple empty terms in one reviewed batch.
- Rename a term after reviewing affected objects and its prospective archive URL.
- Remove a term only when its assignment table is empty.
- Export the audit summary to CSV or JSON.

Similarity detection compares case-, accent-, whitespace-, and punctuation-normalized labels. It does not infer typos.

## Development

The plugin uses WordPress APIs, core admin libraries, PHP 7.4+, and per-site audit tables. Start with `taxonomy-tidy.php`; implementation is in `includes/`. WordPress.org listing assets are prepared separately from the install ZIP.

## License

GPL-2.0-or-later.

## Release files

- Version 1.1.0 install ZIP: [dist/taxonomy-tidy-1.1.0.zip](dist/taxonomy-tidy-1.1.0.zip).
- Release inventory and SHA-256 manifest: [dist/taxonomy-tidy-1.1.0-manifest.json](dist/taxonomy-tidy-1.1.0-manifest.json).
- Release QA and product plan: [docs/](docs/).
- WordPress.org listing graphics and real admin screenshots: [wordpress-org-assets/](wordpress-org-assets/).
- The install ZIP intentionally contains only the plugin files; the listing assets and release documents stay outside it.
