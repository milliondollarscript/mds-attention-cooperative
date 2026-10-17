# Attention Cooperative

Attention Cooperative lets a site owner create a local partner pool for sponsor inventory while keeping approval, disclosure, revocation, and takedown control on the local site. It is designed for explicit partner relationships, not automatic ad-network syndication.

## Practical Examples

- A group of independent local publishers can offer approved sponsor placements across their sites while each publisher retains final approval and takedown control.
- A nonprofit network can reserve selected campaign inventory for trusted community partners without exposing private contacts or unrestricted placement access.
- An agency can coordinate inventory for several client campaigns, limit each partner to suitable inventory types, and retain an audit trail for approvals and revocations.

### Example workflow: a local publisher partnership

1. Open **Million Dollar Script -> Attention Cooperative**, create a cooperative, and complete its disclosure, terms, privacy, and takedown settings.
2. Add two publisher partners and expose only the SponsorBoard and Launch Wall inventory intended for the partnership.
3. Review an incoming placement under **Placements**, confirm the source approval and terms, and approve it for one available inventory unit.
4. The approved placement becomes available to the public display while its partner, inventory, and approval history remain manageable on the local site.

## Setup

1. Activate Million Dollar Script and Attention Cooperative.
2. Open **Million Dollar Script -> Attention Cooperative**.
3. Review the seeded cooperative and draft inventory, or create a new cooperative. The extension does not create a sample partner.
4. Fill in the site identity, contact email, takedown URL, terms URL, privacy URL, disclosure label, prohibited content language, rate limits, and retention period.
5. Confirm the legal review checkbox only after the cooperative terms, privacy language, and takedown process are ready.
6. Add public display with the block or shortcode.

The public cooperative view stays hidden until the cooperative is active and the legal review gate is confirmed.

## Admin Workspace

Existing cooperatives open on **Placements** so inventory requests and approvals are visible first. The workspace separates **Placements**, **Partners**, **Inventory**, **Activity**, **Providers**, and **Settings** into tabs. Select a cooperative once, then move between its tabs without losing that selection. Each tab has its own URL so review and support links can open the relevant workflow directly.

## Public Display

Use the block named **Attention Cooperative** from the Million Dollar Script block category, or add the shortcode manually:

```text
[mds_attention_cooperative id="1"]
```

The block can select a cooperative by ID from existing entries. The slug field is optional and is only used when no specific cooperative ID is selected.

Useful display options include:

- Show exposed inventory.
- Show approved placements.
- Show the public partner application form.

## Managing Partners And Inventory

Partners can be invited or can apply through the public form when applications are enabled. Each partner has a status, requested inventory scope, allowed inventory scope, hourly placement-request limit, endpoint details, and private notes. Partner service endpoints are optional and must use HTTPS.

Inventory records describe what can be exposed to approved partners. Inventory can represent Classic Pixel Grid, SponsorBoard, Missions, Launch Wall, Local Money Map, or custom inventory. Use **Approved partners** scope and **Exposed** status before accepting a partner placement against an item.

A placement request is accepted only when all of these conditions are met:

- The cooperative is active and its review gate is confirmed.
- The partner is approved and belongs to that cooperative.
- The inventory belongs to that cooperative, is exposed to approved partners, has remaining capacity, and its type is allowed for the partner.
- Placement terms are accepted.
- Source-site approval is recorded when the cooperative requires it.
- A local administrator approves the destination placement.

Approving a placement reserves one inventory unit. Repeating the approval does not reserve another unit. Rejecting, revoking, or taking down an approved placement restores the unit once.

The add forms stay collapsed until needed. Use **Edit** beside a partner or inventory record to update it. Lists use accurate totals and pagination. Placement selectors suggest eligible records; an admin can enter an exact numeric ID when a large catalog is not fully represented in the suggestion list.

Use revocation when a partner should no longer access the cooperative. Partner rejection, revocation, a pool change, or privacy anonymization immediately revokes that partner's service credentials. Revocation and placement actions are recorded in audit entries.

## Service Credentials

An approved partner in an active, reviewed cooperative can receive a placement service credential from the partner's **Service credentials** panel. Creation, rotation, and revocation require a WordPress administrator.

The credential has an opaque `acsvc_...` service ID and a cryptographically random secret separate from its display label. The secret is shown once after creation or rotation; save it directly in the partner service's secret manager. It is never shown again. Stored secrets use authenticated encryption and are excluded from public inventory, discovery examples, exports, activity details, and credential tables.

