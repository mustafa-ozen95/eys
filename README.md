# EYS — Access Management System

**English** · [Türkçe](README.tr.md)

A vault panel that keeps the access credentials of in-house systems encrypted,
lets people use a password without ever printing it on screen, and **can
actually change the password on the remote machine**.

Sibling projects: NYS (network panel) and the Identity Management Center
(identity provider).

What is supported, and **to what extent**: [`SUPPORT.md`](SUPPORT.md) (Turkish).

## Three jobs

- **Stores.** Passwords, notes and rotation credentials are stored with the
  `encrypted` cast and listed in `$hidden`: they are never printed in any list,
  JSON response or error output. The only endpoint that brings a password to the
  screen is `POST access/{credential}/password`; it needs a separate permission, is
  rate limited, and every reveal lands in the audit log.
- **Lets you use it.** A portal session produces a one-time form page with the
  fields filled in, and the page submits itself to the target site. The password
  never touches the user's screen or clipboard.
- **Changes it.** Sixteen drivers (Linux, Windows/OpenSSH, WinRM, ESXi, vCenter
  OS and SSO, HPE iLO Redfish/RIBCL, Plesk, mailcow, Rspamd, ATEN and Lantronix
  KVM) rotate the remote password.

## The order of a password rotation

The order is the security itself; each step makes it possible to roll back the
next one.

1. A temporary **recovery account** with full rights is created on the remote
   machine.
2. It is proven that this account can really connect — if that cannot be
   proven, the account is deleted and the run stops; the real password is not
   touched.
3. The real password is changed using the recovery account.
4. The new password is tried with **a new session**.
5. Only now is the vault updated.
6. The recovery account is deleted using the new password; the deletion is the
   second proof.

If something gets stuck, the recovery account stays on the machine, its password
stays encrypted in the panel, and the "onar" (repair) button closes the run. The
queue job has `tries = 1`: blindly retrying a half-finished rotation would leave
the device with a password nobody knows.

## Permissions

The role is read **live**; it is not copied onto the user. A person's own rows
are only exceptions. Decision order:

1. The person's record-specific row
2. The person's general row
3. The role's record-specific permission
4. The role's general permission
5. Deny (closed by default)

Permissions are granted per group, with a split per field: seeing the group is
enough to see the list, but only someone granted `field.password` can reveal the
password.

## Audit log

Every row carries the digest of the previous one, and the digest is produced
with **HMAC-SHA256**. Updates and deletes are disabled at the model level; the
only way to delete is the `activity:prune` command, which asks for a reason
and writes itself into the chain.

## Installation

```bash
composer setup          # dependencies, .env, key, migrations, build
php artisan admin:add --name="Ad Soyad" --email=ad@ornek.test
```

In the Docker image the scheduler already runs inside the container. Outside a
container, scheduled jobs need a single cron line on the server (as the site
user, **not** as root; replace the installation directory):

```
* * * * * cd /path/to/eys && php artisan schedule:run >> /dev/null 2>&1
```

## Commands

| Command | Purpose |
| --- | --- |
| `access:vault-check` | Read-only check: tests the credentials of records ready for rotation by only attempting a login |
| `identity:sync` | Applies role and status changes from the center to local accounts |
| `activity:prune` | Prunes the audit log; asks for a reason |
| `admin:add` | Creates the first administrator; the password is not written on the command line |
| `access:prepare-visuals` | Builds the sqlite database for the visual tests from scratch |

## Tests

```bash
composer test                                   # full suite
php artisan access:prepare-visuals && npx playwright test
```

## Rules

Coding standards and the security review are in `CLAUDE.md`; working rules are
under `.ai/rules/`. In short: every controller carries authorization, sequential
primary keys never leave the app (uuid), filtering happens in the database, and
every fix is locked in with a test.
