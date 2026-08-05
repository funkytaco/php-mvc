-- {{APP_NAME}} — Host Build Order & Tracking Gateway
-- PostgreSQL schema. App-owned data only (DESIGN-DD §5.2).
--
-- GOLDEN RULE 3: there are deliberately NO `nodes`, `queues`, or `build_state`
-- tables here. Fulfillment state belongs to Helix and is read-only to us; the
-- Tracker and Task View are pure projections. `mock_helix_tickets` below is the
-- MockHelixClient adapter's own backing store standing in for the Helix system
-- of record — it is not the app keeping a shadow copy of build progress.

-- ---------------------------------------------------------------------------
-- Helix-owned (mock adapter storage)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mock_helix_tickets (
    id              VARCHAR(32) PRIMARY KEY,
    order_ref       VARCHAR(32) NOT NULL UNIQUE,
    status          VARCHAR(32) NOT NULL DEFAULT 'received',
    current_queue   VARCHAR(64),
    queues_json     TEXT        NOT NULL DEFAULT '[]',
    blocker_json    TEXT,
    history_json    TEXT        NOT NULL DEFAULT '[]',
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_helix_status  ON mock_helix_tickets(status);
CREATE INDEX IF NOT EXISTS idx_helix_created ON mock_helix_tickets(created_at);

-- ---------------------------------------------------------------------------
-- App-owned: commerce / authoring
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS skus (
    id              VARCHAR(48) PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    category        VARCHAR(32)  NOT NULL,   -- os|app|security|identity|cert|firewall|license
    lifecycle       VARCHAR(24)  NOT NULL DEFAULT 'current', -- current|sunset|eol|denied
    lifecycle_note  VARCHAR(255),
    policy_injected BOOLEAN      NOT NULL DEFAULT FALSE,
    rationale       VARCHAR(255),            -- the "why" shown on locked SKUs (FR-CAT-03)
    successor_id    VARCHAR(48),             -- named successor for denied SKUs (FR-CAT-04)
    conflicts_with  VARCHAR(255),            -- comma-separated sku ids (FR-CAT-04)
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_skus_category ON skus(category);

CREATE TABLE IF NOT EXISTS catalog_items (
    id                SERIAL PRIMARY KEY,
    name              VARCHAR(128) NOT NULL UNIQUE,   -- uniqueness validation (FR-CAT-05)
    version           VARCHAR(16)  NOT NULL DEFAULT 'v1',
    solution          VARCHAR(160) NOT NULL,          -- business-language name (FR-CAT-08)
    sizing            VARCHAR(128) NOT NULL DEFAULT '4 vCPU · 16 GB · 200 GB',
    stakeholder_skus  TEXT         NOT NULL DEFAULT '[]',
    policy_skus       TEXT         NOT NULL DEFAULT '[]',
    published         BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS templates (
    id                 SERIAL PRIMARY KEY,
    name               VARCHAR(128) NOT NULL,
    version            VARCHAR(16)  NOT NULL DEFAULT 'v1',
    catalog_item_name  VARCHAR(128),
    certified_profiles TEXT         NOT NULL DEFAULT '[]',
    status             VARCHAR(24)  NOT NULL DEFAULT 'certified', -- certified|under_review
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name, version)
);

CREATE TABLE IF NOT EXISTS environment_profiles (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(96) NOT NULL,
    version        VARCHAR(16) NOT NULL DEFAULT 'v1',
    datacenter     VARCHAR(48) NOT NULL,
    sensitivity    VARCHAR(32) NOT NULL,   -- low|moderate|high
    framework_refs TEXT        NOT NULL DEFAULT '[]',
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name, version)
);

CREATE TABLE IF NOT EXISTS frameworks (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(96) NOT NULL,
    version     VARCHAR(16) NOT NULL DEFAULT 'v1',
    description VARCHAR(255),
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name, version)
);

