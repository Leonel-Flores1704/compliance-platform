# Operations Guide: HTTPS Enforcement and Reverse Proxy Hardening

**Requirement:** Non-functional  
**Related Requirements:** NFR-003, ADR-030  
**MoSCoW:** Must  
**Ticket:** CMP-13 (Enforce HTTPS for all protected resources)  
**Target Domain:** `compliance-platform.online`  

---

## 1. Architecture Overview

To ensure that credentials and organizational data **never** travel over plain HTTP, the system utilizes Nginx 1.27 as a hardened reverse proxy terminating TLS traffic before routing to the application services:

```
                          [ Client Browser / API Consumer ]
                                  |            |
                  HTTP (Port 80)  |            |  HTTPS (Port 443, TLS 1.2/1.3)
                                  v            v
                      +-----------------------------+
                      |         Nginx 1.27          |
                      |   (Reverse Proxy & TLS)     |
                      +-----------------------------+
                        /          |              \
       HTTP 301 / 426  /           |               \
       (Plain traffic /            |                \
        rejected/redirected)       |                 \
                                   v                  v
                   +-------------------+    +--------------------+
                   | React 18 SPA      |    | Laravel 11 Backend |
                   | (Static Files)    |    | (REST API :8000)   |
                   +-------------------+    +--------------------+
                                                      |
                                                      | Internal Docker Network
                                                      v
                                            +--------------------+
                                            | FastAPI Microserv. |
                                            | (RAG / AI :8001)   |
                                            +--------------------+
```

### Key Security Principles
1. **Plain HTTP Handling (Port 80):**
   - **Browser Navigation (GET/HEAD):** Returns `301 Moved Permanently` redirecting to `https://$host$request_uri`.
   - **Non-GET API Requests (`POST`, `PUT`, `PATCH`, `DELETE`):** Forwarded with `X-Forwarded-Proto: http` to Laravel, where the `RequireHttps` middleware rejects the request immediately with **HTTP 426 Upgrade Required** and body `{"message": "HTTPS is required."}`. Plain HTTP POST requests are **never redirected** (preventing data retransmission in the clear) and **no credentials are processed**.
   - **ACME Challenges:** Served on `/.well-known/acme-challenge/` for Certbot renewal.
2. **TLS Hardening (Port 443):**
   - Supports **only TLS 1.2 and TLS 1.3** (`ssl_protocols TLSv1.2 TLSv1.3;`).
   - Deprecated and insecure protocols (**SSLv3, TLS 1.0, TLS 1.1**) are strictly disabled.
   - **HSTS:** `Strict-Transport-Security: max-age=31536000; includeSubDomains` header injected on all HTTPS responses.
   - Hardened cipher suites prioritizing ECDHE and AES-GCM / ChaCha20-Poly1305.
3. **Laravel 11 Session & Security Hardening:**
   - `URL::forceScheme('https')` enforced in production via `AppServiceProvider`.
   - `SESSION_SECURE_COOKIE=true` ensures session cookies are transmitted only via HTTPS.
   - `SESSION_SAME_SITE=lax` prevents CSRF attacks on cross-site requests.
   - `trustProxies(at: '*')` configured so Laravel accurately recognizes `$request->secure()` from proxy headers.
4. **FastAPI Isolation:**
   - The FastAPI AI microservice binds port 8001 **only** on the internal Docker bridge network (`expose: ["8001"]`).
   - Port 8001 is **not published to the host or internet**, rendering it completely unreachable from outside.

---

## 2. Certificate Management

### 2.1 Local Development (mkcert)
For local testing and development with valid TLS certificates recognized by local browsers without security warnings:

1. Install `mkcert` and local CA:
   ```bash
   # On macOS
   brew install mkcert
   mkcert -install

   # On Linux (Fedora/RHEL/Ubuntu)
   # sudo dnf install mkcert / sudo apt install mkcert
   mkcert -install
   ```

2. Generate local certificates:
   ```bash
   ./infra/scripts/generate-local-certs.sh
   ```
   This generates `infra/certs/cert.pem` and `infra/certs/key.pem` with Subject Alternative Names (SAN) for `localhost`, `127.0.0.1`, `::1`, and `compliance-platform.online`.

### 2.2 Production (Let's Encrypt & Certbot)
In production, certificates are issued by Let's Encrypt using Certbot with the ACME HTTP-01 challenge.

