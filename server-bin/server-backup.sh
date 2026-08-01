#!/bin/bash

##########################################################################
# TYPO3 DB Backup Script
# Erstellt Datenbank-Backup auf dem Server
# Version: 2.0
##########################################################################

set -e  # Exit on error

# Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
NC='\033[0m'

# ============================================================================
# CONFIGURATION
# ============================================================================

PROJECT_NAME="${PROJECT_NAME:-TYPO3-v13}"
BACKUP_DIR=""
FORCE_MODE=0

# ============================================================================
# PARSE ARGUMENTS
# ============================================================================

while getopts "o:n:f" opt; do
    case $opt in
        o) BACKUP_DIR="$OPTARG" ;;
        n) PROJECT_NAME="$OPTARG" ;;
        f) FORCE_MODE=1 ;;
        *)
            echo "Usage: $0 [-o output_dir] [-n project_name] [-f]"
            exit 1
            ;;
    esac
done

# ============================================================================
# DETERMINE BACKUP DIRECTORY
# ============================================================================

if [ -z "$BACKUP_DIR" ]; then
    # Automatisch ermitteln: ~/backups
    BACKUP_DIR="$HOME/backups"
fi

# Erstelle Backup-Verzeichnis falls nicht vorhanden
mkdir -p "$BACKUP_DIR"

# ============================================================================
# LOAD .ENV
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# SCHRITT 1: Versuche lokale bin/.env zu laden (falls deployed)
LOCAL_BIN_ENV="$SCRIPT_DIR/.env"
if [ -f "$LOCAL_BIN_ENV" ]; then
    echo -e "${CYAN}Lade lokale Konfiguration: $LOCAL_BIN_ENV${NC}"
    source "$LOCAL_BIN_ENV"
fi

# SCHRITT 2: Ermittle TYPO3 .env Pfad
ENV_FILE=""

# Prio 1: Wenn SHARED_DIR gesetzt ist (aus lokaler .env), nutze es
if [ -n "$SHARED_DIR" ] && [ -f "$SHARED_DIR/.env" ]; then
    ENV_FILE="$SHARED_DIR/.env"
else
    # Prio 2: Suche nach live.*/shared/.env Muster (Alfahosting-Struktur)
    # Von /var/www/vhosts/USER/bin aus eine Ebene hoch, dann live.*/shared/.env
    PARENT_DIR="$(dirname "$SCRIPT_DIR")"

    # Finde alle live.* Verzeichnisse
    for live_dir in "$PARENT_DIR"/live.*; do
        if [ -d "$live_dir/shared" ] && [ -f "$live_dir/shared/.env" ]; then
            ENV_FILE="$live_dir/shared/.env"
            break
        fi
    done

    # Prio 3: Fallback - Standard Deployer-Struktur
    if [ -z "$ENV_FILE" ]; then
        ENV_PATHS=(
            "$SCRIPT_DIR/../shared/.env"
            "$SCRIPT_DIR/../../shared/.env"
            "$SCRIPT_DIR/../../../shared/.env"
            "$HOME/shared/.env"
        )

        for path in "${ENV_PATHS[@]}"; do
            if [ -f "$path" ]; then
                ENV_FILE="$path"
                break
            fi
        done
    fi
fi

# SCHRITT 3: Lade TYPO3 .env
if [ -f "$ENV_FILE" ]; then
    echo -e "${CYAN}Lade TYPO3 Konfiguration: $ENV_FILE${NC}"
    source "$ENV_FILE"
else
    echo -e "${YELLOW}WARNUNG: TYPO3 .env nicht gefunden${NC}"
    echo "SHARED_DIR: ${SHARED_DIR:-'nicht gesetzt'}"
    echo ""
    echo "Gesucht in:"
    [ -n "$SHARED_DIR" ] && echo "  - $SHARED_DIR/.env"
    echo "  - $PARENT_DIR/live.*/shared/.env"
    echo "  - $SCRIPT_DIR/../shared/.env"
    echo "  - $SCRIPT_DIR/../../shared/.env"
    echo "  - $SCRIPT_DIR/../../../shared/.env"
    echo "  - $HOME/shared/.env"
fi

# Fallback-Werte
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-365}"
BACKUP_EMAIL_ENABLED="${BACKUP_EMAIL_ENABLED:-false}"
BACKUP_EMAIL_TO="${BACKUP_EMAIL_TO:-}"

# ============================================================================
# EMAIL-FUNKTION
# ============================================================================

send_error_email() {
    local error_msg="$1"

    if [ "$BACKUP_EMAIL_ENABLED" != "true" ] || [ -z "$BACKUP_EMAIL_TO" ]; then
        return
    fi

    echo "$error_msg" | mail -s "TYPO3 Backup FEHLER: $PROJECT_NAME" "$BACKUP_EMAIL_TO" 2>/dev/null || true
}

