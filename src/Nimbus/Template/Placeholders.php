<?php

declare(strict_types=1);

namespace Nimbus\Template;

/**
 * The scaffolding placeholder vocabulary — the {{TOKEN}}s that
 * AppManager::generateAppConfigWithPasswords() substitutes at create time.
 * A {{TOKEN}} outside this list is never replaced and ships verbatim
 * into the generated app.
 */
final class Placeholders
{
    /**
     * token => dummy value of the right shape, used when a checker needs to
     * substitute before parsing (unquoted tokens like 'eda_port' => {{EDA_PORT}}
     * are not valid PHP until replaced).
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        'APP_NAME' => 'checkapp',
        'APP_NAME_UPPER' => 'CHECKAPP',
        'APP_NAME_LOWER' => 'checkapp',
        'APP_PORT' => '8080',
        'EDA_PORT' => '5000',
        'DB_NAME' => 'checkapp_db',
        'DB_USER' => 'checkapp_user',
        'DB_PASSWORD' => 'checkpass',
        'HAS_EDA' => 'true',
        'KEYCLOAK_ENABLED' => 'true',
        'KEYCLOAK_PORT' => '8081',
        'KEYCLOAK_REALM' => 'checkapp-realm',
        'KEYCLOAK_CLIENT_ID' => 'checkapp-client',
        'KEYCLOAK_CLIENT_SECRET' => 'checksecret',
        'KEYCLOAK_ADMIN_PASSWORD' => 'checkpass',
        'KEYCLOAK_DB_PASSWORD' => 'checkpass',
    ];

    /**
     * Replace every known token with its stand-in value.
     */
    public static function substitute(string $content): string
    {
        foreach (self::PLACEHOLDERS as $token => $value) {
            $content = str_replace('{{' . $token . '}}', $value, $content);
        }

        return $content;
    }

    /**
     * Is this mustache variable name really a scaffolding token ({{APP_NAME}})
     * rather than a view variable?
     */
    public static function isToken(string $name): bool
    {
        return isset(self::PLACEHOLDERS[$name]);
    }
}
