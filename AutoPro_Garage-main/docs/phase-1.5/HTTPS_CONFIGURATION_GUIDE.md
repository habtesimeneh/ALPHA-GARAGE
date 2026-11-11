# Phase 1.5 — HTTPS Configuration Guide

**Environment verified:** Apache 2.4.33 (Win64), OpenSSL 1.0.2u, PHP 8.3.1 (mod_php), MySQL 5.7.24, MAMP on Windows 10
**Local URL verified:** `https://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/`
**Status:** local HTTPS **working** with a self-signed development certificate. Production HTTPS is **prepared but not activated** (no production domain, CA certificate or server was available).

---

## 1. How MAMP serves the application

| Item | Value |
|---|---|
| Apache root | `C:\MAMP\bin\apache` |
| Main config | `C:\MAMP\conf\apache\httpd.conf` |
| Document root (HTTP) | `C:\MAMP\htdocs` (MAMP default) |
| Project path | `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main` |
| Public entry point | `…\public\` (`index.php`, `login.php`, `register.php`, `contact.php`, …) |
| `mod_ssl` | Already loaded: `LoadModule ssl_module modules/mod_ssl.so` (httpd.conf line 152) |
| PHP configuration | `C:\MAMP\conf\php8.3.1\php.ini` |

MAMP starts Apache as a console process on this machine, not as a Windows service. `httpd.exe -k restart` fails with *“No installed service named Apache2.4”*; use the MAMP control panel, or stop and start `httpd.exe` directly.

## 2. Local HTTPS setup performed

### 2.1 Files changed / created

| Path | Purpose |
|---|---|
| `C:\MAMP\conf\apache\gms-ssl.conf` | New SSL virtual host (port 443) |
| `C:\MAMP\conf\apache\httpd.conf` lines 622–624 | Guarded include of the SSL vhost |
| `C:\MAMP\conf\gms-ssl\gms-openssl.cnf` | Certificate request/SAN configuration |
| `C:\MAMP\conf\gms-ssl\localhost-cert.pem` | Development certificate (public) |
| `C:\MAMP\conf\gms-ssl\localhost-key.pem` | Development private key — **outside the web root** |
| `…\phase1_5-backups\20261109_024836\httpd.conf.phase1_5.orig` | Pre-change copy of `httpd.conf` (SHA-256 `aba969939aba4793ee8b45b6f29ad6eddeff3bb5fc8da8dc5611984769105d19`) |

Guarded include appended to `httpd.conf`:

```apache
#BEGIN ALPHA GARAGE PHASE 1.5 LOCAL HTTPS - REMOVE TO ROLL BACK
Include "C:/MAMP/conf/apache/gms-ssl.conf"
#END ALPHA GARAGE PHASE 1.5 LOCAL HTTPS
```

`gms-ssl.conf`:

```apache
# Alpha Garage - Phase 1.5 local HTTPS verification only.
# Scope: port 443 for localhost, same document root as the existing HTTP server.
# Rollback: remove the Include line from httpd.conf, delete this file, restart Apache.
Listen 443

<VirtualHost *:443>
    ServerName localhost
    DocumentRoot "C:\MAMP\htdocs"

    <Directory "C:\MAMP\htdocs">
        Options Indexes Includes FollowSymLinks ExecCGI
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile "C:/MAMP/conf/gms-ssl/localhost-cert.pem"
    SSLCertificateKeyFile "C:/MAMP/conf/gms-ssl/localhost-key.pem"

    # TLS 1.2+ only (OpenSSL 1.0.2u cannot negotiate TLS 1.3).
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder On
    SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES

    # No HSTS here: this is a self-signed localhost certificate, and HSTS must
    # only be enabled in production after every host/subdomain is HTTPS-verified.
</VirtualHost>
```

The HTTP listener on port 80 and its document root are unchanged, so other MAMP projects are unaffected.

### 2.2 Certificate creation (reproducible)

`gms-openssl.cnf`:

```ini
[req]
default_bits       = 2048
prompt             = no
default_md         = sha256
distinguished_name = dn
x509_extensions    = v3_req
req_extensions     = v3_req

[dn]
C  = ET
ST = Addis Ababa
L  = Addis Ababa
O  = Alpha Garage Local Development
OU = Phase 1.5 Local HTTPS
CN = localhost

[v3_req]
basicConstraints     = CA:FALSE
keyUsage             = digitalSignature, keyEncipherment
extendedKeyUsage     = serverAuth
subjectAltName       = @alt_names

[alt_names]
DNS.1 = localhost
IP.1  = 127.0.0.1
IP.2  = ::1
```

Command (from `C:\MAMP\conf\gms-ssl`):

```bat
C:\MAMP\bin\apache\bin\openssl.exe req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes ^
  -keyout localhost-key.pem -out localhost-cert.pem ^
  -config gms-openssl.cnf -extensions v3_req
