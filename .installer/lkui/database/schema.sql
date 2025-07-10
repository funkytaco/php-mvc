-- LKUI Database Schema
-- License Key UI for SSL CSR Generation, Renewal and Retrieval

-- Create database if not exists
-- CREATE DATABASE IF NOT EXISTS lkui;
-- USE lkui;

-- Templates table for SSL certificate deployment configurations
CREATE TABLE IF NOT EXISTS templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    os_version VARCHAR(50),
    common_name VARCHAR(255) NOT NULL DEFAULT '*.example.com',
    csr_options TEXT, -- JSON string containing CSR configuration
    cert_path VARCHAR(500) NOT NULL, -- SSL certificate deployment path
    key_path VARCHAR(500) NOT NULL, -- Private key deployment path
    ca_path VARCHAR(500), -- CA certificate path
    ca_enabled BOOLEAN DEFAULT FALSE, -- Whether to deploy CA certificate
    service_restart_command VARCHAR(255), -- Command to restart service after cert deployment
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Hosts table for tracking CSR generation
CREATE TABLE IF NOT EXISTS hosts (
    id SERIAL PRIMARY KEY,
    template_id INTEGER REFERENCES templates(id) ON DELETE CASCADE,
    common_name VARCHAR(255) NOT NULL,
    csr_content TEXT, -- PEM formatted CSR
    private_key TEXT, -- PEM formatted private key
    status VARCHAR(50) DEFAULT 'CSR_GENERATED',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Orders table for certificate management
-- Supports order types (certbot, letsencrypt, self-signed, external, imported)
-- and renewal relationships via is_renewal + renewal_of_id
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    host_id INTEGER REFERENCES hosts(id) ON DELETE CASCADE,
    order_type VARCHAR(50) NOT NULL,
    is_renewal BOOLEAN DEFAULT FALSE,
    renewal_of_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
    status VARCHAR(50) DEFAULT 'ORDER_PENDING',
    cert_content TEXT,
    issued_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Order updates tracking table
CREATE TABLE IF NOT EXISTS order_updates (
    id SERIAL PRIMARY KEY,
    order_id INTEGER REFERENCES orders(id) ON DELETE CASCADE,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Expiry updates tracking table (limited to 5 rows)
CREATE TABLE IF NOT EXISTS expiry_updates (
    id SERIAL PRIMARY KEY,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Function to maintain 5-row limit for expiry_updates
CREATE OR REPLACE FUNCTION maintain_expiry_updates_limit()
RETURNS TRIGGER AS $$
BEGIN
    -- Delete oldest records if we exceed 5 rows
    DELETE FROM expiry_updates
    WHERE id NOT IN (
        SELECT id FROM expiry_updates
        ORDER BY created_at DESC
        LIMIT 5
    );
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Trigger to maintain 5-row limit
CREATE TRIGGER maintain_expiry_updates_limit_trigger
    AFTER INSERT ON expiry_updates
    FOR EACH ROW EXECUTE FUNCTION maintain_expiry_updates_limit();

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_hosts_template_id ON hosts(template_id);
CREATE INDEX IF NOT EXISTS idx_hosts_status ON hosts(status);
CREATE INDEX IF NOT EXISTS idx_orders_host_id ON orders(host_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);

-- Insert default templates
INSERT INTO templates (name, description, os_version, common_name, csr_options, cert_path, key_path, ca_path, ca_enabled, service_restart_command) VALUES 
('RHEL6', 'Red Hat Enterprise Linux 6 default SSL configuration', 'RHEL 6', '*.example.com', '{"key_type":"RSA","key_size":2048,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
('RHEL7', 'Red Hat Enterprise Linux 7 default SSL configuration', 'RHEL 7', '*.example.com', '{"key_type":"RSA","key_size":2048,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
('RHEL8', 'Red Hat Enterprise Linux 8 default SSL configuration', 'RHEL 8', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
('RHEL9', 'Red Hat Enterprise Linux 9 default SSL configuration', 'RHEL 9', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
('RHEL10', 'Red Hat Enterprise Linux 10 default SSL configuration', 'RHEL 10', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
('AAP', 'Ansible Automation Platform SSL configuration', 'RHEL 8+', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/tower/tower.cert', '/etc/tower/tower.key', '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem', false, 'systemctl restart automation-controller'),
('SATELLITE', 'Red Hat Satellite SSL configuration', 'RHEL 8+', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/usr/share/katello/certs/katello-apache.crt', '/usr/share/katello/certs/katello-apache.key', '/usr/share/katello/certs/katello-default-ca.crt', false, 'systemctl restart httpd'),
('GITLAB', 'GitLab SSL configuration', 'RHEL 7+', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/gitlab/ssl/gitlab.example.com.crt', '/etc/gitlab/ssl/gitlab.example.com.key', '/opt/gitlab/embedded/ssl/certs/cacert.pem', false, 'gitlab-ctl restart'),
('COCKPIT', 'RHEL Cockpit SSL configuration', 'RHEL 7+', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/cockpit/ws-certs.d/0-self-signed.cert', '/etc/cockpit/ws-certs.d/0-self-signed.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart cockpit')
ON CONFLICT (name) DO NOTHING;

-- Create a function to update the updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Certificate expiry tracking table
CREATE TABLE IF NOT EXISTS certificate_expiry (
    id SERIAL PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    expiry_date TIMESTAMP NOT NULL,
    days_remaining INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create triggers to automatically update updated_at
CREATE TRIGGER update_hosts_updated_at BEFORE UPDATE ON hosts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_orders_updated_at BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_certificate_expiry_updated_at BEFORE UPDATE ON certificate_expiry
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
