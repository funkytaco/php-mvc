# Postgres identifier naming — the hyphen gotcha

## The setup

Nimbus app names are hyphenated by convention (`yo-sup`, `my-app`). When an app is
created, its name is turned into Postgres identifiers by appending a suffix with
an underscore:

```yaml
POSTGRES_DB: yo-sup_db
POSTGRES_USER: yo-sup_user
```

See `AppManager::createFromTemplate()` / the compose-generation methods in
`src/Nimbus/App/AppManager.php` for where `{{DB_NAME}}` / `{{DB_USER}}` are resolved
to `$appName . '_db'` / `$appName . '_user'`.

## Why this is a problem

Postgres identifiers (unquoted) may only contain letters, digits, and underscores,
and must start with a letter or underscore. **A hyphen is not a legal unquoted
identifier character.**

`yo-sup_db` is therefore not a valid *unquoted* Postgres identifier — Postgres will
parse `yo`, then hit `-`, and throw a syntax error, unless the identifier is wrapped
in double quotes:

```sql
CREATE DATABASE "yo-sup_db";      -- legal
CREATE DATABASE yo-sup_db;        -- ERROR: syntax error at or near "-"
```

## Why it works today anyway

`/docker-entrypoint-initdb.d/` is a special directory baked into the **official
`postgres:*` Docker image**. Its entrypoint script scans that directory on first
container startup (before the database accepts connections) and executes any
`.sql` / `.sh` files found there.

Nimbus mounts a generated `init.sql` into that path via the compose file (see
`AppManager.php`, the `volumes:` block that maps
`./database/init.sql:/docker-entrypoint-initdb.d/init.sql:ro`).

The official image's startup logic quotes the `POSTGRES_DB` / `POSTGRES_USER`
environment variables before using them in the `CREATE DATABASE` / `CREATE USER`
statements it runs. That quoting is what makes a hyphenated identifier like
`yo-sup_db` work — the quoting happens **inside the base image**, not in
Nimbus's own code.

## The landmine

Nimbus's generated code and templates never interpolate these identifiers
unquoted. But if custom SQL, a migration script, or a hand-written query does:

```php
$conn->exec("GRANT ALL PRIVILEGES ON DATABASE " . $dbName . " TO " . $dbUser);
```

with `$dbName = 'yo-sup_db'` and `$dbUser = 'yo-sup_user'`, Postgres will reject it:

```
ERROR:  syntax error at or near "_"
```

(Actually fails at the hyphen boundary — the "_" in the message may vary depending
on how the parser tokenizes, but the underlying cause is the same: an unquoted
identifier containing a hyphen.)

## Guidance

- Never hand-interpolate `$appName`-derived DB identifiers into raw SQL.
- If you must build SQL dynamically, always double-quote identifiers:
  `"$dbName"`, `"$dbUser"`.
- Prefer parameterized queries / prepared statements wherever the identifier
  is data rather than schema (though note: identifiers generally can't be
  bound as query parameters in Postgres — quoting is the only real option
  for dynamic identifiers).
- This is not currently a bug in the shipped Nimbus code — it's a landmine
  for anyone adding custom SQL against a generated app's database.

## Not fixed / out of scope

This document exists to record the hazard, not to change behavior. Changing
the naming scheme (e.g. replacing hyphens with underscores for DB identifiers
while keeping hyphens in the app/container name) would be a bigger, separate
change and is not implemented here.