```

For the generated certificate, verified with `openssl x509`:

| Property | Value |
|---|---|
| Subject / Issuer | `CN=localhost, O=Alpha Garage Local Development` (self-signed) |
| Validity | 2026-11-09 → 2029-02-11 |
| SANs | `DNS:localhost`, `IP:127.0.0.1`, `IP:0:0:0:0:0:0:0:1` |
| Key usage | Digital Signature, Key Encipherment; EKU serverAuth; `CA:FALSE` |
| SHA-256 fingerprint | `65:93:6D:01:25:B5:7A:C8:94:A1:C5:5E:B6:BC:6C:97:DE:0E:89:E7:99:D5:51:92:95:E9:DE:13:54:A2:F6:9A` |

OpenSSL 1.0.2u is old and cannot verify a `CA:FALSE` self-signed leaf as trusted, and lacks `x509 -ext`. SAN and hostname/IP were therefore verified with:

```bat
openssl x509 -in localhost-cert.pem -noout -text
openssl x509 -in localhost-cert.pem -noout -checkhost localhost
openssl x509 -in localhost-cert.pem -noout -checkip 127.0.0.1
```

Both checks returned *“Hostname localhost does match certificate”* and *“IP 127.0.0.1 does match certificate”*.

**This is a development certificate.** It is not issued by a public CA, is not trusted by default, and must never be presented as a production certificate.

### 2.3 Trusting the certificate on Windows (manual)

A browser shows `ERR_CERT_AUTHORITY_INVALID` / “Not secure” until the certificate is trusted for the current Windows user. This is expected. The trust store was **not** modified automatically during Phase 1.5.

Option A — current user only (recommended for development, no admin rights):

```bat
certutil -addstore -user Root "C:\MAMP\conf\gms-ssl\localhost-cert.pem"
```

To remove it again:

```bat
certutil -delstore -user Root "65:93:6D:01:25:B5:7A:C8:94:A1:C5:5E:B6:BC:6C:97:DE:0E:89:E7:99:D5:51:92:95:E9:DE:13:54:A2:F6:9A"
```

Option B — graphical:

1. Double-click `localhost-cert.pem` → **Install Certificate**.
2. Choose **Current user** (or **Local machine** with administrator rights).
3. Place it in **Trusted Root Certification Authorities**.
4. Restart the browser.

Firefox may use its own trust store; either enable *“Allow this certificate to identify websites”* in the certificate viewer or configure `security.enterprise_roots.enabled=true` so Firefox reads the Windows store.

Remove the trust when it is no longer needed. Trusting this certificate only affects this machine; it has no effect on production or other users.

## 3. Verification results

| Check | Command | Result |
|---|---|---|
| HTTPS page loads | `curl -k https://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/` | **200** |
| Certificate trust as-is | `curl https://...` | exit 60 (self-signed) — expected |
| TLS 1.2 | `openssl s_client -connect localhost:443 -tls1_2` | `Protocol: TLSv1.2`, `ECDHE-RSA-AES256-GCM-SHA384` |
| TLS 1.0 | `openssl s_client -connect localhost:443 -tls1` | rejected: `tlsv1 alert protocol version` |
| Session cookie over HTTP | response headers | `HttpOnly; SameSite=Lax` (no `Secure`) |
| Session cookie over HTTPS | response headers | `secure; HttpOnly; SameSite=Lax` |
| Security headers | response headers | CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| HSTS | response headers | absent locally — intentional |
| Assets / CSS / JS / images / AJAX / forms | browser + HTTP | all same-origin, no mixed content, 200 |
| Authenticated flows over HTTPS | temporary ACL-restricted test-DB vhost | admin dashboard and customer 403 checks pass; remember-me cookie rotates and is `Secure` |

The HTTPS session was exercised with a temporary virtual host on port 8443 bound to the `_test` database for authenticated testing. That vhost was removed afterwards, and port 8443 is no longer listening; only ports 80 and 443 remain.

## 4. HTTP-to-HTTPS redirect

**Not enabled locally, by design.** The directive allows HTTP development to remain available as an explicitly documented fallback; forcing a redirect here would break other MAMP projects and the documented HTTP fallback. No redirect was configured, so there is no redirect to verify locally.

For production, add a redirect in the HTTP virtual host **after** DNS and the certificate are working:

```apache
<VirtualHost *:80>
    ServerName YOUR_DOMAIN

    # ACME HTTP-01 challenge must stay reachable over plain HTTP.
    Alias /.well-known/acme-challenge/ "/var/www/letsencrypt/.well-known/acme-challenge/"
    <Directory "/var/www/letsencrypt/.well-known/acme-challenge/">
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>
```

Verify after enabling:

```bash
curl -I http://YOUR_DOMAIN/            # expect 301 → https://YOUR_DOMAIN/
curl -I https://YOUR_DOMAIN/           # expect 200 and no redirect loop
```

## 5. Production HTTPS deployment checklist

The provider and domain are unknown. Nothing below was executed against a production server. Replace `YOUR_DOMAIN` and paths with the real values.

### 5.1 Prerequisites

1. A real DNS name pointing at the server (A/AAAA record verified with `nslookup`).
2. A certificate from a trusted CA. On Linux with Apache, Certbot usually configures both vhosts; on Windows, win-acme (`wacs.exe`) or the hosting panel can produce the certificate.
3. The application deployed **outside** the web root, with only `public/` as the document root:

