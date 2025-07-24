<?php

namespace Nimbus\Password;

/**
 * Password resolution strategies for Nimbus apps
 */
enum PasswordStrategy: string
{
    case VAULT_RESTORE = 'vault_restore';
    case EXISTING_DATA = 'existing_data';
    case GENERATE_NEW = 'generate_new';
    
    public function getDescription(): string
    {
        return match($this) {
            self::VAULT_RESTORE => 'Restore passwords from vault',
            self::EXISTING_DATA => 'Extract passwords from existing data',
            self::GENERATE_NEW => 'Generate new random passwords'
        };
    }
    
    public function requiresForceInit(): bool
    {
        return $this === self::VAULT_RESTORE;
    }
}