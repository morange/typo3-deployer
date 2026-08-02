# Change Log

## 2026-08-02

- b1f8501 [TASK] `CLAUDE.md` mit Architektur-Leitfaden für Claude Code ergänzt: Library-Charakter (kein Build/Test/Lint hier), Drei-Schichten-Aufbau (Kern/Provider-Profile/Projekt), Entry-Point- und Ladereihenfolge, Deploy-Flow mit kritisch/Best-Effort-Split, Hybrid-Verzeichnisstrategie, Zwei-Achsen-PHP-Aufruf, `composer_install_on_server`, `server-bin/`-Vertrag, Editier-Konventionen
- 67c8c60 [TASK] Deployer 8 zulassen (`deployer/deployer` auf `^7.3 || ^8.0`), Paketbeschreibung auf „Deployer 7/8"
- fe71e89 [FEATURE] Build-Strategie-Flag `composer_install_on_server` (Default `false`): `deploy:composer` ist ein sauberer No-op, solange das Flag nicht gesetzt ist — so bleibt die Deploy-Task-Liste über Prebuilt-vendor- und Server-Composer-Projekte hinweg statisch; Doku `docs/BUILD-STRATEGY.md` (Flag, vendor/-rsync-Kopplung, recipe-agnostischer Frontend-Build) + Client-Name aus `config.php`-Kommentar entfernt
- 9ed777f [FEATURE] Drift-Schutz gegen TYPO3-Core-Änderungen: `typo3:preflight` (prüft Min-Major + Existenz der benötigten CLI-Kommandos), kritisch/Best-Effort-Split im Flow (Schema-Update kritisch), toter `upgrade:prepare` entfernt, `COMPATIBILITY.md` mit Versionierungs-Vertrag + Major-Upgrade-Checkliste
- 44fbc00 [TASK] Lokalen `Projektdateien/`-Arbeitsnotiz-Ordner via `.gitignore` ausschließen

## 2026-08-01

- 51a2d56 [FEATURE] Initiales geteiltes TYPO3-Deployer-Recipe (Deployer 7): generalisierter Kern (config, flow, tasks), Alfahosting-Provider-Profil, `server-bin/`-Backup-Skripte, Composer-Paket + MIT-Lizenz + README
- 0abc040 [FEATURE] PHP-Achse `php_flags` (Binary getrennt von Provider-Flags, z. B. Strato-CGI `register_argc_argv`) + Provider-Profile für alle sieben Hoster (hosteurope, strato, ionos, 1blu, allinkl); Firmen-Autor in `composer.json`