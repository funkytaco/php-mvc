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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_hosts_template_id ON hosts(template_id);


-- Insert default templates
INSERT INTO templates (name, description, os_version, common_name, csr_options, cert_path, key_path, ca_path, ca_enabled, service_restart_command) VALUES 
('RHEL6', 'Red Hat Enterprise Linux 6 default SSL configuration', 'RHEL 6', '*.example.com', '{"key_type":"RSA","key_size":2048,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}', '/etc/pki/tls/certs/localhost.crt', '/etc/pki/tls/private/localhost.key', '/etc/pki/tls/certs/ca-bundle.crt', false, 'systemctl restart httpd'),
ON CONFLICT (name) DO NOTHING;

-- Create a function to update the updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';