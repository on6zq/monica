# ON6ZQ fork — changes vs upstream `4.x`

This branch tracks local customisations applied on top of Monica's `4.x` release branch.

---

## Docker image (`scripts/docker/Dockerfile`)

| What | Why |
|------|-----|
| PHP `8.1` → `8.3` | `composer.lock` requires PHP ≥ 8.2; 8.3 is the current stable |
| Node.js `setup_18.x` → `setup_20.x` | `package.json` requires Node ≥ 20 |
| Removed the runtime-library auto-remove block | The `apt-mark`/`ldd`/`dpkg-query` cleanup stripped libraries that compiled PHP extensions depend on at runtime (libpng, libicu, libmemcached, libzip), causing fatal errors on startup. Acceptable trade-off for a personal dev image. |

## Country list (`app/Helpers/CountriesHelper.php`)

* **Belgium missing** and **Afghanistan misspelled** — both caused by known data issues in the `rinvex/countries` v8.1.2 package. Fixed via explicit overrides in `getCommonNameLocale()`.
* **`en-GB` locale breaks the whole country dropdown** — `getLocaleAlpha()` expects a two-letter ISO-639-1 code (`en`) but was being passed the full locale string (`en-GB`), returning an empty ISO-639-3 code and silently nulling every country name. Fixed by calling `LocaleHelper::getLang()` to strip the country suffix before the lookup, plus a `getName()` fallback for any other countries with missing translations.

## Relationship audit (`Settings → Relations check`)

New page at **Settings → Relations check** (`/settings/relationscheck`) with three sections:

### 1. Circular parent-child relationships
Detects contacts that are each marked as parent of the other — a data-entry error. Listed for manual review; these contacts are excluded from inference below.

### 2. Missing reciprocals
Finds relationships where the reverse direction has not been recorded (e.g. A is *child of* B but B has no *parent of* A). Provides a **Fix all** button and individual contact hyperlinks.

**Artisan:** `php artisan monica:audit-relationships [--fix]`

### 3. Inferred relationship suggestions
Applies three graph-inference rules:
- **Siblings** — two contacts sharing a common parent are likely siblings.
- **Grandparents** — if A is parent of B and B is parent of C, then A is grandparent of C.
- **Uncles/nephews** — if A is sibling of B and B is parent of C, then A is uncle of C.

Each suggestion row shows a **Create** button to add that single relationship, or use **Add all** to create every suggestion at once. `CreateRelationship` is used for every write, so Monica's automatic reciprocal creation applies.

**Artisan:** `php artisan monica:suggest-relationships [--fix]`

### Key implementation note
Monica stores relationships as `(contact_is, type, of_contact)` where `type` describes what `of_contact` *is* to `contact_is`. Rows with `type = child` therefore have `contact_is` = **parent** and `of_contact` = **child** — the opposite of what the column names imply. All inference queries use `type = child` consistently.
