-- LKUI Database Schema
-- License Key UI for SSL CSR Generation, Renewal and Retrieval

-- Create database if not exists
-- CREATE DATABASE IF NOT EXISTS lkui;
-- USE lkui;

-- Templates table for RHEL6, RHEL7, RHEL8 defaults
CREATE TABLE IF NOT EXISTS templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    common_name VARCHAR(255) NOT NULL DEFAULT '*.example.com',
    csr_options TEXT, -- JSON string containing CSR configuration
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

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_hosts_template_id ON hosts(template_id);
CREATE INDEX IF NOT EXISTS idx_hosts_status ON hosts(status);
CREATE INDEX IF NOT EXISTS idx_orders_host_id ON orders(host_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);

-- Insert default templates
INSERT INTO templates (name, common_name, csr_options) VALUES 
('RHEL6', '*.example.com', '{"key_type":"RSA","key_size":2048,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}'),
('RHEL7', '*.example.com', '{"key_type":"RSA","key_size":2048,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}'),
('RHEL8', '*.example.com', '{"key_type":"RSA","key_size":4096,"digest_alg":"sha256","organization":"Example Organization","organizational_unit":"IT Department","locality":"City","state":"State","country":"US"}')
ON CONFLICT (name) DO NOTHING;

-- Create a function to update the updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create triggers to automatically update updated_at
CREATE TRIGGER update_hosts_updated_at BEFORE UPDATE ON hosts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_orders_updated_at BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