#### Automated Certificate Renewal (Twice Per Day)
Certbot certificates expire every 90 days. Per Let's Encrypt best practices, renewal checks must be executed **twice daily**:

Configure the renewal cron job on the production host:
```bash
# Edit crontab
crontab -e
```

Add the following schedule (runs at 00:00 and 12:00 every day):
```cron
0 0,12 * * * certbot renew --webroot -w /var/www/certbot --post-hook "podman compose -f /opt/compliance-platform/docker-compose.yml exec -T nginx nginx -s reload" >> /var/log/certbot-renew.log 2>&1
```

If using systemd timers instead of cron, enable `certbot.timer`:
```bash
sudo systemctl enable --now certbot.timer
sudo systemctl list-timers | grep certbot
```

---

## 3. Verification & Acceptance Criteria Runbook

Execute the following commands to verify all acceptance criteria defined in CMP-13:

### Criterion 1: HTTP 301 Redirect for Web Browsing
Verifies that any plain HTTP navigation is redirected to HTTPS:
```bash
curl -I http://compliance-platform.online/login
```
**Expected Response:**
```http
HTTP/1.1 301 Moved Permanently
Server: nginx/1.27.x
Date: Fri, 23 Oct 2026 12:00:00 GMT
Content-Type: text/html
Content-Length: 169
Connection: keep-alive
Location: https://compliance-platform.online/login
```

---

### Criterion 2: Plain HTTP API Non-GET Rejection (HTTP 426)
Verifies that unencrypted non-GET requests are rejected and credentials are never processed:
```bash
curl -i -X POST http://compliance-platform.online/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email": "admin@compliance-platform.online", "password": "SuperSecretPassword123"}'
```
**Expected Response:**
```http
HTTP/1.1 426 Upgrade Required
Server: nginx/1.27.x
Content-Type: application/json
Connection: keep-alive

{"message":"HTTPS is required."}
```

---

### Criterion 3: Strict-Transport-Security (HSTS) Header
Verifies that all HTTPS responses include the HSTS header:
```bash
curl -I -k https://compliance-platform.online/
```
**Expected Response:**
```http
HTTP/2 200 
server: nginx/1.27.x
content-type: text/html; charset=UTF-8
strict-transport-security: max-age=31536000; includeSubDomains
x-content-type-options: nosniff
x-frame-options: SAMEORIGIN
```

---

### Criterion 4: SSL/TLS Protocol Audit (testssl.sh / SSL Labs)
Audit the TLS endpoint using `testssl.sh` or Qualys SSL Labs:
```bash
testssl.sh --protocols --headers https://compliance-platform.online
```
**Expected Results:**
- **TLS 1.0:** Not offered / Severely disabled
- **TLS 1.1:** Not offered / Severely disabled
- **TLS 1.2:** Offered (with secure PFS cipher suites)
- **TLS 1.3:** Offered
- **HSTS:** 31536000 seconds (1 year), includeSubDomains
- **Overall Grade:** **A** or **A+**

---

### Criterion 5: Wireshark / tcpdump Traffic Capture
Capture network traffic during an authentication attempt and document download:
```bash
# Capture packets on interface eth0 on port 80 and 443
sudo tcpdump -i any -n 'port 80 or port 443' -w /tmp/compliance_https_audit.pcap
```

1. Execute a login and download a document over HTTPS:
   ```bash
   curl -k -X POST https://compliance-platform.online/api/auth/login ...
   curl -k -O https://compliance-platform.online/api/documents/1/download
   ```
2. Stop `tcpdump` and inspect the capture:
   ```bash
   tshark -r /tmp/compliance_https_audit.pcap -Y "http or tls"
   ```
**Expected Finding:**
- All traffic contains **only TLS Application Data** packets (TLS 1.2 / TLS 1.3).
- No plaintext credentials, authorization tokens, or organizational file contents appear in any packet payload.

---

### Criterion 6: FastAPI Internal Isolation Audit (Port 8001)
Verify that port 8001 (FastAPI AI microservice) is not accessible from outside the host:
```bash
nmap -p 8001 compliance-platform.online
# Or locally against external host IP
nmap -p 8001 127.0.0.1
```
**Expected Output:**
```
PORT     STATE  SERVICE
8001/tcp closed vcom-tunnel
```
*(Port state is `closed` or `filtered`, confirming that port 8001 is only reachable inside the private Docker network by Laravel).*
