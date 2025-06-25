# TODO-TASKS.md

## 🎯 Project Overview
**App Name:** LKUI — License Key UI for SSL CSR Gen, Renewal and Retrieval.  
**Goal:**  
- REST API that supports full CSR/Order/Cert lifecycle.
- Track hosts using RHEL6, RHEL7, RHEL8 templates (`*.example.com` default).
- **No direct connection to remote servers** — all certs and CSRs served to client.
- Clients or Ansible playbooks will retrieve certs and deploy them.

---

## 🧾 Tasks

### 1️⃣ Base Application Setup (Review `ApplicationTasks.php` for the following)
1. Configure `composer.json` to support `composer install-lkui` as a new UI template.
2. Create `LKUI` directories (inside .installer directory):
   - `app/Controllers/`
   - `app/Models/`
   - `app/Views/`
   - `app/CustomRoutes.php`
3. Implement `LKUIServiceProvider` to register MVC components.
4. Mock `composer install-mvc` behavior for `LKUI` in `ApplicationTasks.php` (no server calls).

---
### 2️⃣ Default Templates (RHEL6,7,8) - stored in PostGresSQL.
Prerequisite task for the following step: Write an SQL script for default templates used by the following controller. Implement .env.example a podman-compose.yml for this.
5. Implement `TemplatesController`:
   - `listTemplates()` returns a list of templates.
   - `getTemplate(templateName)` returns template data (`common_name='*.example.com'`).
6. Implement `TemplateModel` schema:
   - `id`, `name`, `common_name`, `csr_options` (e.g. key type, size).
7. Implement `TemplateSeeder` to populate RHEL6/7/8 defaults.

---

### 3️⃣ Add Host Workflow
8. Implement `HostController@createHost()`:
   - Input: template ID and optional `common_name`.
   - Generate CSR for the given template (`*.example.com` by default).
   - Save record to `HostModel`: `status='CSR_GENERATED'`.
9. Implement `HostModel` schema:
   - `id`, `template_id`, `csr_content`, `common_name`, `status`.
10. Implement `HostController@listHosts()` and `getHost(id)` to retrieve host data.

---

### 4️⃣ Order Management (CRUD)
11. Implement `OrderController`:
   - `createOrder(host_id)` — sets status `ORDER_PENDING`.
   - `updateOrder(host_id, cert_content)` — set status `ORDER_COMPLETED`.
   - `getOrder(host_id)` — return order status, cert if present.
12. Implement `OrderModel` schema:
   - `id`, `host_id`, `status`, `cert_content`, `issued_at`.
13. Validate certs and CA chain on `updateOrder()`.

---

### 5️⃣ REST API Endpoints Create a CustomRoutes.php installed during "composer install-lkui" (Example: .installer/lkgui/routes/CustomRoutes.php  for LKUI installer initialized by "composer install-lkui" - copy "composer install-mvc" for the real-world data structure of mvc assets.)

Maybe we can allow users to generate CSR from a page with a simple form field for '*.example.com'.

14. Create `routes`:
   - `POST /lkui/api/hosts`
   - `GET /lkui/api/hosts/:id`
   - `POST /lkui/api/orders`
   - `GET /lkui/api/orders/:id`
   - `POST /lkui/api/orders/:id/certificate`
   I may have forgotten an API endpoint for CSR generation, so add that and update this task list.
15. Implement authentication/token mechanism for secure API (e.g. API key or JWT).

---

### 6️⃣ Web UI 
16. Implement `/hosts` view for list of hosts. GET
17. Implement `/hosts` for host creation form. POST
18. Implement `/orders` view for order status & uploading cert. (any ops needed, e.g. GET, PUT, POST)
19. Implement success and error flash messages.

---

### 7️⃣ Installing Certs
> CRUD Operations only.
> **No direct remote calls to hosts** — provide downloadable certs and API CRUD operations that will be used by a standardized deploy_cert.sh script.
> Server-side tasks (installing certs) will happen outside this app.
> Suggest customers use an Ansible playbook or your provided deployment script:


---
Write the deploy script.
The following is an example of how we might deploy the cert via a shell script. 

./deploy_cert.sh user@target-host


20. Document the Ansible role that:
    - Fetches certs via LKUI REST API (`curl` GET /api/orders/:id). Maybe the User ID is supplied, so user doesn't have to paste a URL.
    - Deploys certs & keys to `/etc/pki/tls/`.
    - Runs `update-ca-trust` or `update-ca-certificates`.
21. Test manual `curl` commands to retrieve CSR/cert data.

---

### 8️⃣ Administrative and Security
22. Implement auth for web UI (basic login or SSO).
23. Implement rate-limiting and validation on all endpoints.
24. Implement exception handling and logging (`monolog/monolog` recommended under Apache 2 license).

---

### 9️⃣ Post-Install / Maintenance
25. Implement `composer post-install-cmd` to list available scripts.
26. Implement `composer unlock` to clear any lock files (`src/.lock/app.lock`).
27. Write PHPUnit tests for all Controllers and Models.
28. Document the setup and usage in `README.md`.
29. Create a versioned `CHANGELOG.md`.
30. Prepare deployment scripts and setup instructions.

---

🎯 **End of TODO-TASKS.md**  