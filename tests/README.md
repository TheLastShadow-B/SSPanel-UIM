# Test Suite

SSPanel-UIM uses [Pest 3](https://pestphp.com/) (on PHP 8.2+) for unit, feature,
and integration tests. DB-backed tests run against a **live MariaDB** database
named `sspanel_test` — there is no SQLite fallback.

## 1. Create the test database

Create a dedicated MariaDB/MySQL database and a user with full access to it. Use
a name that will never collide with your production/dev data — the suite
truncates and rebuilds tables.

```sql
CREATE DATABASE sspanel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sspanel_test'@'127.0.0.1' IDENTIFIED BY 'your-password';
GRANT ALL PRIVILEGES ON sspanel_test.* TO 'sspanel_test'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 2. Configure test credentials

Copy the committed template to the (gitignored) real test config and fill in your
local credentials:

```bash
cp config/.config.test.example.php config/.config.test.php
$EDITOR config/.config.test.php   # set db_host / db_database / db_username / db_password
```

- `config/.config.test.example.php` is the committed template — **placeholders
  only, no secrets**.
- `config/.config.test.php` is **never committed** — keep your real credentials
  here only.
- `tests/bootstrap.php` loads `config/.config.test.php` if present; otherwise it
  falls back to the main config (or `config/.config.example.php`) and forces the
  database to `sspanel_test` and Redis DB `15`.

## 3. How the test schema is built

DB-backed tests do **not** run the production migrations. The schema is built
**programmatically** by `tests/TestDatabase.php`:

- `TestDatabase::init()` initialises the DB connection and creates every table
  the suite needs (`user`, `node`, `subscription`, `order`, `invoice`,
  `stripe_event`, etc.) via Eloquent's schema builder.
- `TestDatabase::dropTables()` tears them back down.

Production schema, by contrast, is managed by the migrations in
`db/migrations/*` and applied with:

```bash
php xcat Migration
```

> **Important:** the test schema and the production migrations are maintained
> **separately**. When you add or change a column/table you MUST update **both**:
>
> 1. a new migration file under `db/migrations/` (production path), and
> 2. the matching `Blueprint` in `tests/TestDatabase.php` (test path).
>
> If you only touch one, tests and production will drift apart.

## 4. Running the suite

```bash
# Full suite
./vendor/bin/pest

# Run a subset by test name / description
./vendor/bin/pest --filter="Stripe"

# Run only a directory (Unit, Feature, Integration)
./vendor/bin/pest tests/Unit
```

## Layout

- `tests/Unit/` — pure unit tests (use `Tests\TestCase`).
- `tests/Feature/` — Slim HTTP-level tests (use `Tests\SlimTestCase`).
- `tests/Integration/` — cross-component / DB-backed tests.
- `tests/Factories/` — model factories for building test fixtures.
- `tests/helpers.php` — global helper functions (`createTestUser`,
  `createTestNode`, `cleanTestDatabase`, …). Auto-loaded by Pest as
  `tests/Helpers.php`; each function is guarded with `function_exists()` so the
  file is safe to load more than once (e.g. on case-insensitive filesystems).
- `tests/TestDatabase.php` — programmatic schema for DB-backed tests (see §3).
- `tests/Pest.php` / `tests/bootstrap.php` — Pest config and bootstrap.
