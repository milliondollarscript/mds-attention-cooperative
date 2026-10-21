=== Million Dollar Script - Attention Cooperative ===
Contributors: milliondollarscript
Tags: million dollar script, sponsors, inventory, partnerships, federation
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Coordinate local sponsor-inventory partner pools with explicit approvals, capacity tracking, audit logs, and revocation.

== Description ==

Attention Cooperative lets Million Dollar Script site owners coordinate partner inventory without a central ad network. Each site controls its own terms, disclosures, approvals, exposed inventory, takedown process, and partner access.

The public application flow stays gated until the site owner confirms the legal/privacy/compliance release gate.

== Features ==

* Local cooperative pools with disclosure, terms, privacy, contact, and takedown settings.
* Partner invite, application, approval, change request, rejection, and revocation states.
* Per-partner requested and allowed inventory types.
* Curated inventory exposure for Classic Pixel Grid, SponsorBoard, Missions, Launch Wall, Local Money Map, and custom inventory.
* Placement requests with same-pool relationship validation, source and destination approval gates, and capacity accounting.
* Audit logs, paged WordPress personal data export/erase hooks, and scheduled private-data retention.
* MDS 3.0 REST API manifest entries for cooperative inventory and placement flows.
* Approved-partner service credentials with signed, replay-resistant, idempotent placement requests that remain pending local review.

== Shortcode ==

Use:

`[mds_attention_cooperative id="1"]`

== REST API ==

* `GET /wp-json/million-dollar-script/v1/cooperative/inventory`
* `POST /wp-json/million-dollar-script/v1/cooperative/partners/apply`
* `POST /wp-json/million-dollar-script/v1/cooperative/partners/{id}/approve`
* `POST /wp-json/million-dollar-script/v1/cooperative/placements`
* `POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/approve`
* `POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/revoke`

== Changelog ==

= 0.3.0 =
* Implemented the v1 service-signature placement protocol with exact method, route, body, timestamp, nonce, scope, and credential checks.
* Added administrator-only one-time credential creation, authenticated encrypted storage, expiry, 24-hour rotation overlap, and revocation.
* Added nonce replay prevention, partner rate enforcement, placement idempotency, privacy-safe audits, OpenAPI metadata, and integration coverage.
* Kept credential creation compatible with WordPress Playground's sodium compatibility layer.

= 0.2.0 =
* Removed private contact email from public inventory manifests.
* Added partner/inventory validation, approval gates, hourly limits, and idempotent capacity accounting.
* Added complete privacy pagination, scheduled retention, editable records, and responsive admin pagination.
* Enforced HTTPS partner service endpoints and site-secret audit IP hashing.

= 0.1.0 =
* Initial local/federated Attention Cooperative extension.
