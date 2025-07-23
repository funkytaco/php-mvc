# Nimbus Credential Vault

🔐 **Enterprise-grade credential management for Nimbus applications using Ansible Vault**

## Overview

The Nimbus Vault system solves the **password race condition** problem that occurs when deleting and recreating apps with persistent database data. By using Ansible Vault as an encrypted credential source-of-truth, all app passwords are preserved across deletion/recreation cycles.

## The Problem We Solve

### Before Nimbus Vault
```bash
composer nimbus:create myapp1     # Creates app with password: abc123
composer nimbus:delete myapp1     # Deletes app but leaves data/ directory
composer nimbus:create myapp1     # Creates app with NEW password: xyz789
# 💥 RESULT: Password mismatch - app can't connect to existing database
```

### After Nimbus Vault
```bash
composer nimbus:create myapp1     # Creates app with password: abc123
composer nimbus:delete myapp1     # Backs up credentials to encrypted vault
composer nimbus:create myapp1     # Restores SAME password: abc123
# ✅ RESULT: App connects seamlessly to existing database
```

## Architecture

### Vault Structure
```yaml
# .installer/vault/credentials.yml (encrypted with Ansible Vault)
apps:
  myapp0:
    database:
      password: "bfdaff047648cc86ceb93fbcd648069f"
      user: "myapp0_user"
      name: "myapp0_db"
      created: "2025-01-23T14:32:00Z"
    keycloak:
      admin_password: "d7078eb27c165dd0c3fbe5e1325109f9"
      db_password: "fd9e4d46474948bc9992d2b5855badfb"
      client_secret: "3F@H5Vep&XStAJ8Ru45x(%PatWUKxXI2"
      created: "2025-01-23T14:32:00Z"
    backed_up_at: "2025-01-23T15:45:22+00:00"
    backup_version: "1.0"
```

### Security Features
- **AES-256 Encryption** via Ansible Vault
- **Master Password Protection** stored in `.installer/vault/.vault_pass`
- **Containerized Operations** - no local ansible-vault installation required
- **Secure File Permissions** (0600/0700) on vault files
- **Automatic Cleanup** of temporary files during operations

## Quick Start

### 1. Initialize the Vault
```bash
composer nimbus:vault-init
```
**Output:**
```
🔐 Initializing Nimbus Credential Vault...
Generated vault master password: X8#mK9$qP2vN&7wE4@tR6uY1sA3cF5gH
IMPORTANT: Store this password securely - you'll need it to access credentials!
✅ Vault initialized successfully!

💡 Usage:
  composer nimbus:vault-backup <app>   - Backup app credentials
  composer nimbus:vault-restore <app>  - Restore app credentials
  composer nimbus:vault-list           - List backed up apps
```

### 2. Normal App Lifecycle with Automatic Vault Integration

**Create an app:**
```bash
composer nimbus:create myapp1
```

**Delete with credential backup:**
```bash
composer nimbus:delete myapp1
```
**Interactive Prompt:**
```
⚠️  This will PERMANENTLY delete:
  - App directory: /path/to/.installer/apps/myapp1
  - Compose file: /path/to/myapp1-compose.yml
  - Any associated containers and volumes

🔐 Backup credentials to vault before deleting? [Y/n]: y
📊 Backing up credentials for 'myapp1'...
✅ Credentials backed up to vault!
  📊 Database password: ✓
  🔐 Keycloak admin password: ✓
  🔐 Keycloak DB password: ✓
  🔐 Keycloak client secret: ✓

⚠️  Are you sure you want to delete this app? [y/N]: y
```

**Recreate with restored credentials:**
```bash
composer nimbus:create myapp1
```
**Output:**
```
🔐 Found backed up credentials for 'myapp1' in vault!
  📊 Database password: bfdaff04...
  🔐 Keycloak passwords: ✓
💡 These credentials will be restored automatically.

✅ App 'myapp1' created successfully from template 'nimbus-demo'!
```

## Vault Management Commands

### View Backed Up Apps
```bash
composer nimbus:vault-list
```
**Output:**
```
🔐 Apps with backed up credentials:

  📱 myapp0
     Backed up: 2025-01-23T14:32:15+00:00
     Database: ✓
     Keycloak: ✓

  📱 myapp1  
     Backed up: 2025-01-23T15:45:22+00:00
     Database: ✓
     Keycloak: ✗

💡 Restore credentials with: composer nimbus:vault-restore <app-name>
```

### Manual Credential Backup
```bash
composer nimbus:vault-backup myapp0
```
**Output:**
```
🔐 Backing up credentials for 'myapp0'...
✅ Credentials backed up successfully!

  📊 Database password: ✓
  🔐 Keycloak admin password: ✓
  🔐 Keycloak DB password: ✓
  🔐 Keycloak client secret: ✓
```

### View Stored Credentials
```bash
composer nimbus:vault-restore myapp0
```
**Output:**
```
🔐 Found credentials for 'myapp0' in vault:

  📊 Database password: bfdaff04...
  🔐 Keycloak admin password: d7078eb2...
  🔐 Keycloak DB password: fd9e4d46...

💡 These credentials will be used when creating the app with:
  composer nimbus:create myapp0
```

## Technical Implementation

### VaultManager Class
Location: `src/Nimbus/Vault/VaultManager.php`

**Key Methods:**
- `initializeVault()` - Creates encrypted vault with master password
- `backupAppCredentials()` - Extracts and stores app credentials
- `restoreAppCredentials()` - Retrieves credentials from vault
- `extractAppCredentials()` - Gets live credentials from running containers

