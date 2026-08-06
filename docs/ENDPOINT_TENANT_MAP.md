# DaVez — Endpoint → Table Map (Fase 1 multi-tenant retrofit)

Read-only analysis of `X:\Help`. Purpose: enumerate every PHP endpoint, the
business/infra tables it reads and writes, and the **exact place a `tenant_id`
scope (WHERE) or value (INSERT) must be injected** when the pooled multi-tenant
retrofit lands (ADR-001/002/003, single `tenant_id`, empresa = loja).

> No source files were modified. No migrations were run. No git state changed.

---

## Reference context

- **Decision locked (ADR-001):** pooled tenancy, one `tenant_id` on every
  business table, always derived from the authenticated identity, never from
  client input. No `store_id` (empresa = loja).
- **8 business tables** (migration 012 already added nullable `tenant_id`;
  migration 013 backfilled a "Empresa Legado" tenant; the NOT NULL + FK
  enforcement in `docs/PHASE1_ENFORCEMENT.md` is gated on this retrofit):
  `settings`, `checkins`, `fila_da_vez`, `reports`, `delivery_events`,
  `daily_access_codes`, `admission_tickets`, `public_sessions`.
- **Auth classes used below:**
  - **public request-context** — `davez_require_public_request_context()` /
    `davez_bootstrap_public_request_context()`: HTTPS/CSRF/rate-limit gate only,
    **no logged-in identity required** (the endpoint may create or optionally
    read one).
  - **public identity** — `davez_require_public_identity()`: a valid
    `public_sessions` cookie is mandatory.
  - **admin** — `davez_require_admin()` (+ `davez_require_csrf()` on mutations):
    env-based owner/operator session (`$_SESSION['davez_admin_auth']`).

---

## Cross-cutting retrofit concerns (apply to many endpoints)

These are the structural pivots that every endpoint below inherits. Flagged once
here to avoid repetition.

1. **Admin session carries NO tenant today.** `davez_admin_authenticate()` in
   `src/Security/AdminAuth.php` sets only `['role','issued_at','last_activity']`
   from env (`ADMIN_USER` / `ADMIN_OPERATORS`). Every admin endpoint therefore
   has **no tenant to scope by**. ADR-002's `users` + `admin_sessions` (with
   `tenant_id`) must land first; `davez_authenticated_admin_identity()` must
   start returning `tenant_id`, and that value is what all admin queries below
   bind. **This is the single biggest prerequisite** — until it exists, admin
   endpoints cannot be scoped.

2. **`settings` is a hard singleton (`id=1`).** Schema enforces
   `CONSTRAINT chk_settings_singleton CHECK (id = 1)` and code everywhere uses
   `WHERE id=1`. Multi-tenant needs one settings row per tenant. Every
   `WHERE id=1` on `settings` becomes `WHERE tenant_id = ?`; the singleton CHECK
   and the `INSERT ... VALUES (1)` seed must be reworked (per-tenant seed on
   tenant creation). Also `settings` holds geofence base (`lat_base/lng_base/
   raio`) and `chamada_aberta` — these become per-tenant, so the geofence/queue
   gate in the public flow is tenant-specific.

3. **Operational cycle is per-tenant.** `OperationalCycle::fromEnvironment()`
   reads timezone + cycle start from env. `tenants` already has `timezone` and
   `operational_cycle_time`. Post-retrofit the cycle (hence `operational_date`,
   `operationalStart/End`) must be derived from the resolved tenant, not env —
   otherwise date-window WHEREs compare against the wrong cycle boundary.

4. **Hash uniqueness does not imply isolation.** `code_hash`, `ticket_hash`,
   `token_hash` are globally UNIQUE HMAC/SHA. A lookup by hash returns at most
   one row, but the retrofit must still add `AND tenant_id = ?` (and, more
   importantly, resolve the tenant *before* trusting the row) so that a public
   session/code from tenant A can never be validated inside tenant B's request.

---

## Root-level endpoints

### `checkin.php` — POST — public request-context
Purpose: redeem a daily access code, create (or re-login) today's check-in, and
open a public session cookie.