-- ---------------------------------------------------------------------------
-- App-owned: orders and collaboration
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS orders (
    id                  SERIAL PRIMARY KEY,
    order_ref           VARCHAR(32)  NOT NULL UNIQUE,
    app_name            VARCHAR(160) NOT NULL,
    catalog_item        VARCHAR(128),
    environment         VARCHAR(32)  NOT NULL,
    sensitivity         VARCHAR(32)  NOT NULL,
    expected_users      INTEGER      NOT NULL DEFAULT 1,
    need_by             DATE,
    -- Verbatim stakeholder intent, preserved for drift re-validation (FR-ORD-05).
    requirements_record TEXT         NOT NULL DEFAULT '',
    resolved_profile    VARCHAR(96),
    frameworks          TEXT         NOT NULL DEFAULT '[]',
    approvals           TEXT         NOT NULL DEFAULT '[]',
    helix_ticket_ref    VARCHAR(32)  UNIQUE,   -- the ONLY join to Helix
    state               VARCHAR(32)  NOT NULL DEFAULT 'submitted',
    idempotency_key     VARCHAR(96)  UNIQUE,   -- DESIGN-DD §8.1
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orders_ref     ON orders(order_ref);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_ticket  ON orders(helix_ticket_ref);

CREATE TABLE IF NOT EXISTS sop_notes (
    id         SERIAL PRIMARY KEY,
    team       VARCHAR(48)  NOT NULL,   -- the SOP this note is posted ON
    author     VARCHAR(96)  NOT NULL,
    author_team VARCHAR(48),            -- set when this is a cross-post
    is_cross_post BOOLEAN   NOT NULL DEFAULT FALSE,
    is_customer   BOOLEAN   NOT NULL DEFAULT FALSE,
    body       TEXT         NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sop_notes_team ON sop_notes(team);

-- ---------------------------------------------------------------------------
-- SOP automation: rulebook bindings and run history
--
-- A team's SOP step TEXT stays governed in App\Services\SopService::TEAMS —
-- it is the certified procedure and is not user-editable. What a team member
-- may do is bind an Event-Driven Ansible rulebook to a step and run it against
-- a specific order from their queue.
--
-- These are NOT fulfillment-state tables (Golden Rule 3). Helix still owns
-- whether the build advanced; the Tracker still projects from the Helix ticket
-- and never reads these. This is the team's own record of automation they
-- chose to run — write ledger bucket 3.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sop_step_bindings (
    id          SERIAL PRIMARY KEY,
    team        VARCHAR(48) NOT NULL,
    step_index  INTEGER     NOT NULL,   -- 0-based index into SopService::TEAMS[team]['sop']
    rulebook    VARCHAR(96) NOT NULL,
    created_by  VARCHAR(96) NOT NULL DEFAULT 'system',
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (team, step_index)
);

CREATE TABLE IF NOT EXISTS sop_step_runs (
    id             SERIAL PRIMARY KEY,
    team           VARCHAR(48) NOT NULL,
    step_index     INTEGER     NOT NULL,
    order_ref      VARCHAR(32) NOT NULL,   -- runs are always against one order
    rulebook       VARCHAR(96) NOT NULL,
    status         VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued|running|completed|failed
    actor          VARCHAR(96) NOT NULL DEFAULT 'system',
    result         TEXT,
    -- Per-run bearer token. The completion callback arrives from the EDA
    -- container, which has no browser session, so it authenticates with this
    -- instead. Single-use and unguessable, so the endpoint cannot be forged.
    callback_token VARCHAR(64) NOT NULL,
    started_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at    TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sop_runs_lookup ON sop_step_runs(team, step_index, order_ref);
CREATE INDEX IF NOT EXISTS idx_sop_runs_status ON sop_step_runs(status);

-- One demo binding so the EDA path is demonstrable out of the box:
-- Virtualization step 2 ("Provision the VM in vCenter to the certified template").
INSERT INTO sop_step_bindings (team, step_index, rulebook, created_by)
VALUES ('virt', 2, 'sop-demo.yml', 'system')
ON CONFLICT (team, step_index) DO NOTHING;

-- Immutable app-side action log (AGENTS.md "Audit", FR-SM-01/03).
CREATE TABLE IF NOT EXISTS audit_log (
    id          SERIAL PRIMARY KEY,
    actor       VARCHAR(96)  NOT NULL DEFAULT 'system',
    action      VARCHAR(64)  NOT NULL,
    subject     VARCHAR(128),
    detail      TEXT,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);

-- ===========================================================================
-- Seed data — mirrors soe/UI-ORDER-ENTRY-TRACKER.html exactly
-- ===========================================================================

-- SKUs (FR-CAT-01). policy_injected = the prototype's POLICY_SKUS.
INSERT INTO skus (id, name, category, lifecycle, lifecycle_note, policy_injected, rationale, successor_id, conflicts_with) VALUES
    ('php8.0',   'PHP 8.0 (module stream)',        'app',      'sunset', 'EOL — sunset warning', FALSE, NULL, 'php8.2', NULL),
    ('php8.1',   'PHP 8.1 (module stream)',        'app',      'current', NULL, FALSE, NULL, NULL, NULL),
    ('php8.2',   'PHP 8.2 (module stream)',        'app',      'current', NULL, FALSE, NULL, NULL, NULL),
    ('httpd',    'Apache HTTPD',                   'app',      'current', NULL, FALSE, NULL, NULL, NULL),
    ('mariadb',  'MariaDB',                        'app',      'current', NULL, FALSE, NULL, NULL, NULL),
    ('rhel7',    'RHEL 7 (OS SKU)',                'os',       'denied',  'Out of support — DENY', TRUE,  'certified base OS', 'rhel9', NULL),
    ('rhel9',    'RHEL 9 (OS SKU)',                'os',       'current', NULL, TRUE,  'certified base OS', NULL, 'rhel7'),
    ('harden',   'Hardening / STIG baseline',      'security', 'current', NULL, TRUE,  'moderate baseline', NULL, NULL),
    ('tripwire', 'Tripwire FIM',                   'security', 'current', NULL, TRUE,  'file-integrity monitoring', NULL, NULL),
    ('adjoin',   'Active Directory join',          'identity', 'current', NULL, TRUE,  'domain enrolment', NULL, NULL),
    ('delinea',  'Delinea privileged access',      'identity', 'current', NULL, TRUE,  'PAM enrolment', NULL, NULL),
    ('ssl',      'SSL certificate (internal-CA)',  'cert',     'current', NULL, TRUE,  'web-facing rule', NULL, NULL),
    ('fw-host',  'Host firewall baseline (RHEL)',  'firewall', 'current', NULL, TRUE,  'default-deny', NULL, NULL),
    ('fw-net',   'Network firewall baseline',      'firewall', 'current', NULL, TRUE,  'zone policy', NULL, NULL),
    ('sub',      'RHEL subscription',              'license',  'current', NULL, TRUE,  'entitlement', NULL, NULL)
ON CONFLICT (id) DO NOTHING;

-- Published catalog items (FR-CAT-07)
INSERT INTO catalog_items (name, version, solution, sizing, stakeholder_skus, policy_skus) VALUES
    ('LAMP-PHP8-RHEL9',   'v3', 'LAMP Stack (PHP 8.0)', '4 vCPU · 16 GB · 200 GB',
     '["php8.0","httpd","mariadb"]',
     '["rhel9","harden","tripwire","adjoin","delinea","ssl","fw-host","fw-net","sub"]'),
    ('LAMP-PHP8.1-RHEL9', 'v2', 'LAMP Stack (PHP 8.1)', '4 vCPU · 16 GB · 200 GB',
     '["php8.1","httpd","mariadb"]',
     '["rhel9","harden","tripwire","adjoin","delinea","ssl","fw-host","fw-net","sub"]'),
    ('LAMP-PHP8.2-RHEL9', 'v1', 'LAMP Stack (PHP 8.2)', '4 vCPU · 16 GB · 200 GB',
     '["php8.2","httpd","mariadb"]',
     '["rhel9","harden","tripwire","adjoin","delinea","ssl","fw-host","fw-net","sub"]')
ON CONFLICT (name) DO NOTHING;

INSERT INTO frameworks (name, version, description) VALUES
    ('800-53 Moderate', 'r5', 'NIST SP 800-53 Moderate baseline'),
    ('STIG',            'v2', 'DISA Security Technical Implementation Guide'),
    ('FIPS',            'v1', 'FIPS 140 validated cryptography')
ON CONFLICT (name, version) DO NOTHING;

INSERT INTO environment_profiles (name, version, datacenter, sensitivity, framework_refs) VALUES
    ('DC-East-Moderate', 'v5', 'DC-East', 'moderate', '["800-53 Moderate","STIG","FIPS"]'),
    ('DC-West-Low',      'v3', 'DC-West', 'low',      '["baseline hardening"]'),
    ('DC-East-High',     'v2', 'DC-East', 'high',     '["800-53 High","STIG","FIPS"]')
ON CONFLICT (name, version) DO NOTHING;

INSERT INTO templates (name, version, catalog_item_name, certified_profiles) VALUES
    ('HBT-LAMP-RHEL9', 'v4', 'LAMP-PHP8-RHEL9',   '["DC-East-Moderate v5","DC-West-Low v3"]'),
    ('HBT-LAMP-RHEL9', 'v5', 'LAMP-PHP8.1-RHEL9', '["DC-East-Moderate v5","DC-West-Low v3"]'),
    ('HBT-LAMP-RHEL9', 'v6', 'LAMP-PHP8.2-RHEL9', '["DC-East-Moderate v5","DC-West-Low v3","DC-East-High v2"]')
ON CONFLICT (name, version) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Three demo orders + their Helix tickets (prototype: ORD-1042/1039/1044)
-- ---------------------------------------------------------------------------

-- ORD-1042 — Benefits Portal, blocked at build-out on Network Security
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at) VALUES
    ('HLX-88231', 'ORD-1042', 'blocked', 'Network Security',
     '[{"name":"Virtualization","state":"done"},{"name":"Linux Engineering","state":"done"},{"name":"Security Engineering","state":"done"},{"name":"Directory Services","state":"done"},{"name":"PKI / Certificate","state":"done"},{"name":"Network Security","state":"blocked"},{"name":"Service Desk","state":"pending"}]',
     '{"team":"Network Security","reason":"Awaiting Firewall Change Approval — approver ≠ implementer (segregation of duties).","since":"2026-08-04T20:00:00Z"}',
     '[{"at":"2026-08-03T02:00:00Z","actor":"system","event":"ticket.created","queue":"Virtualization"},{"at":"2026-08-04T20:00:00Z","actor":"Network Security","event":"queue.blocked","queue":"Network Security"}]',
     CURRENT_TIMESTAMP - INTERVAL '54 hours')
ON CONFLICT (id) DO NOTHING;

INSERT INTO orders (order_ref, app_name, catalog_item, environment, sensitivity, expected_users, need_by, requirements_record, resolved_profile, frameworks, approvals, helix_ticket_ref, state, created_at) VALUES
    ('ORD-1042', 'Benefits Portal', 'LAMP-PHP8-RHEL9', 'Production', 'Moderate', 200, '2026-08-05',
     'LAMP stack with PHP 8 for the Benefits Portal. Production, moderate sensitivity, roughly 200 internal users. Web-facing to staff only; no public TLS required.',
     'DC-East-Moderate v5', '["800-53 Moderate","STIG","FIPS"]', '["app-owner","security","ISSO"]',
     'HLX-88231', 'in_fulfillment', CURRENT_TIMESTAMP - INTERVAL '54 hours')
ON CONFLICT (order_ref) DO NOTHING;

-- ORD-1039 — Claims Intake API, delivered
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at) VALUES
    ('HLX-88015', 'ORD-1039', 'delivered', 'Service Desk',
     '[{"name":"Virtualization","state":"done"},{"name":"Linux Engineering","state":"done"},{"name":"Security Engineering","state":"done"},{"name":"Directory Services","state":"done"},{"name":"PKI / Certificate","state":"done"},{"name":"Network Security","state":"done"},{"name":"Service Desk","state":"done"}]',
     NULL,
     '[{"at":"2026-07-29T02:00:00Z","actor":"system","event":"ticket.created","queue":"Virtualization"},{"at":"2026-08-02T14:00:00Z","actor":"Service Desk","event":"ticket.delivered","queue":"Service Desk"}]',
     CURRENT_TIMESTAMP - INTERVAL '190 hours')
ON CONFLICT (id) DO NOTHING;

INSERT INTO orders (order_ref, app_name, catalog_item, environment, sensitivity, expected_users, need_by, requirements_record, resolved_profile, frameworks, approvals, helix_ticket_ref, state, created_at) VALUES
    ('ORD-1039', 'Claims Intake API', 'LAMP-PHP8.2-RHEL9', 'Production', 'Moderate', 80, '2026-07-20',
     'PHP 8.2 API tier for Claims Intake. Production, moderate sensitivity, about 80 concurrent consumers. Internal service-to-service only.',
     'DC-East-Moderate v5', '["800-53 Moderate","STIG","FIPS"]', '["app-owner","security","ISSO"]',
     'HLX-88015', 'delivered', CURRENT_TIMESTAMP - INTERVAL '190 hours')
ON CONFLICT (order_ref) DO NOTHING;

-- ORD-1044 — Grants Dashboard, early / queued
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at) VALUES
    ('HLX-88412', 'ORD-1044', 'received', 'Virtualization',
     '[{"name":"Virtualization","state":"ready"},{"name":"Linux Engineering","state":"pending"},{"name":"Security Engineering","state":"pending"},{"name":"Directory Services","state":"pending"},{"name":"PKI / Certificate","state":"pending"},{"name":"Network Security","state":"pending"},{"name":"Service Desk","state":"pending"}]',
     NULL,
     '[{"at":"2026-08-05T00:00:00Z","actor":"system","event":"ticket.created","queue":"Virtualization"}]',
     CURRENT_TIMESTAMP - INTERVAL '12 hours')
