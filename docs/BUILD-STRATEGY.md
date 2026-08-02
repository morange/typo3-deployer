# Build strategy — where composer & the frontend build run

This recipe deploys a TYPO3 project by rsyncing a **release** to the server and
switching a symlink. Two things must exist in that release before it goes live:

1. **PHP dependencies** — `vendor/`.
2. **Built frontend assets** — compiled CSS/JS.

Projects differ in *where* each is produced. This is controlled by **one**
setting; the frontend build location needs no recipe configuration at all.

## The one setting: `composer_install_on_server`

```php
// in the project's deploy.php, after requiring the recipe
set('composer_install_on_server', true);   // default is false
```

| Value | `vendor/` is… | rsync of `vendor/` | Server needs PHP CLI |
|-------|---------------|--------------------|----------------------|
| `false` (default) | prepared **before** the deploy (CI or locally) and transferred as-is | **required** — do *not* exclude `vendor/` | no |
| `true` | rebuilt **on the server** by `composer install` | optional — you *may* add `/vendor` to `rsync_exclude_extra` to transfer less | yes |

Runtime-gated: the deploy task list always contains `deploy:composer`, but it is
a clean no-op when the flag is `false`.

## The frontend build is NOT a recipe concern

Wherever the CSS/JS are compiled — in a CI pipeline or locally on a dev machine —
they simply need to be present in the working tree when `dep deploy` runs. The
recipe rsyncs whatever is there. There is **no CI change and no flag** for this.

What *does* matter is **where the compiled assets land**, because it interacts
with `vendor/` handling:

- **Assets built into a vendor package** (e.g. a webpack `distPath` pointing at
  `vendor/<pkg>/Resources/Public/Assets`, not committed):
  → the built `vendor/` must be rsynced, so use `composer_install_on_server=false`
  and never exclude `vendor/`. Running composer on the server here would
  overwrite the assets — don't.
- **Assets committed into the site package** (`packages/<sitepackage>/Resources/
  Public/...`, checked into Git):
  → the assets travel with the code independently of `vendor/`. You may run
  `composer_install_on_server=true` and even exclude `vendor/` from rsync.
  Composer does not touch the committed site-package files, so there is no
  asset-wipe risk.

## Decision guide

```
Are the built CSS/JS committed in packages/.../Resources/Public ?
├─ no  (built into vendor/, not committed)
│      → composer_install_on_server = false
│        rsync vendor/ (do not exclude it)
│        build vendor/ + assets before deploy (CI or locally)
│
└─ yes (committed in the site package)
       → server has a usable PHP CLI?
         ├─ yes → composer_install_on_server = true
         │        optionally add '/vendor' to rsync_exclude_extra
         └─ no  → composer_install_on_server = false
                  rsync a locally-prepared vendor/
```

## Related settings

- `rsync_exclude_extra` — add `'/vendor'` here only when
  `composer_install_on_server = true`.
- The composer tasks (`composer:install`, `composer:ensure`,
  `composer:detect_path`) live in `src/tasks/composer.php` and are used only in
  the `true` path.