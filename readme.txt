=== Taxonomy Tidy ===
Contributors: shahidirfan100
Tags: taxonomy, categories, tags, terms, cleanup
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review duplicate and empty terms, preview bulk changes, and restore batches. Optionally redirect merged archives or return 410 for removed terms.

== Description ==

Taxonomy Tidy helps site owners and agencies review categories, tags, WooCommerce terms, and other registered taxonomies before cleaning them up.

The scan is read-only. It reports:

* Repeated term labels within the same taxonomy.
* Labels that differ only by case, spacing, punctuation, or accents.
* Terms with no assigned objects.

Select several duplicate groups, choose one canonical term for each group, and review all affected assignments, hierarchy changes, and archive URLs together before applying one batch. You can also select multiple empty terms for one reviewed removal batch. Each operation is written to a site-local audit log before WordPress updates term assignments. Activity can roll back the complete batch and restore the original term and taxonomy IDs, saved term metadata, relationships, and hierarchy. Rollback checks for conflicting later changes and stops rather than overwriting them. Activity also lets you resume recovery after an interrupted request; review any reported conflict before retrying.

The plugin works only with taxonomies available to the current user on the current site. It does not send term data to a remote service or add front-end assets. Merges can optionally create a permanent same-site redirect from each removed term archive to its canonical archive. Removing an empty term records an HTTP 410 Gone response for its former public archive. Both behaviors are saved with the audit operation and are removed when that operation is rolled back.

== Installation ==

1. Upload the `taxonomy-tidy` folder to `/wp-content/plugins/` or install the ZIP from the Plugins screen.
2. Activate Taxonomy Tidy.
3. Open Taxonomy Tidy in the dashboard and select a taxonomy to scan.
4. Select duplicate groups, choose the canonical term to keep in each group, and review the complete batch.
5. Select empty terms and review the removal batch before applying it.
6. Use Activity to export the audit report or roll back an applied batch.

== Frequently Asked Questions ==

= Does scanning change my terms? =

No. A scan reads registered terms and their assignment counts. It does not rename, merge, or remove anything.

= How are similar labels found? =

The conservative comparison ignores case, accents, spaces, and punctuation. It does not guess spelling mistakes or use fuzzy matching.

= What happens when I merge terms? =

You choose the term to keep. Taxonomy Tidy first adds that term to each affected object, then removes the source term. The operation is scoped to the selected taxonomy. The audit record is saved before the change.

= Can I undo a merge? =

Yes. Activity offers a rollback action for an applied batch. It restores the source term with its original IDs, metadata, object assignments, and hierarchy. If those IDs or terms have since been reused, rollback stops and reports the conflict.

= Can I redirect a merged term archive? =

Yes. Turn on the 301 redirect option in the batch review or make it the default in Settings. Each redirect stays on the current site, follows later term merges, and is removed when its audit operation is rolled back.

= What happens to an empty term's archive when I remove it? =

Taxonomy Tidy records a 410 Gone response for its former public archive URL. The site's normal not-found template still renders. Rolling back the removal removes the 410 rule and restores the term.

= Does this work with WooCommerce and custom taxonomies? =

It works with registered, admin-visible taxonomies that the current user can manage, including WooCommerce product taxonomies and public custom taxonomies. Each operation stays within one taxonomy.

= Where is the audit history stored? =

It is stored in local database tables for the current site. It remains available when the plugin is deactivated. By default, uninstall preserves it; an administrator can opt into deleting the local settings and history on uninstall.

== Screenshots ==

1. A live scan of registered taxonomies with duplicate groups and empty terms.
2. An impact review showing affected objects, hierarchy, and term archive URLs.
3. The local activity log with batch status and rollback controls.
4. Settings for taxonomy visibility, merge redirects, and uninstall data handling.

== Changelog ==

= 1.1.1 =
* Skip affected-object samples when preparing, reviewing, and applying bulk batches; single-change previews still show sample objects.
* Replace the slogan-like dashboard heading with a plain description.

= 1.1.0 =
* Select and review multiple duplicate groups in one preview; merge all non-canonical terms into the selected canonical term.
* Remove multiple empty terms in one reviewed, reversible batch.
* Add batch-level rollback, per-operation audit snapshots, and recovery for partially applied batches.
* Add optional same-site 301 redirects for merged archives and 410 Gone responses for removed empty-term archives.
* Add a Settings preference for the default merge redirect choice.

= 1.0.0 =
* Initial release with read-only scans, impact previews, merge, rename, empty-term removal, audit exports, and guarded rollback.

== Upgrade Notice ==

= 1.1.1 =
Bulk previews avoid loading sample objects that are not displayed, reducing unnecessary work for larger batches.

= 1.1.0 =
Bulk changes now use one reviewed, confirmed, and reversible batch. Merge redirects and removed-term 410 responses are recorded with the batch.
