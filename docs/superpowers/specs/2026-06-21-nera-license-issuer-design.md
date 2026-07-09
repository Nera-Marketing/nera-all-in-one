# Domain-Bound Permanent Licensing — Design Spec

**Date:** 2026-06-21
**Status:** Approved for planning
**Scope:** One new internal plugin (`nera-license-manager`) + small changes to `nera-all-in-one` (AIO).

---

## Context

Nera sells **one-time, permanent** license tiers (PRO, Agency). There is no payment
integration and no subscription. The Nera dev team builds each client site, holds its
admin credentials, and is the **only** party that ever handles license keys: a dev
generates a key, embeds it on the site they built, and the client just uses the site —
they never see or paste a key.

AIO already mints and verifies **permanent, offline, Ed25519-signed** tier keys
(`src/License/Signed_Key.php`, `tools/mint-license.php`, embedded public key in
`NERA_AIO_LICENSE_PUBKEY`). Two gaps remain:

1. **No anti-reuse.** A permanent tier key works on *any* site. A client with admin
   access can copy their key off the AIO License screen and reuse it on a second site,
   dodging a second license. Fix = **bind each key to one domain**.
2. **Key generation is CLI-only** (`tools/mint-license.php`). The team wants a GUI to
   **create keys and see a list of all issued keys with their sites**.

This spec covers both: a domain-bound key format, a small AIO-side domain check, and a
new internal React-based issuer plugin that creates keys and stores a ledger.

### Intended outcome

