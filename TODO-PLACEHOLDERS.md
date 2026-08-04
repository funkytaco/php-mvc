# TODO: scaffolding placeholders vs. Mustache runtime variables

Status doc for a known ambiguity in the templates. Nothing here is broken at
runtime today, but one lint false-positive and two fragile-by-luck patterns
come from the same root cause. Verified against the code on 2026-07-31.

## The root cause

Nimbus has three templating systems, and two of them share `{{ }}`:

| System | Delimiters | Applied by | When |
|---|---|---|---|
| Scaffolding placeholders (`{{APP_NAME}}`, `{{KEYCLOAK_PORT}}`, …) | `{{ }}` | `MVCAppManager::replacePlaceholders()` (`src/Nimbus/App/MVCAppManager.php:309`, applied at `:224` over the whole instance tree) | once, at `nimbus:create` |
| Mustache views (`app/Views/*.mustache`) | `{{ }}` | `MustacheRenderer` | every request |
| Generator templates (Ansible/shell) | `<% %>` | `Nimbus\Generator\FileGenerator` | at create — uses `<% %>` precisely to avoid this collision |

The authoritative placeholder vocabulary is
`Nimbus\Template\Placeholders::PLACEHOLDERS`
(`src/Nimbus/Template/Placeholders.php`). A `{{TOKEN}}` in that list is
replaced at create time; anything else ships verbatim and is (at best) a
Mustache variable resolved at request time.

`nimbus:lint-check` (`src/Nimbus/Tasks/LintTask.php` ~280–300) enforces this:
any `{{UPPERCASE_TOKEN}}` in a template file is treated as a placeholder —
**unknown token → error**, known token in a copied asset → warning. It cannot
tell an uppercase *runtime* variable from a placeholder, because nothing
syntactic distinguishes them.

## The collision, concretely

The demo page in `nimbus-app-php` and `sandbox` passes three **runtime**
values under placeholder-style names
(`Controllers/IndexController.php` → `Views/demo/index.mustache` →
`Views/partials/keycloak-section.mustache`):

| Runtime variable | In `Placeholders` list? | What actually happens |
|---|---|---|
| `APP_PORT_KEYCLOAK` | no | Survives create untouched; rendered at request time from `$config['keycloak']['host_port']`. **Works — but `lint-check nimbus-app-php` reports it as an error**, so a healthy template fails lint. This is the known false positive. |
| `KEYCLOAK_ADMIN_PASSWORD` | **yes** | Appears only as section tags (`{{#…}}`/`{{^…}}`). Create-time `str_replace('{{KEYCLOAK_ADMIN_PASSWORD}}')` does not match section syntax, so it survives *by luck of punctuation*. If anyone ever writes it bare in a view, the real admin password gets baked into that view file at create. |
| `KEYCLOAK_REALM` | **yes** | Passed by the controllers; a bare `{{KEYCLOAK_REALM}}` in any view would be substituted at create (frozen, not live). Same trap as above. |

Related, same file (`keycloak-section.mustache:73`): a *Go template* code
sample (`{{range .Config.Env}}…`) sits inside the view. Mustache resolves
those to empty strings, so the rendered `podman inspect --format` example
displays with its format string silently blanked. Third collision class:
foreign `{{ }}` syntax quoted inside a view.

## Already fixed

- `sandbox/Controllers/IndexController.php` hardcoded
  `'APP_PORT_KEYCLOAK' => 8080` — the port *inside* the container. The
  admin-console link was wrong for essentially every app (host ports are
  deterministic 9xxx). Now reads `$config['keycloak']['host_port'] ?? 8080`,
  matching `nimbus-app-php`.
- **The three runtime variables are renamed to lowercase**
  (`keycloak_port`, `keycloak_admin_password`, `keycloak_realm`) in both
  templates' `IndexController` + `partials/keycloak-section.mustache`.
  `lint-check nimbus-app-php` passes clean; the collision table above is
  historical record.
- While renaming, sandbox's partial turned out to *display*
  `{{KEYCLOAK_ADMIN_PASSWORD}}` bare — the trap live: the real admin password
  was substituted into the view at create and rendered on the demo page.
  Both partials now never print the password (they point at the vault
  command) and print the realm via `{{keycloak_realm}}`.

**Convention now in force:** scaffolding placeholders are `{{UPPERCASE}}`,
runtime Mustache variables are `{{lowercase}}`. lint-check's uppercase
heuristic is correct by construction — and it enforces the convention, since
any uppercase runtime variable that is not a real placeholder fails lint (it
even catches the word `{{TOKENS}}` inside a code comment).

## TODO (in order of value)

1. **Escape the Go-template sample** in `keycloak-section.mustache` (wrap in
   `{{=<% %>=}}…<%={{ }}=%>` delimiter swap, or move the command into
   controller data) so the podman example renders intact.
2. Optional hardening: `replacePlaceholders()` could skip `Views/`
   (per CLAUDE.md, only `app.config.php` / `app.nimbus.json` are *meant* to
   carry tokens) — turns the bake-a-secret trap into a non-event permanently.
3. `keycloak_admin_password` is now consumed by no view — the controllers
   still compute and pass it into render data for nothing. Drop it from
   `$data` (and sandbox's `app.nimbus.json` read that feeds it) so a
   credential stops flowing into the render layer at all.
4. Sandbox's copied-asset `{{APP_NAME}}` literals (lint warnings) — replace
   with `$config['installer-name']` reads per the CLAUDE.md rule, as
   `nimbus-app-php` already does.

## Non-goals

- Teaching lint-check an allowlist of "runtime variables that look like
  placeholders" — that entrenches the ambiguity instead of removing it.
- Changing delimiters of either `{{ }}` system — far too invasive.
