# Domain module catalogue

Stage 19 documentation inventory. These links describe boundaries; they do not
certify that every ASVS requirement is satisfied. Kernel operations are described
separately in [operations](operations.md).

| Domain directory | Responsibility and authoritative module documentation |
|---|---|
| `Admin` | Staff permissions, system status, privileged audits, financial/user views. [admin](admin.md), [admin-metrics](admin-metrics.md) |
| `Ai` | Assistant credit accounting and brand voice; external adapters stay in Integrations. [ai-assistant](ai-assistant.md) |
| `Analytics` | Aggregation and post snapshots; unavailable != zero, isolated PDF. [post-analytics](post-analytics.md), [admin-metrics](admin-metrics.md) |
| `Api` | API token identity, scopes, pagination and idempotency; ordinary domain services remain authoritative. [public-api](public-api.md) |
| `Approval` | Revision-bound review, threaded comments, guest capabilities/PIN and client visibility. [approvals](approvals.md) |
| `Audit` | `AuditLog` records actions with workspace/actor/entity; `AuditReader` restricts reads; `AuditActions` defines labels. [workspaces](workspaces.md), [admin](admin.md) |
| `Auth` | Local credentials, MFA, sessions, one-time recovery tokens and linked identities. [auth](auth.md), [social-auth](social-auth.md) |
| `Automation` | Post queues/slots, recurrence, RSS/import and triggers reuse PostService. [automation](automation.md) |
| `Billing` | Entitlements, subscriptions, payments, renewals and ledger. [billing](billing.md) |
| `Campaign` | `Campaigns` selects eligible recipients and queues `SendCampaignBatchJob`; consent/opt-out is enforced, no separate mail transport. Staff access only. [site-admin](site-admin.md) |
| `Channel` | Publication destinations and encrypted credentials; source ingestion is separate. [channels](channels.md), [vk](vk.md), [max](max.md), [instagram](instagram.md) |
| `Content` | `Documents`, `SiteContent`, `Announcements` are staff CMS; submodules implement immutable text/image/video decisions and automation. [site-admin](site-admin.md), [source-text-processing](source-text-processing.md), [source-image-processing](source-image-processing.md), [source-video-processing](source-video-processing.md), [content-automation](content-automation.md), [content-hardening](content-hardening.md) |
| `ContentDiscovery` | Provider candidates, deterministic clusters/trend and optional semantic relevance; no direct publishing. [content-discovery](content-discovery.md), [semantic-selection](semantic-selection.md) |
| `Design` | `ThemeColors` validates persisted theme overrides for the existing design system; staff changes, no arbitrary CSS execution. [site-admin](site-admin.md) |
| `Help` | `HelpArticles` reads trusted versioned knowledge-base resources into `HelpArticle`; public read, no browser file paths. [site-admin](site-admin.md) |
| `Legal` | `LegalDocuments`/`LegalDocument` load versioned legal resources and consent version. Owner verifies production legal content. [site-admin](site-admin.md), [auth](auth.md) |
| `Link` | UTM, short links, click retention and URL blocking; publication-time integration remains in the request builder. [links](links.md) |
| `Media` | Validated uploads, processing, platform variants, usage checks and private storage keys. [media](media.md) |
| `Notification` | `Notifier` routes events into existing mail/Telegram jobs; `NotificationSettings`, `MarketingConsent`, `MailTemplates`, `MailComposer`, `TelegramLinks` separate preference/content/linking. `FeedbackMailer` handles user feedback. [auth](auth.md), [approvals](approvals.md), [site-admin](site-admin.md) |
| `Post` | Drafts, variants, scheduled publications and the single Publisher pipeline. [posts](posts.md), [content-drafts](content-drafts.md) |
| `Settings` | `Settings` persists application options; `SiteSettings` exposes validated site configuration. Administrative writes are not a workspace permission bypass. [site-admin](site-admin.md) |
| `Source` | Workspace-scoped content sources, ingress events/items/messages and revision-bound selection. [sources](sources.md), [semantic-selection](semantic-selection.md) |
| `Status` | `PlatformHealth`/`PlatformStatus` supply incident state for public status/banner and protected operational views. They do not reveal provider keys. [site-admin](site-admin.md) |
| `Support` | `Tickets` stores support conversations/attachments through scoped services; staff/customer permissions remain separate. [site-admin](site-admin.md), [admin](admin.md) |
| `User` | `User` identity and `UserRepository` normalization, password hash/verified/blocked state; credentials are not returned to views. [auth](auth.md), [workspaces](workspaces.md) |
| `Webhook` | `WebhookOutbox`, `Webhooks`, `WebhookDispatcher`, `DeliverWebhookJob`: durable, signed, idempotent outgoing delivery with SSRF/retry protection. [public-api](public-api.md) |
| `Workspace` | Membership, roles/channel access, permissions, audit and tenant boundaries. [workspaces](workspaces.md) |

The existing how-to is [adding an OAuth provider](../howto-oauth-provider.md).
It remains consistent with the existing Integrations/OAuth boundary; no Stage 19
OAuth provider or new external API was added. Full applicability/security checks
remain in the [ASVS ledger](../../security/asvs-l2.md).