```
/var/www/alpha-garage/            <- app/, database/, storage/, tests/ (not web-accessible)
/var/www/alpha-garage/public/     <- DocumentRoot
```

This also removes the local `.git` exposure described in `PHASE_1_5_REMAINING_RISKS.md`, provided the repository is not placed above `public/`.

### 5.2 Apache virtual host template

```apache
<VirtualHost *:443>
    ServerName YOUR_DOMAIN
    DocumentRoot /var/www/alpha-garage/public

    <Directory /var/www/alpha-garage/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Defence in depth: the repository must never be reachable.
    <DirectoryMatch "/\.git(/|$)">
        Require all denied
    </DirectoryMatch>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/YOUR_DOMAIN/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/YOUR_DOMAIN/privkey.pem

    SSLProtocol             all -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder     On
    SSLCipherSuite          ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384

    # Enable only after every affected host/subdomain is confirmed HTTPS-only:
    # Header always set Strict-Transport-Security "max-age=300"
</VirtualHost>
```

Certificate issuance (Linux example, Certbot with the Apache plugin):

```bash
sudo certbot --apache -d YOUR_DOMAIN
sudo certbot renew --dry-run
```

Renewal must be automated and monitored; an expired certificate is an outage.

### 5.3 Application settings for production

| Setting | Required value / action |
|---|---|
| `app/config/local.php` | Real database credentials; never committed |
| `GMS_DEBUG` / `app.debug` | `false` (already the default) |
| `display_errors` | `0`; export `log_errors=1` and an `error_log` outside the web root |
| `expose_php` | `Off` |
| `session.gc_maxlifetime` | ≥ the 1800-second idle timeout (recommended 28800, matching absolute life) |
| `session.cookie_secure` | Set automatically by the app when `$_SERVER['HTTPS']` is on — do not force it globally in a way that breaks HTTP development |
| `session.cookie_httponly` / `SameSite` | `1` / `Lax` (set by the application) |
| Remember-me | `Secure` cookie over HTTPS, `HttpOnly`, `SameSite=Lax`, 30-day rotation — verified |
| Password-reset links | **Must be absolute `https://` URLs before production** (current `url()` output is root-relative) |
| `storage/` | Outside the document root, writable by the web-server user only; `encryption.key` never committed |
| Installer | `public/setup.php` removed from the production web root after verified installation |
| Backups | Stored outside the web root and outside Git |

### 5.4 Trusted proxy handling

The application derives `HTTPS` **only** from `$_SERVER['HTTPS']` and `client_ip()` uses **only** `REMOTE_ADDR`. It does not trust `X-Forwarded-Proto`, `X-Forwarded-For` or `X-Real-IP`. Consequences:

- **Preferred:** terminate TLS directly in Apache on the application server. Cookies become `Secure` automatically and no proxy headers are needed.
- If a load balancer/CDN must terminate TLS, do **not** blindly enable forwarded headers. Restrict traffic to the proxy at the firewall, configure `mod_remoteip` with `RemoteIPTrustedProxy` for those proxy addresses only, and add a reviewed application change for HTTPS detection (for example a trusted, server-set environment flag). Until then, cookies will not be marked `Secure` behind a TLS-terminating proxy.
- Never trust forwarded headers from arbitrary clients; they are spoofable and would break rate limiting and audit IPs.

### 5.5 HSTS policy

- HSTS is **not** enabled locally or in the template.
- Enable first with a short lifetime (`max-age=300`) only after HTTPS is verified on every host that serves this application.
- Add `includeSubDomains` only after every subdomain is HTTPS-only.
- Add `preload` only after the domain owner accepts the long-term commitment; removal from browser preload lists is slow.
- Recommended progression: `max-age=300` → `max-age=86400` → `max-age=31536000` once stable.

### 5.6 Installer and recovery

1. Confirm `storage/installed.lock` exists and `/setup.php` returns 404.
2. Move `public/setup.php` out of the public web root (keep it in the private deployment package); do not delete the only copy.
3. Keep `database/recover_admin.php` outside the web root; it is CLI-only and refuses to run while an administrator exists.
4. Keep an offline copy of the deployment package, the backup and the certificate renewal account.

## 6. Rollback procedure (local)

1. Stop Apache (MAMP panel, or stop the `httpd.exe` process).
2. Restore `httpd.conf` from `…\phase1_5-backups\20261109_024836\httpd.conf.phase1_5.orig`, or delete lines 622–624:

   ```apache
   #BEGIN ALPHA GARAGE PHASE 1.5 LOCAL HTTPS - REMOVE TO ROLL BACK
   Include "C:/MAMP/conf/apache/gms-ssl.conf"
   #END ALPHA GARAGE PHASE 1.5 LOCAL HTTPS
   ```

3. Delete `C:\MAMP\conf\apache\gms-ssl.conf`.
4. Optionally delete `C:\MAMP\conf\gms-ssl\` (certificate, key, request config).
5. If the certificate was trusted, remove it with the `certutil -delstore` command in §2.3.
6. Start Apache again and confirm HTTP still works:

   ```bash
   curl -I http://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/
   ```

Port 80 was never modified, so rollback does not affect other projects or the application itself.
