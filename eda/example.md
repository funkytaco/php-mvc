#http

curl -X POST http://localhost:5000/ssl-order \
  -H "Content-Type: application/json" \
  -d '{
    "action": "order_ssl_certbot",
    "csr": "-----BEGIN CERTIFICATE REQUEST-----\nMIIBvTCCASYCAQAwejELMAkGA1UEBhMCVVMxCzAJBgNVBAgMAkNBMRYwFAYDVQQH\nDA1TYW4gRnJhbmNpc2NvMRAwDgYDVQQKDAdDb21wYW55MRAwDgYDVQQLDAdTZWN0\naW9uMSIwIAYDVQQDDBlleGFtcGxlLmNvbSBDZXJ0aWZpY2F0ZQ==\n-----END CERTIFICATE REQUEST-----",
    "domain": "foo.example.com",
    "email": "admin@example.com",
    "order_id": "ssl_12345",
    "timestamp": "2025-06-27T20:30:00Z",
    "certificate_authority": "certbot",
    "validation_method": "http",
    "acme_version": "N/A"
  }'

#dns
curl -X POST http://localhost:5000/ssl-order \
  -H "Content-Type: application/json" \
  -d '{
    "action": "order_ssl_letencrypt",
    "csr": "-----BEGIN CERTIFICATE REQUEST-----\nMIIBvTCCASYCAQAwejELMAkGA1UEBhMCVVMxCzAJBgNVBAgMAkNBMRYwFAYDVQQH\nDA1TYW4gRnJhbmNpc2NvMRAwDgYDVQQKDAdDb21wYW55MRAwDgYDVQQLDAdTZWN0\naW9uMSIwIAYDVQQDDBlleGFtcGxlLmNvbSBDZXJ0aWZpY2F0ZQ==\n-----END CERTIFICATE REQUEST-----",
    "domain": "example.com",
    "email": "admin@example.com",
    "order_id": "ssl_12345",
    "timestamp": "2025-06-27T20:30:00Z",
    "certificate_authority": "certbot",
    "validation_method": "dns"
  }'



  curl -X POST http://localhost:5000/ssl-order \
  -H "Content-Type: application/json" \
  -d '{
    "action": "order_ssl",
    "csr": "-----BEGIN CERTIFICATE REQUEST-----\nMIIBvTCCASYCAQAwejELMAkGA1UEBhMCVVMxCzAJBgNVBAgMAkNBMRYwFAYDVQQH\nDA1TYW4gRnJhbmNpc2NvMRAwDgYDVQQKDAdDb21wYW55MRAwDgYDVQQLDAdTZWN0\naW9uMSIwIAYDVQQDDBlleGFtcGxlLmNvbSBDZXJ0aWZpY2F0ZQ==\n-----END CERTIFICATE REQUEST-----",
    "domain": "example.com",
    "email": "admin@example.com",
    "order_id": "ssl_12345",
    "timestamp": "2025-06-27T20:30:00Z",
    "certificate_authority": "letsencrypt",
    "validation_method": "dns"
  }'