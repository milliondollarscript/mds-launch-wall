# Launch Wall

Launch Wall lets a site owner sell and moderate launch slots, featured placements, and sponsored launch boards for products, events, books, campaigns, and other public announcements.

## Practical Examples

- Run an indie-product launch directory where makers pay for reviewed listings and optional featured placement.
- Create an event launch wall for speakers, exhibitors, community announcements, or after-parties.
- Publish a themed board for new books, games, courses, local businesses, or crowdfunding campaigns and let visitors vote for approved entries.

### Example workflow: a paid product launch wall

1. Open **Million Dollar Script -> Launch Wall**, create an active wall, set standard and featured prices, and choose the active Million Dollar Script payment provider.
2. Add the **Launch Wall** block to a page with the submission form and sponsor disclosure enabled.
3. A maker submits a launch and completes checkout; the record remains private until payment is eligible and an administrator approves it under **Submissions**.
4. The approved launch appears on the wall, and voting can be enabled to add rate-limited public feedback.

## Setup

1. Activate Million Dollar Script and Launch Wall.
2. Open **Million Dollar Script -> Launch Wall**.
3. Review the seeded wall or create a new wall.
4. Set status, slug, description, slot pricing, featured pricing, capacity, disclosure text, terms URL, moderation email, private-data retention, hourly submission limit, and daily vote limit.
5. Choose manual payment handling or Million Dollar Script payment-provider checkout.
6. Publish the wall with the block or shortcode.

## Admin Workspace

Existing walls open on **Submissions** so launches awaiting review are visible first. Use **Wall settings** to create or configure a wall. The current wall, filters, page, and tab remain in the URL where applicable.

## Public Display

Use the **Launch Wall** block from the Million Dollar Script block category, or add:

```text
[mds_launch_wall id="1"]
```

The block can select a wall by ID. The slug field is optional and is only used when no wall ID is selected.

Common display options include:

- Show the public submission form.
- Show wall summary information.
- Show sponsor disclosure.

The wall and its REST collections are paginated. When no block ID or slug is selected, the block uses the first active wall. Draft, paused, and archived walls do not render publicly.

## Moderation

Public launch submissions are held for review. Administrators can approve, reject, feature, or unfeature launches. Featured launches can use a separate fee.

Visitors must accept the placement terms and sponsorship disclosure. New submissions require a valid email address and HTTP or HTTPS launch URL. Optional logo URLs must also use HTTP or HTTPS, and launch dates must be real calendar dates. Slot capacity is enforced while holding a database lock so simultaneous submissions cannot exceed the configured limit.

Approved launches appear on the public wall. Rejected or archived launches are not shown publicly.

In provider mode, approval and payment are independent requirements. A launch appears only after an administrator approves it and its payment reaches a paid, processing, or completed state. These events can arrive in either order. Rejected, cancelled, failed, or refunded payments remove the launch from public display while preserving its moderation record.

If checkout cannot start, the launch remains private with a failed payment state and the visitor is told that no charge was made. Submission notifications use WordPress `wp_mail()`.

## Payments

Launch Wall uses the Million Dollar Script payment-provider API when provider checkout is enabled. It does not call WooCommerce or payment gateways directly.

Use manual mode when payment is reviewed outside automated checkout.

In manual mode, approving a launch confirms its manual or free payment state. Verify any offline payment before approving the launch.

Launch Wall does not automatically expire or renew placements. Approved entries remain until an administrator rejects, removes, or trashes them, or until a provider payment becomes ineligible. State any fixed display period or renewal offer separately before accepting payment.

## Voting

When voting is enabled, each public launch includes a vote button and total. Votes require a WordPress nonce, are accepted only for launches that are currently public, use an atomic database increment, and are limited per one-way IP-derived visitor key according to the wall's daily setting. The IP address itself is not stored.

## Legal And Privacy

Launch Wall can contribute **Launch Wall Sponsorship Terms** and a **Launch Wall Submission Privacy Policy** through the Million Dollar Script extension setup flow. Review them before publishing. Generated legal content is a starting point for review and is not legal advice.

Site owners remain responsible for sponsor disclosure, launch claims, refund policies, tax records, intellectual-property permissions, moderation, takedown handling, and privacy requests.

Launch Wall registers with WordPress under **Tools -> Export Personal Data** and **Tools -> Erase Personal Data**. Paginated export requests include every matching launch's content, contact details, moderation state, payment state, amount, currency, and timestamps. Erasure removes the submitter name and email, description, tagline, destination URL, and logo URL. The non-identifying launch title, category, date, payment state, amount, currency, and moderation state remain available for accounting, fraud prevention, and dispute handling.

A daily retention job applies each wall's configured period to stale rejected submissions and terminal unpaid records. Active public launches are not anonymized by scheduled retention.

## REST API

```text
GET  /wp-json/million-dollar-script/v1/launch-wall/walls
GET  /wp-json/million-dollar-script/v1/launch-wall/launches?wall_id=1
POST /wp-json/million-dollar-script/v1/launch-wall/launches
POST /wp-json/million-dollar-script/v1/launch-wall/launches/{id}/moderate
POST /wp-json/million-dollar-script/v1/launch-wall/launches/{id}/vote
```

Read endpoints require the configured `launchwall.read` API-key policy and return active/public display fields only. They support `page` and `per_page`, and do not expose submitter identity, contact details, moderation internals, payment state, charged amounts, or private wall settings. Public create and vote requests require a valid WordPress REST nonce. Moderation endpoints require administrator access.

REST create requests must include `terms_accepted`. The response is a minimal receipt and, when provider payment is required, a checkout URL; it does not echo private submitter or payment data.
