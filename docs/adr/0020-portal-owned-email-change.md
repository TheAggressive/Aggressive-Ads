# ADR-0020 — Portal-owned email change

**Status:** Accepted — 2026-08-12

## Context

Portal users cannot reach `/wp-admin/profile.php` because `Admin_Guard`
closes wp-admin to them. Core's email-change flow mails a confirmation to the
new address and completes on that profile screen, so advertisers had no safe
self-service path. Approximating the change by posting a new address from the
account form without a confirmation token would be an account-takeover
primitive.

## Decision

The plugin owns the email-change challenge end to end:

1. The account screen offers a dedicated request form (never the details save
   form).
2. A 256-bit URL-safe token is mailed only to the requested new address. Only a
   salted HMAC is stored in `_laao_ads_email_change` user meta, with the
   destination and a three-day expiry.
3. Confirmation happens on `/advertiser/confirm-email/`, which requires both a
   valid token and a signed-in session for the same account.
4. Completion uses `wp_update_user()` so core still notifies the previous
   address. Sessions are not destroyed; email is not part of the auth cookie.
5. Requests are rate-limited per user. Addresses already held by another account
   receive the same opaque success response and no mail.

Raw tokens and email addresses do not enter audit context.

## Consequences

- Advertisers can change their sign-in email without staff or profile.php.
- Confirmation needs mailbox control plus the existing password session.
- Re-requests overwrite the previous pending challenge.
- Core's weak `_new_email` / `send_confirmation_on_profile_email()` path is not
  used.

## Alternatives rejected

**Complete through profile.php.** Portal users cannot reach it by design.

**Update email on the request form without a token.** Anyone with a stolen
session could permanently redirect recovery mail.

**Reuse password-reset keys.** Different privilege boundary; mixing them makes
expiry and audit semantics harder to reason about.

**Custom access-table row.** One pending challenge per user fits user meta; the
org access table exists for multi-tenant identity and invitations.
