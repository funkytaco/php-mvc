-- {{APP_NAME}} — Host Build Order Gateway & Tracker Schema
-- Compatible with both PostgreSQL and MySQL

-- Mock Helix ticket storage (Helix-owned fulfillment state)
CREATE TABLE IF NOT EXISTS mock_helix_tickets (
    id VARCHAR(20) PRIMARY KEY,
    order_ref VARCHAR(20) NOT NULL UNIQUE,
    status VARCHAR(50) NOT NULL DEFAULT 'received',
    current_queue VARCHAR(100),
    queues_json TEXT NOT NULL,
    blocker_json TEXT,
    history_json TEXT NOT NULL DEFAULT '[]',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mock_helix_status ON mock_helix_tickets(status);
CREATE INDEX IF NOT EXISTS idx_mock_helix_created_at ON mock_helix_tickets(created_at);

-- App-owned order records (Order & Intake Layer)
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_ref VARCHAR(20) NOT NULL UNIQUE,
    app_name VARCHAR(255) NOT NULL,
    environment VARCHAR(50) NOT NULL,
    sensitivity VARCHAR(50) NOT NULL,
    expected_users INTEGER DEFAULT 1,
    need_by DATE,
    requirements_record TEXT,
    resolved_profile VARCHAR(100) DEFAULT 'standard',
    helix_ticket_ref VARCHAR(20) UNIQUE,
    state VARCHAR(50) NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orders_order_ref ON orders(order_ref);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_helix_ticket_ref ON orders(helix_ticket_ref);

-- Seed the three demo tickets from the prototype
-- ORD-1042: blocked at Network Security (FW approval)
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, created_at)
VALUES (
    'HLX-88231',
    'ORD-1042',
    'blocked',
    'Network Security',
    '[{"name": "Virtualization", "state": "done"}, {"name": "Linux Engineering", "state": "done"}, {"name": "Security Engineering", "state": "done"}, {"name": "Directory Services", "state": "done"}, {"name": "PKI / Certificate", "state": "done"}, {"name": "Network Security", "state": "blocked"}, {"name": "Service Desk", "state": "pending"}]',
    '{"team": "Network Security", "reason": "Awaiting Firewall Change Approval (approver ≠ implementer)", "since": "2026-07-24T05:00:00Z"}',
    NOW() - INTERVAL '11 days'
);

INSERT INTO orders (order_ref, app_name, environment, sensitivity, expected_users, need_by, requirements_record, helix_ticket_ref, state)
VALUES (
    'ORD-1042',
    'Benefits Portal',
    'prod',
    'moderate',
    200,
    '2026-08-15',
    'LAMP Stack (PHP 8) with MariaDB for Benefits Portal. Moderate sensitivity, ~200 concurrent users.',
    'HLX-88231',
    'in_fulfillment'
);

-- ORD-1039: delivered
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, created_at)
VALUES (
    'HLX-88015',
    'ORD-1039',
    'delivered',
    'Service Desk',
    '[{"name": "Virtualization", "state": "done"}, {"name": "Linux Engineering", "state": "done"}, {"name": "Security Engineering", "state": "done"}, {"name": "Directory Services", "state": "done"}, {"name": "PKI / Certificate", "state": "done"}, {"name": "Network Security", "state": "done"}, {"name": "Service Desk", "state": "done"}]',
    NULL,
    NOW() - INTERVAL '7 days'
);

INSERT INTO orders (order_ref, app_name, environment, sensitivity, expected_users, need_by, requirements_record, helix_ticket_ref, state)
VALUES (
    'ORD-1039',
    'HR System',
    'prod',
    'confidential',
    150,
    '2026-08-08',
    'Java App Server for HR System. Confidential data, ~150 users, strict AD/PAM enrollment.',
    'HLX-88015',
    'delivered'
);

-- ORD-1044: early/queued
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, created_at)
VALUES (
    'HLX-88412',
    'ORD-1044',
    'received',
    'Virtualization',
    '[{"name": "Virtualization", "state": "ready"}, {"name": "Linux Engineering", "state": "pending"}, {"name": "Security Engineering", "state": "pending"}, {"name": "Directory Services", "state": "pending"}, {"name": "PKI / Certificate", "state": "pending"}, {"name": "Network Security", "state": "pending"}, {"name": "Service Desk", "state": "pending"}]',
    NULL,
    NOW() - INTERVAL '2 days'
);

INSERT INTO orders (order_ref, app_name, environment, sensitivity, expected_users, need_by, requirements_record, helix_ticket_ref, state)
VALUES (
    'ORD-1044',
    'Analytics Platform',
    'test',
    'internal',
    75,
    '2026-08-30',
    'Static Web Host for Analytics Platform staging. Internal use, ~75 concurrent users.',
    'HLX-88412',
    'submitted'
);
