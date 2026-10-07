# Change Log

## 2026-10-07

- 242fd92 [BUGFIX] `.github/workflows/packagist-update.yml`: `mnavarrocarter/packagist-update`-Action durch direkten `curl`-Aufruf auf die Packagist-`update-package`-API ersetzt — die Action schickt als `repository.url` immer die Packagist-Paketseite (`https://packagist.org/packages/...`) statt der tatsächlichen GitHub-Repo-URL, worauf Packagist jeden Request mit `403` ablehnt (offenes, ungefixtes Upstream-Issue [#2](https://github.com/mnavarrocarter/packagist-update/issues/2))
- 402db23 [BUGFIX] Vorstufe dazu: Action-Versions-Pin von `@v1.0` (existiert nicht) auf `@v1.0.0` korrigiert — davor scheiterte jeder Lauf seit dem allerersten (02.08.2026) schon am Auflösen der Action, bevor überhaupt ein API-Call erfolgte
- [TASK] `PACKAGIST_TOKEN`-Repo-Secret war 2 Monate alt und ungültig; neu mit dem Packagist-Safe-Token gesetzt (zuvor versehentlich unter Secret-Namen `MEINTOKEN` abgelegt, dort wieder gelöscht) — Workflow-Lauf für Commit `9f31a37` danach grün, GitHub→Packagist-Sync damit erstmals tatsächlich verifiziert funktionierend

## 2026-10-06

- 4aa5bc1/5fd7f2e/45fb740 [FEATURE] Opt-in SBOM support: `tasks/sbom.php` (new, `after('deploy:setup_backups', ...)`) provisions a `sbom/` directory INSIDE `deploy_path` (sibling of `current`/`releases`/`shared` — not one level above like `backups/`/`bin/`, corrected after an initial bugfix) and uploads `server-backup-sbom.sh` (new in `server-bin/`) — disabled by default (`sbom_enabled` flag), since it additionally requires the consuming project to require `cyclonedx/cyclonedx-php-composer` itself. SBOM output path derives from `SERVER_PATH`/`NAS_PATH` instead of dedicated env vars, kept in sync across `server-backup-sbom.sh`/`nas-pull.sh`. `config.php`: `/sbom` added to the never-rsync exclude list alongside `/backups`/`/bin`. `server-bin/nas-pull.sh` updated with a third `NAS_PULL_MODE=sbom`. README updated accordingly. Driven by K00101 DVL Sachsen's NIS-2 SBOM-documentation tooling.
- a7bef4d [FEATURE] `deploy:generate_sbom` (new task, `after('deploy:setup_sbom', ...)`): runs `server-backup-sbom.sh -r {{release_path}} -o sbom/` right during the deploy itself, pinned to the about-to-go-live release — a periodic SBOM cron no longer makes sense once this runs on every deploy; only the NAS-pull cron stays needed to fetch the resulting SBOM file (`server-backup-sbom.sh` itself stays independently runnable for an ad hoc snapshot outside a deploy).

## 2026-08-02

- 7f8036d [TASK] GitHub-Actions-Workflow `.github/workflows/packagist-update.yml`: stößt bei jedem Push auf `main` sowie bei jedem Tag-Push automatisch ein Re-Scan auf packagist.org an (`mnavarrocarter/packagist-update`-Action, Secret `PACKAGIST_TOKEN`), damit `packagist.org/packages/dmfh/typo3-deployer` neue Releases ohne Wartezeit auf den periodischen Crawl übernimmt — lief tatsächlich von Anfang an fehlerhaft (siehe Korrekturen 2026-10-07), am 2026-10-06 nur fälschlich als funktionierend angenommen, weil Packagist unabhängig davon trotzdem aktuell war
- b1f8501 [TASK] `CLAUDE.md` mit Architektur-Leitfaden für Claude Code ergänzt: Library-Charakter (kein Build/Test/Lint hier), Drei-Schichten-Aufbau (Kern/Provider-Profile/Projekt), Entry-Point- und Ladereihenfolge, Deploy-Flow mit kritisch/Best-Effort-Split, Hybrid-Verzeichnisstrategie, Zwei-Achsen-PHP-Aufruf, `composer_install_on_server`, `server-bin/`-Vertrag, Editier-Konventionen
- 67c8c60 [TASK] Deployer 8 zulassen (`deployer/deployer` auf `^7.3 || ^8.0`), Paketbeschreibung auf „Deployer 7/8"
- fe71e89 [FEATURE] Build-Strategie-Flag `composer_install_on_server` (Default `false`): `deploy:composer` ist ein sauberer No-op, solange das Flag nicht gesetzt ist — so bleibt die Deploy-Task-Liste über Prebuilt-vendor- und Server-Composer-Projekte hinweg statisch; Doku `docs/BUILD-STRATEGY.md` (Flag, vendor/-rsync-Kopplung, recipe-agnostischer Frontend-Build) + Client-Name aus `config.php`-Kommentar entfernt
- 9ed777f [FEATURE] Drift-Schutz gegen TYPO3-Core-Änderungen: `typo3:preflight` (prüft Min-Major + Existenz der benötigten CLI-Kommandos), kritisch/Best-Effort-Split im Flow (Schema-Update kritisch), toter `upgrade:prepare` entfernt, `COMPATIBILITY.md` mit Versionierungs-Vertrag + Major-Upgrade-Checkliste
- 44fbc00 [TASK] Lokalen `Projektdateien/`-Arbeitsnotiz-Ordner via `.gitignore` ausschließen

## 2026-08-01

- 51a2d56 [FEATURE] Initiales geteiltes TYPO3-Deployer-Recipe (Deployer 7): generalisierter Kern (config, flow, tasks), Alfahosting-Provider-Profil, `server-bin/`-Backup-Skripte, Composer-Paket + MIT-Lizenz + README
- 0abc040 [FEATURE] PHP-Achse `php_flags` (Binary getrennt von Provider-Flags, z. B. Strato-CGI `register_argc_argv`) + Provider-Profile für alle sieben Hoster (hosteurope, strato, ionos, 1blu, allinkl); Firmen-Autor in `composer.json`