# Error Handler
trap 'send_error_email "Backup fehlgeschlagen in Zeile $LINENO"' ERR

# ============================================================================
# DATABASE CREDENTIALS
# ============================================================================

# Aus .env
DB_HOST="${TYPO3_DB_HOST:-localhost}"
DB_NAME="${TYPO3_DB_NAME}"
DB_USER="${TYPO3_DB_USER:-$TYPO3_DB_USERNAME}"
DB_PASS="${TYPO3_DB_PASSWORD}"
DB_PORT="${TYPO3_DB_PORT:-3306}"

# Parse Host und Port (falls Host bereits Port enthält)
# Beispiel: "127.0.0.1:3307" → Host=127.0.0.1, Port=3307
if [[ "$DB_HOST" == *":"* ]]; then
    # Host enthält Port
    IFS=':' read -r DB_HOST_ONLY DB_PORT <<< "$DB_HOST"
    DB_HOST="$DB_HOST_ONLY"
    # Port aus Host überschreibt DB_PORT
fi

# Validierung
if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo -e "${RED}FEHLER: Datenbank-Credentials nicht gefunden in .env${NC}"
    echo "Benötigt: TYPO3_DB_NAME, TYPO3_DB_USER (oder TYPO3_DB_USERNAME), TYPO3_DB_PASSWORD"
    send_error_email "DB-Credentials fehlen in .env"
    exit 1
fi

# ============================================================================
# SICHERE PASSWORT-ÜBERGABE
# ============================================================================
# Nutze MYSQL_PWD Environment Variable statt Command Line
# Dies vermeidet Probleme mit Sonderzeichen und "password on command line" Warnung
export MYSQL_PWD="$DB_PASS"

# Unterdrücke "Using a password" Warnung (stderr → /dev/null)
# Aber nur für die Warnung, nicht für echte Fehler
export MYSQL_HISTFILE=/dev/null

# Ermittle TYPO3-Version aus composer.lock (enthält immer die tatsächlich
# installierte typo3/cms-core Version, auch wenn sie nur transitiv referenziert
# wird). Kein grep -P nötig -> portabel (auch auf macOS/BSD).
TYPO3_VERSION="v14"
COMPOSER_LOCK="$SCRIPT_DIR/../composer.lock"
if [ -f "$COMPOSER_LOCK" ]; then
    DETECTED_VERSION=$(grep -A2 '"name": "typo3/cms-core"' "$COMPOSER_LOCK" 2>/dev/null \
        | grep '"version"' \
        | head -1 \
        | sed -E 's/.*"version": *"v?([0-9]+)\..*/\1/')
    [ -n "$DETECTED_VERSION" ] && TYPO3_VERSION="v${DETECTED_VERSION}"
fi

# ============================================================================
# BACKUP ERSTELLEN
# ============================================================================

TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="${TIMESTAMP}_${PROJECT_NAME}_TYPO3-${TYPO3_VERSION}_DB_${DB_NAME}.tar.gz"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILE}"
TEMP_DIR=$(mktemp -d)
# Sicherstellen, dass TEMP_DIR bei JEDEM Skriptende entfernt wird (Erfolg,
# Fehler, Ctrl+C) – nicht nur auf den expliziten rm -rf Aufrufen in den
# einzelnen Fehlerpfaden verlassen, die künftige Fehlerpfade leicht vergessen.
trap 'rm -rf "$TEMP_DIR"' EXIT
SQL_FILE="${TEMP_DIR}/backup.sql"

echo ""
echo -e "${CYAN}==========================================${NC}"
echo -e "${CYAN}  TYPO3 Datenbank-Backup${NC}"
echo -e "${CYAN}==========================================${NC}"
echo -e "${BLUE}Projekt:${NC}    ${PROJECT_NAME}"
echo -e "${BLUE}Version:${NC}    TYPO3 ${TYPO3_VERSION}"
echo -e "${BLUE}Datenbank:${NC}  ${DB_NAME}"
echo -e "${BLUE}Host:${NC}       ${DB_HOST}:${DB_PORT}"
echo -e "${BLUE}Backup:${NC}     ${BACKUP_FILE}"
echo -e "${BLUE}Ziel:${NC}       ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

# Test DB-Verbindung
echo "🔍 Teste Datenbankverbindung..."

# Test mit mysql (nutzt MYSQL_PWD env variable)
if ! mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "USE $DB_NAME" 2>/dev/null; then
    echo -e "${RED}✗ FEHLER: Kann nicht mit Datenbank verbinden${NC}"
    echo ""
    echo "Debug-Info:"
    echo "  Host: $DB_HOST"
    echo "  Port: $DB_PORT"
    echo "  User: $DB_USER"
    echo "  DB:   $DB_NAME"
    echo "  Pass: *** (${#DB_PASS} Zeichen)"
    echo ""
    send_error_email "DB-Verbindung fehlgeschlagen (Host: $DB_HOST, DB: $DB_NAME)"
    exit 1
