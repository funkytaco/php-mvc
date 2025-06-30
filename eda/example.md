#http

curl -X POST http://localhost:5000/ssl-order \
  -H "Content-Type: application/json" \
  -d '{
    "action": "order_ssl_certbot",
    "csr": "-----BEGIN CERTIFICATE REQUEST-----\nMIICyjCCAbICAQAwgYQxHzAdBgNVBAMMFnJoZWw4LWFwcDEuZXhhbXBsZS5jb20xHTAbBgNVBAoMFEV4YW1wbGUgT3JnYW5pemF0aW9uMRYwFAYDVQQLDA1JVCBEZXBcnRtZW50MQ0wCwYDVQQHDARDaXR5MQ4wDAYDVQQIDAVTdGF0ZTELMAkGA1UEBhMCVVMwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDIqC6udIKk3rB/e/NoqJQJU+5ee3xE8vcXCPg6XxO238o/TTQHzr1/opRH9B5PxDyxumFHj4hjHsevEFj+TzzhghkMiMN1QZza5OpoNk23eUd+iKeUchq+lQwZ4h0y6Q4AD7NTK4nEujghNOpuqbPveLWgjOaBPyP0XJgn6102/FsRlZXfKIhcRKyxys5xvtKwx96uQdPn+uVBHwGmIGtHP+vcCajLvzlwgiR2HGnIRYGRgNEXu4vifD3J1AzcW0XSNL4XfoWGK6LUO3OCAOIEgIRMeqNGqJaG+rPf3LtDddWrgVSxopEMhXdn4GwDM8BIsf2D/F1ffhcJ1cX/s5YBAgMBAAGgADANBgkqhkiG9w0BAQsFAAOCAQEAdVUCWcPVifQLLNkYxZrfnirPJ3rG7Q6yAYeF4rVed2ePLactljzhOg5kuXDe3GqEPK0lWOjTMPhT6A6H2QCFiUXcJCXztDn768l2sZD0+Ch7XqosupKdTekDz5i+npotQJC8QuZZi6bPytAOwrhFDXJuuqOckWs+GOgfidnpC6FYxOj8fLHiCTd+YNp/+jke+b1P7ZmBx0fMTeHDvcn2xC7EQdwFojEXcf46xvjMxhzCXykwnZdi3g4DZxh5joVoK6hSl9yhZbtTQ4Ze1nwlqM0px5ghfGwFr7c3NEjNkvWhSGC2bun2lHicIGqa8KE7gDxkn85UYYxYeeF9YSeBEw==\n-----END CERTIFICATE REQUEST-----",
    "domain": "foo.example.com",
    "email": "admin@example.com",
    "order_id": "4",
    "timestamp": "2025-06-28T20:30:00Z",
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