- Each minted key is locked to a single production domain.
- Devs mint and review keys from a WordPress admin UI (React), not the CLI.
- All issued keys + their sites are stored in one shared ledger (the internal site's DB).
- System stays **fully offline**: issuer and client sites never communicate.

---

## Goals / Non-goals

**Goals**
- Hard domain binding: a key grants its tier only on its stamped domain.
- React admin UI to create a key (site name, domain, tier) and list all issued keys.
- Shared ledger in the internal WordPress site's database.
- Private key never leaves the server (issuer signs; never shipped to a browser).
- Minted keys verify against AIO's **existing** embedded public key (no re-keying).

**Non-goals (explicitly out of scope)**
- Payment / store / checkout integration.
- Revocation / kill switch (impossible offline; permanent keys by design).
- Expiry, scheduled re-checks, phone-home, or any client→server traffic.
- Multi-domain / wildcard / seat counting.
- Self-serve customer flows.
- Editing a key after mint (a moved site → mint a new key).

---

## Architecture overview

Two independent plugins that share only a **key wire-format contract**:

```
┌─────────────────────────────┐         ┌──────────────────────────────┐
│  nera-license-manager        │  key    │  nera-all-in-one (AIO)        │
│  (internal-only, Nera box)   │ ──────▶ │  (deployed to client sites)   │
│                              │ (copy/  │                               │
│  React UI ── REST ── PHP     │  paste) │  Signed_Key::inspect()        │
│  sign (private key, server)  │         │  License_Client::resolve()    │
│  ledger: CPT nera_license    │         │  verify (embedded public key) │
└─────────────────────────────┘         └──────────────────────────────┘
        holds PRIVATE key                     holds PUBLIC key only
```

- **No network link** between them. A dev copies the key string from the issuer UI and
  pastes it into the client site's AIO License page.
- The issuer **must never** be installed on a client site (it holds the private key).
- Both must use the **same Ed25519 keypair** already in use: the issuer signs with
  `tools/.license-private.key`; AIO verifies with the matching
  `NERA_AIO_LICENSE_PUBKEY` already embedded. **Do not regenerate the keypair** — that
  would invalidate every AIO build's embedded public key.

---

## The contract: key wire format

A license key is unchanged in structure — `b64url(payloadJSON).b64url(signature)`,
Ed25519 detached signature — with one new payload field:

```json
{ "tier": "pro", "domain": "acme.com", "id": 123, "iss": "nera" }
```

| Field | Meaning | Notes |
|---|---|---|
| `tier` | `pro` \| `agency` | existing |
| `domain` | bound host, normalized | **new** — lowercase, no scheme, no `www.`, no trailing slash |
| `id` | ledger record id | **new** — the issuer CPT post ID; bookkeeping only, not security |
| `iss` | issuer tag (`nera`) | existing |
| `exp` | — | **omitted** = permanent |

**Back-compat:** a key with **no** `domain` field is treated as *unbound* and grants its
tier on any site (covers the demo key path and any pre-existing keys).

**Domain normalization (must be identical on both sides):**
```
lowercase( strip-leading-"www." ( host-only ( input ) ) )
```
AIO derives the site host from `wp_parse_url( home_url(), PHP_URL_HOST )` and normalizes
the same way before comparing.

---

## Component A — `nera-license-manager` (new plugin)

Internal-only WordPress plugin. Stack mirrors the AIO frontend: **Vite + React +
Tailwind v4**, built to `dist/` and enqueued on its admin page. React owns 100% of the
UI; PHP is a thin bootstrap + REST + signing layer.

### A1. PHP layer (thin)

- **Bootstrap** — plugin header, constants, autoload (PSR-4 fallback like AIO).
- **CPT registration** — `nera_license`, `public => false`, `show_in_rest => false`
  (custom routes handle data; the React app is the only UI).
- **Admin page** — top-level menu "Licenses"; renders `<div id="nera-lm-root"></div>`,
  enqueues the React bundle, and `wp_localize_script`s:
  - REST root + nonce (`wp_create_nonce('wp_rest')`),
  - the preloaded license list (so first paint needs no fetch),
  - the tier options.
- **REST namespace `nera-lm/v1`**, every route
  `permission_callback => current_user_can('manage_options')`:
  - `GET /licenses` → `[{ id, name, domain, tier, key, issued_at }]`.
  - `POST /licenses` `{ name, domain, tier }` → validate + sanitize → normalize domain →
    create the CPT post (to obtain its ID) → sign payload `{tier, domain, id, iss}` →
    save `key` meta → return the new record incl. the key string.
- **Signer service** — reads the private key from a file at
  `NERA_LM_PRIVATE_KEY_PATH` (default: a `.license-private.key` in the plugin dir,
  gitignored). Signs with `sodium_crypto_sign_detached`. Encodes
  `b64url(json).b64url(sig)`. This is the same algorithm and key as
  `tools/mint-license.php`.

### A2. React app

- **List view** — table: Site name · Domain · Tier badge · Key (truncated + **Copy**) ·
  Issued date. Client-side search box (filters by name/domain). Renders from the
  preloaded data; refetches after a create.
- **New License view** — form: Site name, Domain, Tier (select). Submit → `POST` →
  show the generated key with a prominent **Copy** button + a "paste this into the
  site's AIO License page" hint.
- **Data access** — `@wordpress/api-fetch` (auto-attaches the REST nonce) or `fetch`
  with `X-WP-Nonce`. Simple local state; no router needed beyond a two-tab toggle.

### A3. Data model (CPT `nera_license`)

| Stored as | Field | Example |
|---|---|---|
| `post_title` | site / client name | "Acme Comps" |
| meta `_nlm_domain` | bound host (normalized) | `acme.com` |
| meta `_nlm_tier` | tier | `pro` |
| meta `_nlm_key` | full signed key string | `eyJ0…` |
| `post_date` | issued date (WP auto) | — |

No custom table, no `dbDelta`. WP manages IDs, dates, and search.

---

## Component B — AIO domain check (small, existing plugin)

Three focused changes, no contract breakage:

1. **`src/License/Signed_Key.php` → `inspect()`**
   Currently returns `{ ok, tier, expired }`. Add `domain` (string|null) read from the
   verified payload. Signature verification logic unchanged.

2. **`src/License/License_Client.php` → `resolve()`**
   In the signed-key branch (after `ok` + valid tier, before returning entitled):
   - If `domain` is non-empty **and** normalized `domain` ≠ normalized site host →
     return `entitlement( NERA_AIO_DEFAULT_TIER, 'signed-domain-mismatch' )` and
     `Logger::log`.
   - If `domain` is empty/absent → unchanged (unbound key, grant tier).
   - The demo-key branch (above this) is untouched, so non-production sites still get
     PRO via the demo key with no domain constraint.
   Add a small private `site_host()` / `normalize_domain()` helper.

3. **`src/Admin/Licensing.php` → `render_page()`**
   Show "Licensed for: `<domain>`" when the active key is domain-bound, and a warning
   notice when `source === 'signed-domain-mismatch'` explaining the key is bound to a
   different domain.

No changes to `License_Store`, `Entitlements`, `Module_Registry`, or the catalog.

---

## Security

- **Private key isolation** — only the issuer's PHP signs; the key is never sent to a
  browser and never present on a client site. `.license-private.key` stays gitignored.
- **Issuer must not ship to clients** — operational rule; document in the issuer
  plugin's README. (Build artifact + key live only on the internal box.)
- **REST writes** — gated by `manage_options` + REST nonce; `domain`/`tier` validated
  and sanitized server-side (`tier` ∈ {pro, agency}; domain via `wp_parse_url` + regex).
- **Tamper resistance** — domain and tier live inside the signed payload; changing
  either invalidates the signature (offline-verifiable). `id` is bookkeeping only and
  is not trusted for any security decision.

## Error handling

- **Issuer:** missing/unreadable private key → REST `POST` returns a clear 500 ("signing
  key not configured"); the UI surfaces it. Invalid tier/domain → 400 with field message.
- **AIO:** unchanged failure posture — any verification failure, expired, bad tier, or
  domain mismatch falls back to the default (free) tier and logs via `Logger`. The site
  never fatals on a bad key.

---

## Verification (end-to-end)

1. **Interop (critical):** mint a key in the issuer UI for `domain=acme.com, tier=pro`.
   Confirm it verifies against AIO's embedded public key (same keypair). If not, the
   issuer is using the wrong private key — point `NERA_LM_PRIVATE_KEY_PATH` at the
   existing `tools/.license-private.key`.
2. **Match:** set the test site to host `acme.com` (or mint for the test host), paste the
   key into AIO License → Apply → tier shows **PRO**, "Licensed for: acme.com".
3. **Mismatch:** use that same key on a different host (e.g. `competitions-core.local`)
   → AIO drops to **free**, `source = signed-domain-mismatch`, warning shown.
4. **Demo path:** on the non-production test site, the demo key
   (`NERA-DEMO-PRO-2026`) still grants PRO with no domain constraint.
5. **Unbound back-compat:** a key minted with no `domain` grants its tier on any host.
6. **Ledger:** the new key appears in the issuer Licenses list with correct site/domain/
   tier/date; search finds it; Copy yields the exact key string.

---

## Build order (for the implementation plan)

1. AIO Component B (domain check) — small, unblocks testing with CLI-minted keys.
2. Issuer PHP layer (CPT + REST + signer) — verify interop with AIO from step 1.
3. Issuer React app (list + create) on the Vite/Tailwind toolchain.
4. End-to-end verification per the matrix above.
