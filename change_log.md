# Change Log

## 2026-08-02

- aac626b [FEATURE] Build-Strategie-Flag `composer_install_on_server` (Default `false`): `deploy:composer` ist ein sauberer No-op, solange das Flag nicht gesetzt ist — so bleibt die Deploy-Task-Liste über Prebuilt-vendor- und Server-Composer-Projekte hinweg statisch; Doku `docs/BUILD-STRATEGY.md` (Flag, vendor/-rsync-Kopplung, recipe-agnostischer Frontend-Build) + Client-Name aus `config.php`-Kommentar entfernt
- d8be7af [FEATURE] Drift-Schutz gegen TYPO3-Core-Änderungen: `typo3:preflight` (prüft Min-Major + Existenz der benötigten CLI-Kommandos), kritisch/Best-Effort-Split im Flow (Schema-Update kritisch), toter `upgrade:prepare` entfernt, `COMPATIBILITY.md` mit Versionierungs-Vertrag + Major-Upgrade-Checkliste
- ab06adc [TASK] Lokalen `Projektdateien/`-Arbeitsnotiz-Ordner via `.gitignore` ausschließen

## 2026-08-01

- 5baa061 [FEATURE] Initiales geteiltes TYPO3-Deployer-Recipe (Deployer 7): generalisierter Kern (config, flow, tasks), Alfahosting-Provider-Profil, `server-bin/`-Backup-Skripte, Composer-Paket + MIT-Lizenz + README
- d4814a7 [FEATURE] PHP-Achse `php_flags` (Binary getrennt von Provider-Flags, z. B. Strato-CGI `register_argc_argv`) + Provider-Profile für alle sieben Hoster (hosteurope, strato, ionos, 1blu, allinkl); Firmen-Autor in `composer.json`