Credentials expire after 90 days by default; an administrator may choose 1–365 days at creation. Rotation creates a new service ID and secret while the old credential remains valid for 24 hours, allowing a controlled cutover. Revoke the old credential immediately after confirming the replacement. A revoked credential stops working immediately. Sites with no configured credential deny remote service requests.

By default, encryption derives a key from the site's WordPress secure-auth and nonce salts. Advanced deployments can define a stable, high-entropy `MDS_ATTENTION_COOPERATIVE_MASTER_KEY` of at least 32 characters in `wp-config.php` before creating credentials. Preserve the salts and any configured master key in backups: losing the key material makes the affected credentials unreadable, and they must be replaced.

## Legal And Privacy

Attention Cooperative can contribute a **Partner Inventory and Privacy Terms** page to the Million Dollar Script extension setup flow. Review this document before publishing it. The site owner remains responsible for commercial terms, sponsor disclosure, privacy requests, takedowns, refunds, moderation, and any partner-specific agreements.

The extension registers paged WordPress personal data export and erasure hooks for partner and placement contact details. Erasure anonymizes private contacts while preserving operational status and audit records.

The **Private data retention days** setting is enforced by a daily scheduled cleanup. It removes private contact, endpoint, credential-label, review-note, and audit IP-hash data from stale rejected, revoked, or taken-down records. Active partners and pending or approved placements are retained. Audit IP identifiers use a site-secret HMAC and are not reversible raw IP hashes.

## REST API

Attention Cooperative registers these REST endpoints under the Million Dollar Script API:

```text
GET  /wp-json/million-dollar-script/v1/cooperative/inventory
POST /wp-json/million-dollar-script/v1/cooperative/partners/apply
POST /wp-json/million-dollar-script/v1/cooperative/partners/{id}/approve
POST /wp-json/million-dollar-script/v1/cooperative/placements
POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/approve
POST /wp-json/million-dollar-script/v1/cooperative/placements/{id}/revoke
```

Application writes require the relevant nonce or API policy. Approval and revocation actions require administrator access. Service placement writes require a valid Attention Cooperative v1 service signature and an `X-Idempotency-Key`. The credential identifies the partner and cooperative; request fields cannot substitute another partner or pool. The approved same-pool relationship, inventory allowance, terms, source approval, capacity, moderation, and hourly partner limit are still checked.

For this endpoint, sign:

```text
method: POST
canonical route: /million-dollar-script/v1/cooperative/placements
scope: cooperative.placement.write
idempotency: required
```

Send `X-MDS-Service-Id`, `X-MDS-Signature-Version: v1`, `X-MDS-Timestamp`, `X-MDS-Nonce`, `X-MDS-Content-SHA256`, `X-MDS-Signature`, and `X-Idempotency-Key` over HTTPS. The timestamp must be within 300 seconds. The nonce is the 43-character unpadded base64url form of 32 random bytes. Hash the exact raw body bytes.

Join these values with one LF and no trailing LF:

```text
v1
<SERVICE_ID>
POST
/million-dollar-script/v1/cooperative/placements
<TIMESTAMP>
<NONCE>
<BODY_SHA256>
<IDEMPOTENCY_KEY>
```

Compute lowercase hexadecimal HMAC-SHA256 over that canonical string with the one-time secret. Use a new random nonce for each network attempt. Reuse the same idempotency key only when retrying the exact same placement body. A successful repeat returns the original placement instead of creating a duplicate. Reusing the key with a different request returns `409`.

Invalid, expired, revoked, replayed, tampered, wrong-route, wrong-method, or wrong-scope requests return the same generic authentication failure. A valid signature authenticates only the partner service. It does not create a WordPress session or grant administrator capability. The new placement is always `pending_destination`, and local administrator approval remains required before publication or inventory consumption.

The inventory response includes public site, terms, privacy, takedown, disclosure, inventory, and provider details. It does not expose the cooperative contact email, partner or sponsor contacts, private notes, or credential labels.

## Shared-Host Notes

The extension is local-first and does not connect partner sites or syndicate inventory automatically. Daily retention uses WordPress cron and does not require long-running workers or image rendering services. High-volume remote partner verification, fraud scoring, and automated cross-site placement review are better candidates for a hosted service.
