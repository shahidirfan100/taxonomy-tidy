=== Taxonomy Tidy ===
Tags: taxonomy, categories, tags, terms, cleanup
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review duplicate and unused terms before cleanup. Preview impact, log each change, and safely restore merges, renames, or removals.

== Description ==

Taxonomy Tidy helps site owners and agencies review categories, tags, WooCommerce terms, and other registered taxonomies before cleaning them up.

The scan is read-only. It reports:

* Repeated term labels within the same taxonomy.
* Labels that differ only by case, spacing, punctuation, or accents.
* Terms with no assigned objects.

Before a change, review the affected objects, parent and child terms, term archive URLs, and the canonical term to keep. Each change is written to a site-local audit log before WordPress updates term assignments. Rollback preserves the original term and taxonomy IDs, saved term metadata, relationships, and hierarchy. It checks for conflicting later changes and stops rather than overwriting them. Activity also lets you resume recovery after an interrupted request; review any reported conflict before retrying.

The plugin works only with taxonomies available to the current user on the current site. It does not send term data to a remote service, add front-end code, or create redirects. The archive URL impact is recorded in the audit report for review.

== Installation ==

1. Upload the `taxonomy-tidy` folder to `/wp-content/plugins/` or install the ZIP from the Plugins screen.
2. Activate Taxonomy Tidy.
3. Open Taxonomy Tidy in the dashboard and select a taxonomy to scan.
4. Review a finding, inspect its impact, and confirm the change only when the preview is correct.
5. Use Activity to export the audit report or roll back an applied batch.

== Frequently Asked Questions ==

= Does scanning change my terms? =

No. A scan reads registered terms and their assignment counts. It does not rename, merge, or remove anything.

= How are similar labels found? =

The conservative comparison ignores case, accents, spaces, and punctuation. It does not guess spelling mistakes or use fuzzy matching.

= What happens when I merge terms? =

You choose the term to keep. Taxonomy Tidy first adds that term to each affected object, then removes the source term. The operation is scoped to the selected taxonomy. The audit record is saved before the change.

= Can I undo a merge? =

Yes. Activity offers a rollback action for an applied batch. It restores the source term with its original IDs, metadata, object assignments, and hierarchy. If those IDs or terms have since been reused, rollback stops and reports the conflict.

= Does the plugin create redirects? =

No. The preview and export show the old and new archive URLs when they differ so you can plan redirects with your site's existing redirect tool.

= Does this work with WooCommerce and custom taxonomies? =

It works with registered, admin-visible taxonomies that the current user can manage, including WooCommerce product taxonomies and public custom taxonomies. Each operation stays within one taxonomy.

= Where is the audit history stored? =

It is stored in local database tables for the current site. It remains available when the plugin is deactivated. By default, uninstall preserves it; an administrator can opt into deleting the local settings and history on uninstall.

== Screenshots ==

1. A live scan of registered taxonomies with duplicate groups and empty terms.
2. An impact review showing affected objects, hierarchy, and term archive URLs.
3. The local activity log with batch status and rollback controls.
4. Settings for private taxonomy visibility and uninstall data handling.

== Changelog ==

= 1.0.0 =
* Initial release with read-only scans, impact previews, merge, rename, empty-term removal, audit exports, and guarded rollback.