ON CONFLICT (id) DO NOTHING;

INSERT INTO orders (order_ref, app_name, catalog_item, environment, sensitivity, expected_users, need_by, requirements_record, resolved_profile, frameworks, approvals, helix_ticket_ref, state, created_at) VALUES
    ('ORD-1044', 'Grants Dashboard', 'LAMP-PHP8.1-RHEL9', 'Test', 'Low', 25, '2026-08-20',
     'PHP 8.1 dashboard for the Grants team. Test environment, low sensitivity, around 25 users. Short-lived — used for the pilot only.',
     'DC-West-Low v3', '["baseline hardening"]', '["app-owner"]',
     'HLX-88412', 'in_fulfillment', CURRENT_TIMESTAMP - INTERVAL '12 hours')
ON CONFLICT (order_ref) DO NOTHING;

-- Seeded SOP notes and cross-posts (FR-TASK-05) — from the prototype's `notes`
INSERT INTO sop_notes (team, author, author_team, is_cross_post, is_customer, body, created_at) VALUES
    ('linux',  'PKI / Certificate',   'pki',   TRUE,  FALSE,
     'Once the VM is on the domain, ping us early — CSR generation needs the final FQDN, not the build hostname.',
     CURRENT_TIMESTAMP - INTERVAL '30 hours'),
    ('dir',    'Customer',            NULL,    FALSE, TRUE,
     'Please place this host in the Benefits app OU, not the default Servers OU.',
     CURRENT_TIMESTAMP - INTERVAL '20 hours'),
    ('pki',    'Customer',            NULL,    FALSE, TRUE,
     'This is web-facing to ~200 internal users — internal-CA cert is fine, no public TLS needed.',
     CURRENT_TIMESTAMP - INTERVAL '48 hours'),
    ('netsec', 'Linux Engineering',   'linux', TRUE,  FALSE,
     'Host firewall will default-deny inbound except 443. Please mirror that at the perimeter for the app zone.',
     CURRENT_TIMESTAMP - INTERVAL '7 hours');

INSERT INTO audit_log (actor, action, subject, detail) VALUES
    ('system', 'seed.loaded', 'database', 'Demo catalog, profiles, frameworks, orders and SOP notes seeded.');
