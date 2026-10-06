#!/bin/bash

##########################################################################
# TYPO3 SBOM Backup Script
# Erstellt ein CycloneDX Software Bill of Materials (SBOM) auf dem Server
# und legt es im SBOM-Backup-Verzeichnis ab. Pendant zu server-backup.sh
# (DB-Backup), gleiche Konventionen (Timestamp, Checksumme, Retention).
# Version: 1.0
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

PROJECT_NAME="${PROJECT_NAME:-TYPO3-v14}"
BACKUP_DIR=""
RELEASE_DIR=""

# ============================================================================
# PARSE ARGUMENTS
# ============================================================================

while getopts "o:n:r:" opt; do
    case $opt in
        o) BACKUP_DIR="$OPTARG" ;;
        n) PROJECT_NAME="$OPTARG" ;;
        r) RELEASE_DIR="$OPTARG" ;;
        *)
            echo "Usage: $0 [-o output_dir] [-n project_name] [-r release_dir]"
            exit 1
            ;;
    esac
done

# ============================================================================
# LOAD .ENV (gleiche Suchlogik wie server-backup.sh)
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

LOCAL_BIN_ENV="$SCRIPT_DIR/.env"
if [ -f "$LOCAL_BIN_ENV" ]; then
    echo -e "${CYAN}Lade lokale Konfiguration: $LOCAL_BIN_ENV${NC}"
    source "$LOCAL_BIN_ENV"
fi

# ============================================================================
# DETERMINE DIRECTORIES
# ============================================================================

# Release-Verzeichnis: dort liegt composer.json/composer.lock/vendor, die
# tatsächlich analysiert werden sollen (SERVER_PATH = Deployer "current"-Symlink).
if [ -z "$RELEASE_DIR" ]; then
    RELEASE_DIR="${SERVER_PATH:-}"
fi

if [ -z "$RELEASE_DIR" ] || [ ! -f "$RELEASE_DIR/composer.json" ]; then
    echo -e "${RED}✗ FEHLER: Release-Verzeichnis nicht gefunden oder composer.json fehlt${NC}"
    echo "  RELEASE_DIR: ${RELEASE_DIR:-'nicht gesetzt'}"
    echo "  Erwartet: SERVER_PATH in bin/.env, oder -r <pfad> Argument"
    exit 1
fi
echo -e "${GREEN}✓${NC} Release-Verzeichnis: $RELEASE_DIR"

# SBOM-Zielordner: standardmäßig aus RELEASE_DIR (= SERVER_PATH, der "current"-
# Symlink) abgeleitet - Nachbarordner von current/shared direkt in deploy_path,
# pro Umgebung getrennt. Kein eigener SERVER_SBOM_PATH-Eintrag in bin/.env nötig;
# -o/SERVER_SBOM_PATH bleiben nur als expliziter Override möglich.
if [ -z "$BACKUP_DIR" ]; then
    BACKUP_DIR="${SERVER_SBOM_PATH:-${RELEASE_DIR%/*}/sbom}"
fi
mkdir -p "$BACKUP_DIR"

# ============================================================================
# DETECT TYPO3 VERSION (identische Logik wie server-backup.sh)
# ============================================================================

TYPO3_VERSION="v14"
COMPOSER_LOCK="$RELEASE_DIR/composer.lock"
if [ -f "$COMPOSER_LOCK" ]; then
    DETECTED_VERSION=$(grep -A2 '"name": "typo3/cms-core"' "$COMPOSER_LOCK" 2>/dev/null \
        | grep '"version"' \
        | head -1 \
        | sed -E 's/.*"version": *"v?([0-9]+)\..*/\1/')
    [ -n "$DETECTED_VERSION" ] && TYPO3_VERSION="v${DETECTED_VERSION}"
fi

# ============================================================================
# SBOM ERSTELLEN
# ============================================================================

TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="${TIMESTAMP}_${PROJECT_NAME}_TYPO3-${TYPO3_VERSION}_SBOM.json"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILE}"