fi
echo -e "${GREEN}✓ Verbindung OK${NC}"

# Prüfe ob mysqldump verfügbar ist
if ! command -v mysqldump &> /dev/null; then
    echo -e "${RED}✗ FEHLER: mysqldump nicht gefunden${NC}"
    send_error_email "mysqldump nicht installiert"
    exit 1
fi

echo ""

# Erstelle Backup
echo "💾 Erstelle Datenbank-Dump..."

# Cache-Tabellen finden (nur Struktur sichern)
CACHE_TABLES=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "SHOW TABLES LIKE '%cache%'" -s --skip-column-names 2>/dev/null || echo "")

IGNORE_TABLES=()
if [ -n "$CACHE_TABLES" ]; then
    echo "🗑️  Cache-Tabellen (nur Struktur):"
    for table in $CACHE_TABLES; do
        echo "   - $table"
        IGNORE_TABLES+=("--ignore-table=${DB_NAME}.${table}")
    done
    echo ""
fi

echo "⏳ Starte mysqldump..."
echo "📊 Datenbank-Größe ermitteln..."

# Versuche Größe zu ermitteln
DB_SIZE=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema='$DB_NAME'" -s --skip-column-names 2>/dev/null || echo "0")
echo "   Geschätzte Größe: ${DB_SIZE} MB"
echo ""

# VEREINFACHTER Dump - nur die essentiellen Optionen
echo "⏳ Führe mysqldump aus..."

DUMP_ERR_FILE="${TEMP_DIR}/mysqldump.err"

set +e
mysqldump \
    -h"$DB_HOST" \
    -P"$DB_PORT" \
    -u"$DB_USER" \
    --opt \
    --skip-lock-tables \
    --no-tablespaces \
    "${IGNORE_TABLES[@]}" \
    "$DB_NAME" > "$SQL_FILE" 2> "$DUMP_ERR_FILE"
DUMP_EXIT_CODE=$?
set -e

# Zeige Datei-Info
if [ -f "$SQL_FILE" ]; then
    SQL_LINES=$(wc -l < "$SQL_FILE" 2>/dev/null || echo "0")
    SQL_SIZE_BYTES=$(stat -c%s "$SQL_FILE" 2>/dev/null || stat -f%z "$SQL_FILE" 2>/dev/null || echo "0")
    echo "   Dump-Datei: $SQL_LINES Zeilen, $((SQL_SIZE_BYTES / 1024 / 1024)) MB"
fi

# Prüfe ob Datei erstellt wurde und nicht leer ist
if [ ! -f "$SQL_FILE" ]; then
    echo -e "${RED}✗ FEHLER: SQL-Datei wurde nicht erstellt${NC}"
    rm -rf "$TEMP_DIR"
    send_error_email "mysqldump: Datei nicht erstellt"
    exit 1
fi

if [ ! -s "$SQL_FILE" ]; then
    echo -e "${RED}✗ FEHLER: SQL-Datei ist leer${NC}"
    echo "Fehlerausgabe:"
    cat "$SQL_FILE"
    rm -rf "$TEMP_DIR"
    send_error_email "mysqldump: Datei leer"
    exit 1
fi

