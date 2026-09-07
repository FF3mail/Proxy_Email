# PROMPT-29 — Session cookie / IP allow-list fix

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-24-panel-auth`  
**Implementation commit:** `805723528b21af594691f4b1dc0418e42c0eb027` (`8057235`)  
**Date:** 2026-09-04

---

## Scope

Fix PROMPT-28 finding: `session_start()` ran before `checkLocalNetworkAccess()`, so non-allowlisted clients received `Set-Cookie: PHPSESSID` on HTTP 403 responses.

**Files changed:** `web/includes/helpers.php` (`isHttpsRequest()`, `startPanelSession()`), `web/index.php`, `web/monitor.php` — bootstrap order only; IP allow-list logic unchanged.

**Cookie flags:** `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS (absent on plain HTTP).

---

## Live validation

Validated on the Epic A test VPS in **PROMPT-30** (`/root/prompt30_validation.log`), clean install of `8057235`:

| Check | Result |
|-------|--------|
| **IP_403_GATE** — FastCGI `REMOTE_ADDR=203.0.113.50` on `index.php` / `monitor.php` | **PASS** — HTTP 403, no `Set-Cookie` |
| **COOKIE_FLAGS** — HTTPS login vs plain HTTP FCGI | **PASS** — `HttpOnly` + `SameSite=Lax` + `Secure` on HTTPS; `Secure` absent on HTTP |
| **OAUTH_CALLBACK_LAX** — callback with existing session | **PASS** — redirect to `/index.php`, not login |
| Core auth + monitor regression matrix | **PASS** (all items) |

---

## Verdict

**GO** — Epic B IP-gate and session-cookie requirements from PROMPT-28/29 are closed on live host `mail.testvps.loc`.