| Table | Op | Where in code / query | tenant_id injection |
|---|---|---|---|
| `settings` | SELECT (R) | ll.110-116 `SELECT chamada_aberta,lat_base,lng_base,raio FROM settings WHERE id=1` | replace `WHERE id=1` → `WHERE tenant_id=?` |
| `daily_access_codes` | SELECT…FOR UPDATE (R) | `PublicIdentityStore::loadDailyCodeForUpdate` (called l.180) | add `AND tenant_id=?` to WHERE |
| `daily_access_codes` | UPDATE (W) | `activateDailyCode` (l.292) / `touchDailyCode` (l.352) | add `AND tenant_id=?` to WHERE |
| `checkins` | SELECT…FOR UPDATE (R) | dup-name check ll.193-199; re-login lookup ll.308-315 | add `AND tenant_id=?` |
| `checkins` | SELECT MAX(ordem) (R) | ll.230-235 | add `AND tenant_id=?` |
| `checkins` | INSERT (W) | ll.262-266 `INSERT INTO checkins (nome,data_hora,operational_date,ordem)` | add `tenant_id` column + bind value |
| `public_sessions` | INSERT (W) | `createSession` (l.358) | add `tenant_id` column + bind value |

**Tricky tenant resolution (HIGH):** this is a pre-auth public entry point with
no session yet. The tenant must be resolved **before** the `settings` geofence
read (chicken-and-egg: you need the tenant to know which store's lat/lng/radius
to validate against, but the only tenant signal in the request is the store
context). Resolution must come from the **store/QR context** (URL slug,
sub-domain, or a store token embedded in the QR the driver scanned) — not from
`nome`/`lat`/`lng`, and not solely from `daily_access_codes.tenant_id` (the code
is only known after the settings/geofence gate). Recommended: derive tenant from
the QR/store slug up front, load that tenant's `settings`, then confirm the
redeemed code's `tenant_id` matches.

### `recover.php` — POST — public request-context
Purpose: re-open a session for an existing check-in from its daily code.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `daily_access_codes` | SELECT…FOR UPDATE (R) | `loadDailyCodeForUpdate` (l.104) | add `AND tenant_id=?` |
| `daily_access_codes` | UPDATE (W) | `touchDailyCode` (l.168) | add `AND tenant_id=?` |
| `checkins` | SELECT…FOR UPDATE (R) | ll.120-127 | add `AND tenant_id=?` |
| `public_sessions` | UPDATE (W) | `revokeActiveSessions` (l.162) | add `AND tenant_id=?` |
| `public_sessions` | INSERT (W) | `createSession` (l.170) | add `tenant_id` value |

**Tricky (HIGH):** same store/QR-derived resolution as `checkin.php`. No
`settings` read here, so tenant must come from the store context and be
cross-checked against the matched `daily_access_codes.tenant_id`.

### `session_info.php` — GET — public request-context (identity optional)
Purpose: report whether the caller's public session is valid + their queue view.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `public_sessions` (JOIN `checkins`) | SELECT (R) | `PublicIdentityStore::findValidSession` (l.919) via `davez_authenticated_public_identity` | add `AND sessions.tenant_id=?` **and** `AND checkins.tenant_id = sessions.tenant_id` on the JOIN |
| `checkins` | SELECT (R) | `davez_public_identity_me` ll.23-29 (`PublicIdentityView.php`) | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT (R) | `davez_public_identity_me` queue lookup ll.62-69 + position count ll.92-101 | add `AND tenant_id=?` |

**Tricky (MEDIUM):** identity is resolved purely from the opaque cookie
(`token_hash`). The tenant must come from the session row itself; the retrofit
must ensure the `findValidSession` JOIN cannot bind a `public_sessions` row of
tenant A to a `checkins` row of tenant B (add tenant equality to the JOIN
predicate).

### `public_logout.php` — POST — public request-context (identity optional)
Purpose: revoke the caller's active session + clear cookie.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `public_sessions` (JOIN `checkins`) | SELECT (R) | `findValidSession` via `davez_authenticated_public_identity` (l.47) | add `AND tenant_id=?` (see session_info) |
| `public_sessions` | UPDATE (W) | `revokeSession` (l.54) | add `AND tenant_id=?` to WHERE |

**Tricky (MEDIUM):** tenant derived from the session cookie, same as above.

### `relogin.php` — POST — public
Legacy path; returns HTTP 410. **No DB access, no tenant work.**

