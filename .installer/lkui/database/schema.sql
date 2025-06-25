-- Database schema for License Key UI
CREATE TABLE templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    common_name VARCHAR(255) NOT NULL,
    csr_options JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE hosts (
    id SERIAL PRIMARY KEY,
    template_id INTEGER REFERENCES templates(id) NOT NULL,
    common_name VARCHAR(255) NOT NULL,
    csr_content TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    host_id INTEGER REFERENCES hosts(id) NOT NULL,
    cert_content TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    issued_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default RHEL templates
INSERT INTO templates (name, common_name, csr_options) VALUES
('rhel6', 'RHEL 6', '{"country":"US","state":"California","locality":"San Francisco","organization":"Example Inc","organizationalUnit":"IT","email":"admin@example.com"}'),
('rhel7', 'RHEL 7', '{"country":"US","state":"California","locality":"San Francisco","organization":"Example Inc","organizationalUnit":"IT","email":"admin@example.com"}'),
('rhel8', 'RHEL 8', '{"country":"US","state":"California","locality":"San Francisco","organization":"Example Inc","organizationalUnit":"IT","email":"admin@example.com"}');
