-- {{APP_NAME}} — Host Build Order Gateway & Tracker Schema

-- Mock Helix ticket storage (Helix-owned fulfillment state)
CREATE TABLE IF NOT EXISTS mock_helix_tickets (
    id VARCHAR(20) PRIMARY KEY COMMENT 'Ticket ID like HLX-88231',
    order_ref VARCHAR(20) NOT NULL COMMENT 'Link back to orders table',
    status VARCHAR(50) NOT NULL DEFAULT 'received' COMMENT 'received|in_fulfillment|blocked|delivered|etc',
    current_queue VARCHAR(100) NULL COMMENT 'Which team queue currently owns this',
    queues_json JSON NOT NULL COMMENT 'Array of {name, state} per queue',
    blocker_json JSON NULL COMMENT 'Nullable {team, reason, since}',
    history_json JSON NOT NULL DEFAULT '[]' COMMENT 'Array of {at, actor, event, queue} history',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_ref (order_ref),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App-owned order records (Order & Intake Layer)
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(20) NOT NULL UNIQUE COMMENT 'Human-friendly ORD-XXXX ref',
    app_name VARCHAR(255) NOT NULL COMMENT 'Requested app/system name',
    environment VARCHAR(50) NOT NULL COMMENT 'dev|test|prod',
    sensitivity VARCHAR(50) NOT NULL COMMENT 'public|internal|confidential|restricted',
    expected_users INT DEFAULT 1 COMMENT 'Expected concurrent users',
    need_by DATE NULL COMMENT 'Target delivery date',
    requirements_record TEXT COMMENT 'Verbatim stakeholder intent',
    resolved_profile VARCHAR(100) DEFAULT 'standard' COMMENT 'Environment profile applied at compile',
    helix_ticket_ref VARCHAR(20) NULL COMMENT 'Foreign key to mock_helix_tickets.id',
    state VARCHAR(50) NOT NULL DEFAULT 'draft' COMMENT 'draft|submitted|in_fulfillment|delivered|etc',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_helix_ticket_ref (helix_ticket_ref),
    INDEX idx_order_ref (order_ref),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the three demo tickets from the prototype
-- ORD-1042: blocked at Network Security (FW approval)
INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, created_at)
VALUES (
    'HLX-88231',
    'ORD-1042',
    'blocked',
    'Network Security',
    JSON_ARRAY(
        JSON_OBJECT('name', 'Virtualization', 'state', 'done'),
        JSON_OBJECT('name', 'Linux Engineering', 'state', 'done'),
        JSON_OBJECT('name', 'Security Engineering', 'state', 'done'),
        JSON_OBJECT('name', 'Directory Services', 'state', 'done'),
        JSON_OBJECT('name', 'PKI / Certificate', 'state', 'done'),
        JSON_OBJECT('name', 'Network Security', 'state', 'blocked'),
        JSON_OBJECT('name', 'Service Desk', 'state', 'pending')
    ),
    JSON_OBJECT(
        'team', 'Network Security',
        'reason', 'Awaiting Firewall Change Approval (approver ≠ implementer)',
        'since', '2026-07-24T05:00:00Z'
    ),
    DATE_SUB(NOW(), INTERVAL 11 DAY)
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
    JSON_ARRAY(
        JSON_OBJECT('name', 'Virtualization', 'state', 'done'),
        JSON_OBJECT('name', 'Linux Engineering', 'state', 'done'),
        JSON_OBJECT('name', 'Security Engineering', 'state', 'done'),
        JSON_OBJECT('name', 'Directory Services', 'state', 'done'),
        JSON_OBJECT('name', 'PKI / Certificate', 'state', 'done'),
        JSON_OBJECT('name', 'Network Security', 'state', 'done'),
        JSON_OBJECT('name', 'Service Desk', 'state', 'done')
    ),
    NULL,
    DATE_SUB(NOW(), INTERVAL 7 DAY)
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
    JSON_ARRAY(
        JSON_OBJECT('name', 'Virtualization', 'state', 'ready'),
        JSON_OBJECT('name', 'Linux Engineering', 'state', 'pending'),
        JSON_OBJECT('name', 'Security Engineering', 'state', 'pending'),
        JSON_OBJECT('name', 'Directory Services', 'state', 'pending'),
        JSON_OBJECT('name', 'PKI / Certificate', 'state', 'pending'),
        JSON_OBJECT('name', 'Network Security', 'state', 'pending'),
        JSON_OBJECT('name', 'Service Desk', 'state', 'pending')
    ),
    NULL,
    DATE_SUB(NOW(), INTERVAL 2 DAY)
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
