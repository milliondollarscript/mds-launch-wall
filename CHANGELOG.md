# Changelog

## 0.2.2 - 2026-10-21
- Corrected the version the plugin reports, so its listings, its assets, and its built-in documentation all name the installed build.

## 0.2.1 - 2026-10-21
- Tested with WordPress 7.1.3 and WooCommerce 11.2.1, and the plugin states the exact versions it was tested with, so WordPress and WooCommerce no longer report it as untested on a current site.
- Added opt-in uninstall cleanup through the core extension policy for extension-owned walls, submissions, settings, and transients.

## 0.2.0 - 2026-07-13

- Open existing walls on the Submissions queue while keeping new wall creation on Wall settings.
- Split wall configuration from searchable, paginated submission moderation with directly addressable Wall settings and Submissions tabs.
- Added database-locked wall capacity and accurate paginated admin, public, privacy, and REST collections.
- Added shared bounded validation for required email, HTTP/HTTPS launch and logo URLs, real calendar dates, and explicit placement-term acceptance.
- Added accessible public voting with nonce checks, active/public eligibility, atomic counters, and configurable daily visitor limits.
- Limited read-scoped REST responses to active walls and approved payment-eligible public fields without submitter or payment data.
- Added clear checkout failure handling and direct WordPress email notifications.
- Added paginated privacy export/erasure and scheduled per-wall retention cleanup for stale terminal private data.
- Schedule retention during `init` so first activation remains warning-free alongside plugins that add translated cron intervals.
- Added stable checkbox geometry, responsive pagination, and explicit success/error colors in light, dark, and system admin themes.

## 0.1.0

- Added the initial local-first Launch Wall implementation.