if [ $DUMP_EXIT_CODE -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: mysqldump fehlgeschlagen (Exit Code: $DUMP_EXIT_CODE)${NC}"
    echo ""
    echo "mysqldump stderr:"
    [ -s "$DUMP_ERR_FILE" ] && cat "$DUMP_ERR_FILE" || echo "(keine Ausgabe)"
    echo ""
    rm -rf "$TEMP_DIR"
    send_error_email "mysqldump fehlgeschlagen (Exit Code: $DUMP_EXIT_CODE)"
    exit 1
fi

# Warnungen ausgeben (z.B. "Using a password ..."), aber nicht abbrechen
if [ -s "$DUMP_ERR_FILE" ]; then
    echo -e "${YELLOW}⚠ mysqldump-Warnungen:${NC}"
    cat "$DUMP_ERR_FILE"
    echo ""
fi

echo "⏳ Dump abgeschlossen, füge Cache-Strukturen hinzu..."

# Cache-Tabellen: Nur Struktur
if [ -n "$CACHE_TABLES" ]; then
    for table in $CACHE_TABLES; do
        mysqldump \
            -h"$DB_HOST" \
            -P"$DB_PORT" \
            -u"$DB_USER" \
            --no-data \
            --no-tablespaces \
            "$DB_NAME" "$table" >> "$SQL_FILE" 2>> "$DUMP_ERR_FILE"
    done
fi

echo -e "${GREEN}✓ Dump erstellt${NC}"

# Dateigröße vor Kompression
SQL_SIZE=$(stat -c%s "$SQL_FILE" 2>/dev/null || stat -f%z "$SQL_FILE" 2>/dev/null)
SQL_SIZE_MB=$((SQL_SIZE / 1024 / 1024))
echo "   SQL-Größe: ${SQL_SIZE_MB} MB"
echo ""

# Komprimieren
echo "📦 Komprimiere Backup..."
cd "$TEMP_DIR"
tar -czf "$BACKUP_PATH" backup.sql

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: Komprimierung fehlgeschlagen${NC}"
    rm -rf "$TEMP_DIR"
    send_error_email "Komprimierung fehlgeschlagen"
    exit 1
fi

echo -e "${GREEN}✓ Backup komprimiert${NC}"

# Integritätsprüfung: verifiziert Gzip- UND Tar-Struktur, damit ein
# stillschweigend korruptes Archiv nicht erst beim Restore aktenkundig wird
echo "🔍 Prüfe Archiv-Integrität..."
if ! tar -tzf "$BACKUP_PATH" >/dev/null 2>&1; then
    echo -e "${RED}✗ FEHLER: Backup-Archiv ist beschädigt (tar -tzf fehlgeschlagen)${NC}"
    rm -f "$BACKUP_PATH"
    send_error_email "Backup-Archiv beschädigt: $BACKUP_FILE"
    exit 1
fi
echo -e "${GREEN}✓ Archiv-Integrität OK${NC}"

# Dateigröße nach Kompression
FILE_SIZE=$(stat -c%s "$BACKUP_PATH" 2>/dev/null || stat -f%z "$BACKUP_PATH" 2>/dev/null)
FILE_SIZE_MB=$((FILE_SIZE / 1024 / 1024))
COMPRESSION_RATIO=$((100 - (FILE_SIZE * 100 / SQL_SIZE)))
echo "   Komprimiert: ${FILE_SIZE_MB} MB (${COMPRESSION_RATIO}% Einsparung)"
echo ""

# Checksumme
echo "🔐 Erstelle Checksumme..."
cd "$BACKUP_DIR"
sha256sum "$BACKUP_FILE" > "${BACKUP_FILE}.sha256"
CHECKSUM=$(cut -d' ' -f1 "${BACKUP_FILE}.sha256")
echo -e "${GREEN}✓ SHA256: $CHECKSUM${NC}"
echo ""

# Cleanup
rm -rf "$TEMP_DIR"

# ============================================================================
# ALTE BACKUPS LÖSCHEN
# ============================================================================

if [ -n "$BACKUP_RETENTION_DAYS" ] && [ "$BACKUP_RETENTION_DAYS" -gt 0 ]; then
    echo "🗑️  Prüfe alte Backups (älter als $BACKUP_RETENTION_DAYS Tage)..."

    # Finde alte Backups (Pattern muss zum tatsächlichen Dateinamen passen,
    # siehe BACKUP_FILE oben: TIMESTAMP_PROJECT_TYPO3-vXX_DB_dbname.tar.gz)
    OLD_BACKUPS=$(find "$BACKUP_DIR" -name "*_DB_*.tar.gz" -mtime "+$BACKUP_RETENTION_DAYS" 2>/dev/null || true)

    if [ -n "$OLD_BACKUPS" ]; then
        # Zähle Backups (sicher)
        OLD_COUNT=$(echo "$OLD_BACKUPS" | grep -c "tar.gz" || echo "0")

        if [ "$OLD_COUNT" -gt 0 ]; then
            echo "   Lösche $OLD_COUNT alte(s) Backup(s):"
            echo "$OLD_BACKUPS" | while read old_file; do
                if [ -n "$old_file" ] && [ -f "$old_file" ]; then
                    echo "   - $(basename "$old_file")"
                    rm -f "$old_file"
                    rm -f "${old_file}.sha256" 2>/dev/null || true
                fi
            done
            echo -e "${GREEN}✓ Alte Backups gelöscht${NC}"
        else
            echo "   Keine alten Backups gefunden"
        fi
    else
        echo "   Keine alten Backups gefunden"
    fi
    echo ""
fi

# ============================================================================
# ZUSAMMENFASSUNG
# ============================================================================

echo -e "${CYAN}==========================================${NC}"
echo -e "${GREEN}✓ Backup erfolgreich abgeschlossen!${NC}"
echo -e "${CYAN}==========================================${NC}"
echo -e "${BLUE}Datei:${NC}       ${BACKUP_FILE}"
echo -e "${BLUE}Größe:${NC}       ${FILE_SIZE_MB} MB"
echo -e "${BLUE}Checksumme:${NC}  ${CHECKSUM:0:16}..."
echo -e "${BLUE}Speicherort:${NC} ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

date
exit 0