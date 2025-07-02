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

returns:
�2025-07-02 01:57:55,584 - aiohttp.access - INFO - 127.0.0.1 [02/Jul/2025:01:57:55 +0000] "POST /ssl-order HTTP/1.1" 200 159 "-" "curl/7.76.1"

Probably because nothing in rulebook to do. 

#working with rulebook:

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

  PLAY [Order SSL certificate with certbot (EDA triggered)] **********************

TASK [Ensure python3 and pip installed] ****************************************
ok: [localhost]

TASK [Install certbot via pip3] ************************************************
ok: [localhost]

TASK [Run certbot with http-01 validation (standalone)] ************************
�fatal: [localhost]: FAILED! => {"changed": true, "cmd": ["certbot", "certonly", "--standalone", "--non-interactive", "--agree-tos", "-m", "admin@example.com", "-d", "foo.example.com"], "delta": "0:00:00.769921", "end": "2025-07-02 01:59:16.790627", "msg": "non-zero return code", "rc": 1, "start": "2025-07-02 01:59:16.020706", "stderr": "Saving debug log to /var/log/letsencrypt/letsencrypt.log\nUnable to register an account with ACME server. The ACME server believes admin@example.com is an invalid email address. Please ensure it is a valid email and attempt registration again.\nAsk for help or search for solutions at https://community.letsencrypt.org. See the logfile /var/log/letsencrypt/letsencrypt.log or re-run Certbot with -v for more details.", "stderr_lines": ["Saving debug log to /var/log/letsencrypt/letsencrypt.log", "Unable to register an account with ACME server. The ACME server believes admin@example.com is an invalid email address. Please ensure it is a valid email and attempt registration again.", "Ask for help or search for solutions at https://community.letsencrypt.org. See the logfile /var/log/letsencrypt/letsencrypt.log or re-run Certbot with -v for more details."], "stdout": "", "stdout_lines": []}
...ignoring

TASK [Run certbot with manual dns-01 challenge] ********************************
skipping: [localhost]

TASK [Debug output on success] *************************************************
skipping: [localhost]

TASK [Post failure to order endpoint] ******************************************
ok: [localhost]

PLAY RECAP *********************************************************************
localhost                  : ok=4    changed=1    unreachable=0    failed=0    skipped=2    rescued=0    ignored=1   
2025-07-02 01:59:17,291 - ansible_rulebook.action.runner - INFO - Ansible runner Queue task cancelled
2025-07-02 01:59:17,292 - ansible_rulebook.action.run_playbook - INFO - Ansible runner rc: 0, status: successful