### AppManager Integration
Location: `src/Nimbus/App/AppManager.php`

**Enhanced Methods:**
- `getVaultCredentials()` - Checks vault before generating new passwords
- `createFromTemplate()` - Priority: Vault → Existing Data → New Passwords

### Credential Priority Order
1. **Vault credentials** (if available)
2. **Existing data directory passwords** (backward compatibility)
3. **Generated random passwords** (new apps)

## Containerized Ansible Vault

The system uses containerized Ansible Vault operations to avoid requiring local installations:

```bash
# Container command example
podman run --rm \
  -v '.installer/vault:/vault:Z' \
  -w /vault \
  quay.io/ansible/ansible-runner:latest \
  sh -c 'echo "$PASSWORD" | ansible-vault encrypt --vault-password-file /dev/stdin credentials.yml'
```

**Benefits:**
- ✅ No local ansible-vault installation required
- ✅ Consistent encryption across environments  
- ✅ Isolated operations with proper cleanup
- ✅ Works on any system with Podman/Docker

## File Structure

```
.installer/
├── vault/
│   ├── credentials.yml       # Encrypted credential storage
│   └── .vault_pass          # Master password (secure permissions)
├── apps/
│   └── myapp1/              # App configurations
└── _templates/              # App templates
```

## Security Considerations

### Vault Password Security
- **Master password** is auto-generated (32 characters)
- **File permissions** set to 0600 (owner read/write only)
- **Store securely** - needed for manual vault operations
- **Backup recommended** - losing it means losing access to credentials

### Access Control
- Vault files are created with restrictive permissions
- Only the user who created the vault can access it
- Container operations run with limited privileges

### Backup Strategy
```bash
# Backup vault files (recommended)
cp -r .installer/vault/ ~/secure-backup/nimbus-vault-$(date +%Y%m%d)/

# Or export specific app credentials
composer nimbus:vault-restore myapp1 > myapp1-credentials.txt
```

## Troubleshooting

### Vault Not Initialized
**Error:** `Vault not initialized. Run: composer nimbus:vault-init`
**Solution:** Initialize the vault first:
```bash
composer nimbus:vault-init
```

### No Credentials Found
**Error:** `No credentials found for 'myapp1'. App may not be running.`
**Solutions:**
1. Ensure app containers are running before backup
2. Check that app was created with vault-compatible configuration
3. Manually start containers: `composer nimbus:up myapp1`

### Decryption Failed
**Error:** `Failed to decrypt vault credentials`
**Solutions:**
1. Check vault password file exists: `ls -la .installer/vault/.vault_pass`
2. Verify file permissions are correct (0600)
3. Re-initialize vault if password is lost: `composer nimbus:vault-init`

### Container Issues
**Error:** Issues with containerized ansible-vault
**Solutions:**
1. Ensure Podman/Docker is running
2. Check container image availability: `podman pull quay.io/ansible/ansible-runner:latest`
3. Verify vault directory permissions

## Advanced Usage

### Multiple Environment Vaults
```bash
# Development vault
export NIMBUS_VAULT_DIR=".installer/vault-dev"
composer nimbus:vault-init

# Production vault  
export NIMBUS_VAULT_DIR=".installer/vault-prod"
composer nimbus:vault-init
```

### Batch Operations
```bash
# Backup all running apps
for app in $(composer nimbus:list --quiet); do
  composer nimbus:vault-backup $app
done

# Restore all apps from vault
composer nimbus:vault-list --quiet | while read app; do
  composer nimbus:create $app
done
```

### Integration with CI/CD
```yaml
# .github/workflows/deploy.yml
- name: Initialize Nimbus Vault
  run: |
    echo "$VAULT_PASSWORD" > .installer/vault/.vault_pass
    chmod 600 .installer/vault/.vault_pass
    
- name: Deploy with credential restore
  run: |
    composer nimbus:create ${{ matrix.app }}
    composer nimbus:up ${{ matrix.app }}
```

## Migration from Legacy Apps

For existing apps created before vault implementation:

```bash
# 1. Start existing app to extract credentials
composer nimbus:up myapp1

# 2. Backup credentials to vault
composer nimbus:vault-backup myapp1

# 3. Test vault integration
composer nimbus:delete myapp1  # Credentials already backed up
composer nimbus:create myapp1  # Should restore from vault

# 4. Verify app connects successfully
curl http://localhost:8565/
```

## Best Practices

### 🔐 **Security**
- Initialize vault immediately after Nimbus setup
- Store master password in secure password manager
- Regular vault backups to secure location
- Rotate credentials periodically using vault commands

### 🚀 **Operations**  
- Always backup credentials before major changes
- Use vault for all production deployments
- Test credential restoration in staging environments
- Monitor vault file integrity

### 🛠️ **Development**
- Each developer should have their own vault
- Never commit vault password to version control
- Use vault for consistent local development environments
- Document team vault management procedures

## Future Enhancements

- **Multi-user vault access** with role-based permissions
- **Credential rotation automation** with configurable schedules  
- **Integration with external secret managers** (HashiCorp Vault, AWS Secrets Manager)
- **Audit logging** for credential access and modifications
- **Backup verification** and restoration testing automation

---

The Nimbus Vault system transforms credential management from a manual, error-prone process into a seamless, secure, and automated workflow. No more password race conditions, no more database connection failures, no more manual credential tracking.

**🔐 Your credentials are safe. Your deployments are reliable. Your developers are productive.**