echo ""
echo -e "${CYAN}==========================================${NC}"
echo -e "${CYAN}  TYPO3 SBOM-Backup${NC}"
echo -e "${CYAN}==========================================${NC}"
echo -e "${BLUE}Projekt:${NC}    ${PROJECT_NAME}"
echo -e "${BLUE}Version:${NC}    TYPO3 ${TYPO3_VERSION}"
echo -e "${BLUE}Release:${NC}    ${RELEASE_DIR}"
echo -e "${BLUE}Backup:${NC}     ${BACKUP_FILE}"
echo -e "${BLUE}Ziel:${NC}       ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

if ! command -v composer &> /dev/null; then
    echo -e "${RED}✗ FEHLER: composer nicht gefunden${NC}"
    exit 1
fi

echo "⏳ Erstelle SBOM (composer CycloneDX:make-sbom)..."

cd "$RELEASE_DIR"

# --omit=dev: dokumentiert nur den tatsächlich live installierten Paketstand
# (composer install --no-dev beim Deploy entfernt require-dev ohnehin, dies
# ist ein zusätzliches, explizites Sicherheitsnetz).
set +e
composer CycloneDX:make-sbom \
    --omit=dev \
    --output-format=JSON \
    --output-file="$BACKUP_PATH" \
    --no-interaction
SBOM_EXIT_CODE=$?
set -e

if [ $SBOM_EXIT_CODE -ne 0 ] || [ ! -s "$BACKUP_PATH" ]; then
    echo -e "${RED}✗ FEHLER: SBOM-Erstellung fehlgeschlagen (Exit Code: $SBOM_EXIT_CODE)${NC}"
    rm -f "$BACKUP_PATH"
    exit 1
fi

echo -e "${GREEN}✓ SBOM erstellt${NC}"

FILE_SIZE=$(stat -c%s "$BACKUP_PATH" 2>/dev/null || stat -f%z "$BACKUP_PATH" 2>/dev/null)
echo "   Größe: $((FILE_SIZE / 1024)) KB"
echo ""

# Checksumme (gleiche Konvention wie DB-Backup, wird von nas-pull.sh mitgeladen)
echo "🔐 Erstelle Checksumme..."
cd "$BACKUP_DIR"
sha256sum "$BACKUP_FILE" > "${BACKUP_FILE}.sha256"
CHECKSUM=$(cut -d' ' -f1 "${BACKUP_FILE}.sha256")
echo -e "${GREEN}✓ SHA256: $CHECKSUM${NC}"
echo ""

# ============================================================================
# ALTE SBOM-BACKUPS LÖSCHEN
# ============================================================================

BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-365}"

if [ -n "$BACKUP_RETENTION_DAYS" ] && [ "$BACKUP_RETENTION_DAYS" -gt 0 ]; then
    echo "🗑️  Prüfe alte SBOM-Backups (älter als $BACKUP_RETENTION_DAYS Tage)..."

    OLD_BACKUPS=$(find "$BACKUP_DIR" -name "*_SBOM.json" -mtime "+$BACKUP_RETENTION_DAYS" 2>/dev/null || true)

    if [ -n "$OLD_BACKUPS" ]; then
        OLD_COUNT=$(echo "$OLD_BACKUPS" | grep -c "json" || echo "0")
        if [ "$OLD_COUNT" -gt 0 ]; then
            echo "   Lösche $OLD_COUNT alte(s) Backup(s):"
            echo "$OLD_BACKUPS" | while read old_file; do
                if [ -n "$old_file" ] && [ -f "$old_file" ]; then
                    echo "   - $(basename "$old_file")"
                    rm -f "$old_file"
                    rm -f "${old_file}.sha256" 2>/dev/null || true
                fi
            done
            echo -e "${GREEN}✓ Alte SBOM-Backups gelöscht${NC}"
        else
            echo "   Keine alten SBOM-Backups gefunden"
        fi
    else
        echo "   Keine alten SBOM-Backups gefunden"
    fi
    echo ""
fi

# ============================================================================
# ZUSAMMENFASSUNG
# ============================================================================

echo -e "${CYAN}==========================================${NC}"
echo -e "${GREEN}✓ SBOM-Backup erfolgreich abgeschlossen!${NC}"
echo -e "${CYAN}==========================================${NC}"
echo -e "${BLUE}Datei:${NC}       ${BACKUP_FILE}"
echo -e "${BLUE}Checksumme:${NC}  ${CHECKSUM:0:16}..."
echo -e "${BLUE}Speicherort:${NC} ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

date
exit 0
