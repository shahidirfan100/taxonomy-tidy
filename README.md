# Taxonomy Tidy

Taxonomy Tidy is a preview-first WordPress term cleanup plugin. It scans registered taxonomies for empty terms, repeated labels, and formatting variants; previews impact; records changes locally; and offers a guarded rollback.

## Safety model

- Scans are read-only and scoped to taxonomies available to the current user.
- Every change requires a separate preview and a checked confirmation.
- The preview is fingerprinted and rejected if its source assignments or hierarchy changes before application.
- A durable audit record is written before each mutation.
- Merges add the chosen canonical term before deleting the source, which avoids a default category being inserted during category cleanup.
- Rollback restores saved term and taxonomy IDs, metadata, relationships, and parent links. It refuses a conflicting reused ID.
- All data remains local to the current site; no analytics, accounts, or remote services are used.

## Supported operations

- Merge a source term into an explicitly selected term in the same taxonomy.
- Rename a term after reviewing affected objects and its prospective archive URL.
- Remove a term only when its assignment table is empty.
- Export the audit summary to CSV or JSON.

Similarity detection compares case-, accent-, whitespace-, and punctuation-normalized labels. It does not infer typos.

## Development

The plugin uses WordPress APIs, core admin libraries, PHP 7.4+, and per-site audit tables. Start with `taxonomy-tidy.php`; implementation is in `includes/`. WordPress.org listing assets are prepared separately from the install ZIP.

## License

GPL-2.0-or-later.

## Release files

- Version 1.0.0 install ZIP: [dist/taxonomy-tidy-1.0.0.zip](dist/taxonomy-tidy-1.0.0.zip).
- Release QA and product plan: [docs/](docs/).
- WordPress.org listing graphics and real admin screenshots: [wordpress-org-assets/](wordpress-org-assets/).
- The install ZIP intentionally contains only the plugin files; the listing assets and release documents stay outside it.
