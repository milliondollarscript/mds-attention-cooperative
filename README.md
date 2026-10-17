# Million Dollar Script - Attention Cooperative

Attention Cooperative lets a site owner coordinate a local partner pool for sponsor inventory without creating a central ad network. Each site owner controls their own terms, approvals, exposed inventory, capacity, takedown process, and partner access.

## Features

- Local cooperative pools with public site identity, disclosure, contact, terms, privacy, and takedown settings.
- Public partner application flow gated by a legal/privacy/compliance review checkbox.
- Partner registry with invite, application, approval, change request, rejection, and revocation states.
- Per-partner requested and allowed inventory types.
- Searchable partner and inventory ID suggestions with support for exact IDs on large sites.
- Curated inventory exposure for Classic Pixel Grid, SponsorBoard, Missions, Launch Wall, Local Money Map, and custom inventory.
- Read-only detection of active local inventory providers.
- Placement requests that require an active reviewed pool, an approved same-pool partner, allowed same-pool inventory, accepted terms, required source approval, remaining capacity, and local destination approval before publication.
- Extension-owned HMAC service credentials with one-time secret reveal, authenticated encryption, 90-day default expiry, 24-hour rotation overlap, immediate revocation, timestamp and nonce replay checks, and required placement idempotency.
- Idempotent inventory reservation and restoration when placements are approved, rejected, revoked, or taken down.
- Audit records for pool, partner, inventory, placement, approval, rejection, revocation, and takedown actions.
- MDS 3.0 REST API manifest entries for inventory, applications, partner approval, placement creation, placement approval, and placement revocation.
- Paged WordPress personal data export/erase hooks and scheduled retention cleanup for inactive contact details and audit IP hashes.

## Shortcode

Use the public shortcode on any page:

```text
[mds_attention_cooperative id="1"]
```

The public view stays hidden until the cooperative is active and the site owner confirms the legal/privacy/compliance release gate.

## REST Endpoints

- `GET /wp-json/million-dollar-script/v1/cooperative/inventory`
- `POST /wp-json/million-dollar-script/v1/cooperative/partners/apply`
- `POST /wp-json/million-dollar-script/v1/cooperative/partners/{id}/approve`
- `POST /wp-json/million-dollar-script/v1/cooperative/placements`
- `POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/approve`
- `POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/revoke`

Million Dollar Script API governance controls endpoint policy where available. Browser application writes require a valid WordPress REST nonce; partner and placement approvals require an administrator; service placement writes require an approved partner's v1 service signature and `X-Idempotency-Key`. The signature authenticates only that partner service, and every incoming placement remains pending local review. Private cooperative contact email, partner contacts, sponsor contacts, notes, credential labels, service IDs, and secret material are never included in the public inventory manifest. Partner service endpoints and production signing requests must use HTTPS.

Administrators create credentials from an approved partner's **Service credentials** panel. Each secret is shown once, stored with authenticated encryption, and separate from its label. Rotation provides a 24-hour overlap before the old credential retires; revocation is immediate. Sites without credentials remain fail-closed. See `docs/USAGE.md` for the signing protocol and lifecycle.

## Requirements

- Million Dollar Script 3.0+
- WordPress 6.0+
- PHP 8.1+

## Notes For Site Owners

Attention Cooperative does not operate a central marketplace, connect partner sites automatically, auto-syndicate remote inventory, or publish sponsor placements without local approval. Site owners remain responsible for their own terms, content, moderation, sponsor disclosure, commercial email behavior, refunds, takedown handling, privacy requests, and legal compliance.

## Changelog

### 0.3.0

- Implemented the v1 signed placement protocol with approved-partner credentials, exact request binding, replay and rate enforcement, and idempotent pending placement creation.
- Added administrator-only one-time credential creation, authenticated encrypted storage, rotation overlap, expiry, and immediate revocation.
- Added service-signature discovery metadata, privacy-safe behavior, integration coverage, and operator/client documentation.

### 0.2.0

- Removed private contact email from public inventory manifests.
- Added same-pool partner and inventory validation, approval gates, hourly partner limits, and transactional capacity accounting.
- Added complete paged privacy export/erasure and scheduled retention cleanup.
- Added editable partner and inventory forms, collapsed creation tools, accurate totals, and responsive pagination.
- Enforced HTTPS partner service endpoints and site-secret audit IP hashing.

### 0.1.0

- Initial local/federated Attention Cooperative extension.
