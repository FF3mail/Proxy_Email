# PROMPT-39 — subjectAltName for installer self-signed TLS

**Date:** 2026-09-08  
**Branch:** `prompt-39-cert-san` (from `master`, **independent PR** — not stacked on PR #4 docs work)  
**Scope:** `delta-transit-install.sh` only (`generate_self_signed_certificate()`)

---

## Root cause

`generate_self_signed_certificate()` issued certs with only `-subj "/CN=${NGINX_SERVER_NAME}"` and no **subjectAltName**. Modern browsers (Chrome, Edge, Firefox since ~2017) validate the TLS hostname against SAN, not CN alone. A self-signed cert without SAN triggers a harsher or outright rejection warning (observed on staging `panel.testvps.loc`).

---

## Change

**Approach:** `openssl req -addext "subjectAltName=…"` (OpenSSL **≥ 1.1.1**). No separate OpenSSL config file.

**OS baseline:** Project targets Debian 11+ / Ubuntu 20.04+. Those ship OpenSSL 1.1.1+ (Ubuntu 24.04: OpenSSL 3.x). `-addext` is available on all supported targets; older OpenSSL is out of scope.

**SAN type:** New helper `self_signed_subject_alt_name()`:

| `NGINX_SERVER_NAME` shape | SAN value | When |
|---------------------------|-----------|------|
| IPv4 literal (`d.d.d.d`) | `IP:<addr>` | `PARAM_APP_URL` host is an IP (allowed by `ask_public_url()` — only `https://` prefix is enforced) |
| Anything else (hostname, `.loc`, FQDN) | `DNS:<name>` | Typical case (`panel.testvps.loc`, `panel.example.com`) |

`NGINX_SERVER_NAME` comes from `extract_app_hostname()` (strip `https://` and path from `PARAM_APP_URL`). There is no dedicated hostname-vs-IP validator in preflight; the helper picks `DNS:` vs `IP:` from the extracted token.

**Unchanged:** Early return when both `$NGINX_SSL_CERT` and `$NGINX_SSL_KEY` already exist — still sets `SSL_MODE=existing` and does not regenerate. Certbot and existing-file paths untouched.

### Exact diff (installer)

```diff
+self_signed_subject_alt_name() {
+    local name="$1"
+    if [[ "$name" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
+        echo "IP:${name}"
+        return 0
+    fi
+    echo "DNS:${name}"
+}
+
 generate_self_signed_certificate() {
     ...
+    local san_ext=""
+    san_ext="$(self_signed_subject_alt_name "$NGINX_SERVER_NAME")"
+
+    # -addext requires OpenSSL >= 1.1.1 (Debian 11 / Ubuntu 20.04+ ship 1.1.1+ or 3.x).
     openssl req \
         ...
-        -subj "/CN=${NGINX_SERVER_NAME}"
+        -subj "/CN=${NGINX_SERVER_NAME}" \
+        -addext "subjectAltName=${san_ext}"
```

---

## Verification

### Environment

- **Dev host:** Windows + Git for Windows OpenSSL 3.5.6 (equivalent `openssl req -addext` to Linux 3.x).
- **No live VPS / nginx** in this session — nginx reload not executed here. The change only adds a standard X.509v3 extension; nginx `ssl_certificate` / `ssl_certificate_key` directives are unchanged.

### Fresh self-signed cert (hostname — staging case)

```text
$ openssl req -x509 -nodes -days 1 -newkey rsa:2048 \
    -keyout k.pem -out c.pem \
    -subj "/CN=panel.testvps.loc" \
    -addext "subjectAltName=DNS:panel.testvps.loc"

$ openssl x509 -in c.pem -noout -text | grep -A1 "Subject Alternative Name"
            X509v3 Subject Alternative Name:
                DNS:panel.testvps.loc
```

### IPv4 literal URL host (defensive path)

```text
$ openssl req ... -subj "/CN=192.168.1.50" -addext "subjectAltName=IP:192.168.1.50"
$ openssl x509 -in c2.pem -noout -text | grep -A1 "Subject Alternative Name"
            X509v3 Subject Alternative Name:
                IP Address:192.168.1.50
```

### Early-return / reuse path

Logic unchanged at top of `generate_self_signed_certificate()`:

```bash
if [[ -f "$NGINX_SSL_CERT" && -f "$NGINX_SSL_KEY" ]]; then
    SSL_MODE="existing"
    log_info "Reusing certificate files already at default paths"
    return 0
fi
```

Confirmed: when both default-path files exist, generation (including new `-addext`) is **not** invoked.

### Installer syntax

```bash
bash -n delta-transit-install.sh   # PASS
```

### Nginx (operator / VPS follow-up)

On a Linux host after a **new** self-signed install: `nginx -t` and `systemctl reload nginx` should behave as before — only the cert PEM gains a SAN extension. Existing deployments keep their old certs until manually replaced.

---

## PR

Independent PR into `master`: **PROMPT-39: add subjectAltName to self-signed cert generation** (`prompt-39-cert-san`).