### `client_log.php` — POST — public request-context
Best-effort client error slug logger. Writes to the **file** event log via
`log_event()` (see `log.php`), **no DB tables.** No tenant scoping on tables;
if per-tenant log separation is ever wanted, that is a `log.php` concern, not a
business-table WHERE.

### `ranking_pdf.php` — GET — admin
Purpose: PDF of the delivery ranking for a period.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `delivery_events` | SELECT (R) | `RankingQuery::fetchPage` → `countDrivers`, main aggregate, `loadDailySeries`, `loadPreviousTotals` (see RankingQuery below) | add `AND tenant_id=?` to **all four** `delivery_events` WHEREs |

Tenant = admin session tenant (blocked on concern #1).

### `report_pdf.php` — GET — admin
Purpose: PDF of one persisted report + that cycle's ranking.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `reports` | SELECT (R) | ll.46-52 `SELECT ... FROM reports WHERE id=? LIMIT 1` | add `AND tenant_id=?` (ownership check — prevents IDOR across tenants) |
| `delivery_events` | SELECT (R) | ll.79-88 aggregate `WHERE operational_date=?` | add `AND tenant_id=?` |

**Tricky (MEDIUM):** `WHERE id=?` on `reports` is a raw BOLA/IDOR surface — a
report id from another tenant would currently be served. The `tenant_id` guard
here is an **ownership assertion**, not just a filter.

### `reports_pdf.php` — GET — admin
Purpose: PDF index/listing of persisted reports (date-filtered).

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `reports` | SELECT (R) | `ReportListQuery::count` + `::load` (see below) | add `AND tenant_id=?` to both WHEREs |

Tenant = admin session tenant (concern #1).

### `admin.php` — mixed (GET read-actions + POST JSON/form) — admin
Single-file router. Login/logout via `admin_auth_action`; read actions via
`?action=`; mutations via JSON `acao` or the settings form. On **every**
authenticated request it also runs a top-level `settings` read.

**Always-on (any authenticated action):**
| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `settings` | SELECT (R) | ll.194-200 `SELECT ... FROM settings WHERE id=1` | `WHERE tenant_id=?` |

**GET read actions:**
| action | Table | Op | Where | injection |
|---|---|---|---|---|
| `dados` | `settings` | SELECT (R) | ll.359-365 `WHERE id=1` | `WHERE tenant_id=?` |
| `metrics` | `settings` | SELECT (R) | l.378 `SELECT chamada_aberta FROM settings WHERE id=1` | `WHERE tenant_id=?` |
| `metrics` | `checkins` | SELECT agg (R) | `metrics_hoje()` ll.212-244 (count + avg duration, `WHERE data_hora >= ? AND < ?`) | add `AND tenant_id=?` to both queries |
| `lista` | `checkins` | SELECT (R) | ll.388-397 cycle list | add `AND tenant_id=?` |
| `listar_relatorios` | `reports` | SELECT (R) | `ReportListQuery::fetchPage` | add `AND tenant_id=?` (see below) |
| `ver_relatorio` | `reports` | SELECT (R) | l.523 `SELECT * FROM reports WHERE id=? LIMIT 1` | add `AND tenant_id=?` (ownership / IDOR) |
| `ver_relatorio` | `delivery_events` | SELECT agg (R) | ll.538-548 `WHERE operational_date=DATE(?)` | add `AND tenant_id=?` |
| `ranking` | `delivery_events` | SELECT (R) | `RankingQuery::fetchPage` | add `AND tenant_id=?` (see below) |
| `logs` | — | — | reads file event log only (`read_recent_log_events`) | none |
| `server_time` | — | — | no DB | none |

**POST JSON mutations (`acao`):**
| acao | Table | Op | Where | injection |
|---|---|---|---|---|
| `toggle_chamada` | `settings` | UPDATE (W) | ll.835-850 `UPDATE settings SET chamada_* WHERE id=1` | `WHERE tenant_id=?` |
| `limpar` | `settings` | SELECT…FOR UPDATE (R) | ll.889-894 `WHERE id=1 FOR UPDATE` | `WHERE tenant_id=?` |
| `limpar` | `checkins` | SELECT…FOR UPDATE (R) | ll.902-912 cycle snapshot | add `AND tenant_id=?` |
| `limpar` | `reports` | INSERT (W) | ll.953-958 `INSERT INTO reports (...)` | add `tenant_id` value |
| `limpar` | `fila_da_vez`,`public_sessions`,`admission_tickets`,`daily_access_codes` | DELETE (W) | ll.985-1019 loop `DELETE FROM {table} WHERE {datecol}=?` | add `AND tenant_id=?` to every DELETE |
| `limpar` | `checkins` | DELETE (W) | ll.1021-1025 `WHERE data_hora >= ? AND < ?` | add `AND tenant_id=?` (**note** the `affected_rows === $total` invariant must count only this tenant's rows) |
| `limpar` | `settings` | UPDATE (W) | ll.1047-1051 `SET chamada_aberta=0 WHERE id=1` | `WHERE tenant_id=?` |
| `toggle_close` | `checkins` | SELECT…FOR UPDATE (R) | ll.1110-1118 by id + cycle | add `AND tenant_id=?` |
| `toggle_close` | `checkins` | SELECT MAX(ordem) (R) | ll.1149-1153 | add `AND tenant_id=?` |
| `toggle_close` | `checkins` | UPDATE (W) | ll.1198-1206 set is_closed/ordem | add `AND tenant_id=?` |
| `toggle_close` | `fila_da_vez` | DELETE (W) | ll.1238-1250 by checkin_id/client_id/nome | add `AND tenant_id=?` |
| `toggle_close` | `public_sessions` | UPDATE (W) | `revokeActiveSessions` l.1276 | add `AND tenant_id=?` |
| `apagar_relatorio` | `reports` | DELETE (W) | l.1315 `DELETE FROM reports WHERE id=? LIMIT 1` | add `AND tenant_id=?` (ownership / IDOR) |
| `atualizar_ordem` | `checkins` | SELECT…FOR UPDATE (R) | ll.1353-1359 | add `AND tenant_id=?` |
| `atualizar_ordem` | `checkins` | UPDATE (W) | ll.1393-1398 set ordem | add `AND tenant_id=?` |
| `atualizar_ordem` | `checkins` | SELECT verify (R) | ll.1428-1434 | add `AND tenant_id=?` |
| `add_manual` | `checkins` | SELECT dup (R) | ll.1517-1523 | add `AND tenant_id=?` |
| `add_manual` | `checkins` | SELECT MAX(ordem) (R) | ll.1554-1558 | add `AND tenant_id=?` |
| `add_manual` | `checkins` | INSERT (W) | ll.1594-1598 `INSERT INTO checkins (...)` | add `tenant_id` value |
| `issue_checkin_ticket` | `daily_access_codes` | INSERT (W) | `issueDailyCode` l.702 | add `tenant_id` value |
| `issue_recovery_ticket` | `checkins` | SELECT (R) | ll.746-752 target lookup | add `AND tenant_id=?` |
| `issue_recovery_ticket` | `daily_access_codes` | UPDATE (W) | `rotateDailyCodeHash` l.797 | add `AND tenant_id=?` |
| `issue_recovery_ticket` | `daily_access_codes` | INSERT (W) | `issueActivatedDailyCode` l.804 (fallback) | add `tenant_id` value |
| `ticket_status` | `daily_access_codes` | SELECT (R) | `findDailyCodeStatus` l.661 | add `AND tenant_id=?` |

**POST form (`form_action=save_settings`):**
| Table | Op | Where | injection |
|---|---|---|---|
| `settings` | UPDATE (W) | ll.1690-1694 `UPDATE settings SET lat_base,lng_base,raio WHERE id=1` | `WHERE tenant_id=?` |

Tenant for all of admin.php = admin-session tenant (concern #1). The
`ver_relatorio` / `apagar_relatorio` / `report_pdf` by-id paths are the concrete
IDOR spots.

---

## DaVez/ subfolder endpoints

### `DaVez/entrar.php` — POST — public identity (required)
Purpose: authenticated driver joins today's DaVez queue.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `public_sessions`(JOIN `checkins`) | SELECT (R) | `davez_require_public_identity` → `findValidSession` (l.76) | add `AND tenant_id=?` |
| `settings` | SELECT (R) | ll.92-97 `WHERE id=1` (geofence base) | `WHERE tenant_id=?` |
| `fila_da_vez` | SELECT…FOR UPDATE (R) | ll.144-151 existing-entry check | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT MAX(ordem) (R) | ll.181-185 | add `AND tenant_id=?` |
| `fila_da_vez` | INSERT … ON DUPLICATE KEY UPDATE (W) | ll.215-226 | add `tenant_id` column + value; keep uniqueness aligned with `(tenant_id, dia, checkin_id)` |

**Tricky (LOW/MEDIUM):** tenant comes from the resolved public identity
(session → checkin → tenant). Straightforward once #2/#3 settle, but the
`settings WHERE id=1` geofence read must use the same tenant as the session.

### `DaVez/sair.php` — POST — admin (+ CSRF)
Purpose: dispatch the head of the DaVez queue ("é a vez dele"), log the delivery,
compact the queue.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `fila_da_vez` | SELECT…FOR UPDATE (R) | ll.65-72 lookup dispatched row | add `AND tenant_id=?` |
| `fila_da_vez` | UPDATE (W) | ll.104-111 set `em_entrega` | add `AND tenant_id=?` |
| `delivery_events` | INSERT (W) | ll.137-143 `INSERT INTO delivery_events (...)` | add `tenant_id` value |
| `fila_da_vez` | SELECT…FOR UPDATE (R) | ll.167-173 remaining | add `AND tenant_id=?` |
| `fila_da_vez` | UPDATE (W) | ll.199-205 reorder compaction | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT verify (R) | ll.233-239 | add `AND tenant_id=?` |

Tenant = admin session (concern #1).

### `DaVez/listar.php` — GET — public request-context (identity optional)
Purpose: public queue board (next up + counts) polled every ~5s; adds caller's
own position if a session cookie is present.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `public_sessions`(JOIN `checkins`) | SELECT (R) | optional `davez_authenticated_public_identity` (l.53) | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT agg (R) | `davez_public_queue_summary` counts ll.152-158 + next ll.177-184 | add `AND tenant_id=?` |
| `checkins` | SELECT (R) | `davez_public_identity_me` (only if identity present) | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT (R) | `davez_public_identity_me` queue + position | add `AND tenant_id=?` |

**Tricky (HIGH):** the queue **summary** runs even with no logged-in caller, so
the tenant of the board cannot come from a session — it must come from the
**store/QR/public page context** (same resolver as `checkin.php`). This is a
public read endpoint whose tenant is store-derived, not identity-derived.

### `DaVez/listar_admin.php` — GET — admin
Purpose: full admin queue view for today.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `fila_da_vez` | SELECT (R) | ll.24-33 `WHERE dia=? ORDER BY ...` | add `AND tenant_id=?` |

Tenant = admin session (concern #1).

### `DaVez/reordenar.php` — POST — admin (+ CSRF)
Purpose: admin drag-reorder of the DaVez queue.

| Table | Op | Where | tenant_id injection |
|---|---|---|---|
| `fila_da_vez` | SELECT…FOR UPDATE (R) | ll.74-81 current set | add `AND tenant_id=?` |
| `fila_da_vez` | UPDATE (W) | ll.107-113 set ordem | add `AND tenant_id=?` |
| `fila_da_vez` | SELECT verify (R) | ll.132-139 | add `AND tenant_id=?` |

Tenant = admin session (concern #1).

---

## Shared query classes (`src/Application/`)

These are not endpoints but carry the SQL for `ranking`/`ranking_pdf` and
`listar_relatorios`/`reports_pdf`. Retrofitting them scopes several endpoints at
once — but each currently takes only date/paging args, so the retrofit must
thread a `tenant_id` (from the caller's `TenantContext`) into the constructor or
every method.

### `src/Application/Ranking/RankingQuery.php` — reads `delivery_events`
| Method | Op | Query | injection |
|---|---|---|---|
| `fetchPage` (main) | SELECT agg (R) | ll.75-90 `... FROM delivery_events WHERE operational_date >= ? AND <= ? GROUP BY nome` | add `AND tenant_id=?` |
| `countDrivers` | SELECT (R) | ll.151-156 `COUNT(DISTINCT nome) ... WHERE operational_date BETWEEN` | add `AND tenant_id=?` |
| `loadDailySeries` | SELECT (R) | ll.176-183 `... WHERE ... AND nome IN (...)` | add `AND tenant_id=?` |
| `loadPreviousTotals` | SELECT (R) | ll.209-215 | add `AND tenant_id=?` |

**Note:** ranking aggregates by `nome` (free-text driver name), so cross-tenant
name collisions (two tenants with a "João Silva") make the `tenant_id` filter
**mandatory for correctness**, not just isolation.

### `src/Application/Reports/ReportListQuery.php` — reads `reports`
| Method | Op | Query | injection |
|---|---|---|---|
| `count` | SELECT (R) | ll.90-93 `COUNT(*) FROM reports WHERE (date filter)` | add `AND tenant_id=?` |
| `load` (used by `fetchPage` & `fetchForExport`) | SELECT (R) | ll.120-127 | add `AND tenant_id=?` |

---

## Non-trivial tenant-resolution spots (summary)

Ranked by difficulty; these are where "derive tenant from the authenticated
identity" (ADR-003) does **not** directly apply:

1. **Public pre-session entry — `checkin.php`, `recover.php` (HIGH).** No session
   exists yet; tenant must be derived from the **store/QR context** (slug /
   subdomain / QR-embedded store token) *before* the `settings` geofence read,
   then cross-checked against `daily_access_codes.tenant_id`. Chicken-and-egg:
   the code is only validated after the tenant-specific geofence gate.
2. **Public board — `DaVez/listar.php` queue summary (HIGH).** Renders for
   anonymous callers, so tenant is store/QR-derived, not identity-derived.
3. **Admin identity has no tenant yet — all admin endpoints (HIGH, prerequisite).**
   Blocked on ADR-002 `users`/`admin_sessions.tenant_id`; nothing to bind until
   that lands.
4. **By-id IDOR surfaces — `report_pdf.php`, admin `ver_relatorio` /
   `apagar_relatorio` (MEDIUM).** `reports.id` lookups have no ownership check;
   `AND tenant_id=?` is an ownership assertion guarding BOLA/IDOR.
5. **Cookie-derived session JOINs — `session_info.php`, `public_logout.php`,
   `DaVez/listar.php`, `DaVez/entrar.php` (MEDIUM).** `findValidSession` joins
   `public_sessions`→`checkins`; the retrofit must make the JOIN tenant-consistent
   so a session of tenant A can't bind a checkin of tenant B.
6. **`limpar` invariant — admin.php (MEDIUM).** The
   `DELETE FROM checkins ... affected_rows === $total` consistency check and the
   4-table cascade delete must all be tenant-scoped **together**, or a cross-tenant
   miscount aborts the transaction (or, worse, deletes another tenant's cycle).
7. **`settings` singleton (MEDIUM).** `WHERE id=1` + `CHECK (id=1)` +
   `INSERT VALUES(1)` must convert to per-tenant rows; touches
   `checkin.php`, `DaVez/entrar.php`, and 6 spots in `admin.php`.

---

## Dormant / not currently wired

- **`src/Database/SettingsTokenCycle.php`** reads/writes `settings`
  (`SELECT token,token_data,... FROM settings` and `UPDATE settings SET
  token=?,token_data=? WHERE id=1 ... token_data <=> ?`). The factory
  `davez_settings_token_cycle()` exists in `src/Database/bootstrap.php` but is
  **not invoked by any endpoint** (only tests reference it). It is a legacy
  token-rotation path over the `settings.token`/`token_data` columns. If ever
  revived it needs the same `settings` tenant scoping (`WHERE id=1` →
  `WHERE tenant_id=?`). Flagged so the retrofit doesn't miss it.
- **`log.php`** (`log_event`, `read_recent_log_events`) is a file-based event log
  included by several endpoints; **no DB tables**, so no `tenant_id` on tables.
  Per-tenant log separation, if wanted, is a file/path concern.
- **`config.php`** — DB connection bootstrap only; no business tables.

---

## Coverage note

Endpoints covered: `checkin.php`, `recover.php`, `session_info.php`,
`public_logout.php`, `relogin.php`, `client_log.php`, `ranking_pdf.php`,
`report_pdf.php`, `reports_pdf.php`, `admin.php` (login/logout + 8 read actions +
9 JSON mutations + settings form), and `DaVez/{entrar,sair,listar,listar_admin,
reordenar}.php`; plus the shared query classes `RankingQuery` and
`ReportListQuery`. All eight business tables' reads and writes are accounted for.
