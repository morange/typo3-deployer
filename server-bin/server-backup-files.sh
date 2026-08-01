#!/bin/bash

##########################################################################
# TYPO3 Files Backup Script
# Erstellt Dateisystem-Backup mit verschiedenen Modi
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
BACKUP_MODE=""
FORCE_MODE=0

# ============================================================================
# PARSE ARGUMENTS
# ============================================================================

while getopts "o:n:m:f-:" opt; do
    case $opt in
        o) BACKUP_DIR="$OPTARG" ;;
        n) PROJECT_NAME="$OPTARG" ;;
        m) BACKUP_MODE="$OPTARG" ;;
        f) FORCE_MODE=1 ;;
        -)
            case "${OPTARG}" in
                mode)
                    BACKUP_MODE="${!OPTIND}"; OPTIND=$(( $OPTIND + 1 ))
                    ;;
                force)
                    FORCE_MODE=1
                    ;;
                *)
                    echo "Unbekannte Option: --${OPTARG}"
                    exit 1
                    ;;
            esac
            ;;
        *)
            echo "Usage: $0 --mode <minimal|standard|komplett|fileadmin> [--force] [-o output_dir] [-n project_name]"
            exit 1
            ;;
    esac
done

# ============================================================================
# DETERMINE BACKUP DIRECTORY
# ============================================================================

if [ -z "$BACKUP_DIR" ]; then
    BACKUP_DIR="$HOME/backups"
fi

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
fi

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

    echo "$error_msg" | mail -s "TYPO3 Files-Backup FEHLER: $PROJECT_NAME" "$BACKUP_EMAIL_TO" 2>/dev/null || true
}

trap 'send_error_email "Files-Backup fehlgeschlagen in Zeile $LINENO"' ERR

# ============================================================================
# ERMITTLE TYPO3-VERSION
# ============================================================================

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
# ERMITTLE PROJECT ROOT & SHARED
# ============================================================================

# Finde project root (enthält current/ und shared/)
PROJECT_ROOT=""
SHARED_DIR=""

# PRIO 1: Nutze den Pfad der geladenen .env Datei
# ENV_FILE wurde weiter oben gesetzt (z.B. .../live.example.tld/shared/.env)
if [ -n "$ENV_FILE" ] && [ -f "$ENV_FILE" ]; then
    # ENV_FILE = /var/www/vhosts/.../live.example.tld/shared/.env
    # SHARED_DIR = /var/www/vhosts/.../live.example.tld/shared
    SHARED_DIR="$(dirname "$ENV_FILE")"
    # PROJECT_ROOT = /var/www/vhosts/.../live.example.tld
    PROJECT_ROOT="$(dirname "$SHARED_DIR")"

    echo -e "${CYAN}Project Root (aus ENV_FILE): $PROJECT_ROOT${NC}"
    echo -e "${CYAN}Shared Dir:                  $SHARED_DIR${NC}"
fi

# PRIO 2: Von bin/ aus suchen (Fallback)
if [ -z "$PROJECT_ROOT" ]; then
    current_dir="$SCRIPT_DIR"
    for i in {1..5}; do
        if [ -d "$current_dir/current" ] && [ -d "$current_dir/shared" ]; then
            PROJECT_ROOT="$current_dir"
            SHARED_DIR="$current_dir/shared"
            break
        fi
        current_dir="$(dirname "$current_dir")"
    done

    if [ -n "$PROJECT_ROOT" ]; then
        echo -e "${CYAN}Project Root (gefunden): $PROJECT_ROOT${NC}"
        echo -e "${CYAN}Shared Dir:              $SHARED_DIR${NC}"
    fi
fi

# Validierung
if [ -z "$PROJECT_ROOT" ] || [ ! -d "$PROJECT_ROOT/current" ] || [ ! -d "$PROJECT_ROOT/shared" ]; then
    echo -e "${RED}FEHLER: Project Root nicht gefunden${NC}"
    echo "Erwartet: Verzeichnis mit current/ und shared/"
    echo ""
    echo "Debug:"
    echo "  ENV_FILE: ${ENV_FILE:-'nicht gesetzt'}"
    echo "  SHARED_DIR: ${SHARED_DIR:-'nicht gesetzt'}"
    echo "  PROJECT_ROOT: ${PROJECT_ROOT:-'nicht gesetzt'}"
    echo ""
    if [ -n "$PROJECT_ROOT" ]; then
        echo "Prüfe Verzeichnisse:"
        echo "  current/: $([ -d "$PROJECT_ROOT/current" ] && echo 'existiert' || echo 'FEHLT')"
        echo "  shared/:  $([ -d "$PROJECT_ROOT/shared" ] && echo 'existiert' || echo 'FEHLT')"
    fi
    echo ""
    send_error_email "Project Root nicht gefunden"
    exit 1
fi

echo ""

# ============================================================================
# INTERAKTIVE MODUSWAHL
# ============================================================================

if [ -z "$BACKUP_MODE" ] && [ $FORCE_MODE -eq 0 ]; then
    echo -e "${CYAN}==========================================${NC}"
    echo -e "${CYAN}  Backup-Modus wählen${NC}"
    echo -e "${CYAN}==========================================${NC}"
    echo ""
    echo "  ${GREEN}[1]${NC} Minimal      (Code + Config, ohne Cache/Logs)"
    echo "                  ${BLUE}~50-200 MB${NC}"
    echo ""
    echo "  ${GREEN}[2]${NC} Standard     (Minimal + Fileadmin) ${YELLOW}⭐ Empfohlen${NC}"
    echo "                  ${BLUE}~200 MB - 2 GB${NC}"
    echo ""
    echo "  ${GREEN}[3]${NC} Komplett     (Alles inkl. Cache/Logs)"
    echo "                  ${BLUE}~500 MB - 5 GB${NC}"
    echo ""
    echo "  ${GREEN}[4]${NC} Nur Fileadmin (Kundendateien)"
    echo "                  ${BLUE}~100 MB - 2 GB${NC}"
    echo ""
    echo -n "Auswahl [1-4]: "
    read mode_choice

    case $mode_choice in
        1) BACKUP_MODE="minimal" ;;
        2) BACKUP_MODE="standard" ;;
        3) BACKUP_MODE="komplett" ;;
        4) BACKUP_MODE="fileadmin" ;;
        *)
            echo -e "${RED}Ungültige Auswahl${NC}"
            exit 1
            ;;
    esac
    echo ""
fi

# Validiere Modus
case $BACKUP_MODE in
    minimal|standard|komplett|fileadmin) ;;
    *)
        echo -e "${RED}FEHLER: Ungültiger Modus: $BACKUP_MODE${NC}"
        echo "Gültig: minimal, standard, komplett, fileadmin"
        send_error_email "Ungültiger Backup-Modus: $BACKUP_MODE"
        exit 1
        ;;
esac

# ============================================================================
# BACKUP KONFIGURATION NACH MODUS
# ============================================================================

TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="${TIMESTAMP}_${PROJECT_NAME}_TYPO3-${TYPO3_VERSION}_Files_${BACKUP_MODE}.tar.bz2"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILE}"

# tar Excludes basierend auf Modus
EXCLUDES=()

case $BACKUP_MODE in
    minimal)
        # Code + Config (ohne Cache, Logs, Sessions, Temp, Fileadmin)
        EXCLUDES=(--exclude=var/cache --exclude=var/log --exclude=var/session --exclude=var/lock --exclude=var/charset --exclude=public/typo3temp --exclude=public/fileadmin)
        BACKUP_PATHS=("current" "shared/config" "shared/.env")
        ;;
    standard)
        # Minimal + Fileadmin
        EXCLUDES=(--exclude=var/cache --exclude=var/log --exclude=var/session --exclude=var/lock --exclude=var/charset --exclude=public/typo3temp)
        BACKUP_PATHS=("current" "shared/config" "shared/.env" "shared/public/fileadmin")
        ;;
    komplett)
        # Alles
        EXCLUDES=()
        BACKUP_PATHS=("current" "shared")
        ;;
    fileadmin)
        # Nur Fileadmin
        EXCLUDES=""
        BACKUP_PATHS=("shared/public/fileadmin")
        ;;
esac

# ============================================================================
# BACKUP ERSTELLEN
# ============================================================================

echo ""
echo -e "${CYAN}==========================================${NC}"
echo -e "${CYAN}  TYPO3 Files-Backup${NC}"
echo -e "${CYAN}==========================================${NC}"
echo -e "${BLUE}Projekt:${NC}    ${PROJECT_NAME}"
echo -e "${BLUE}Version:${NC}    TYPO3 ${TYPO3_VERSION}"
echo -e "${BLUE}Modus:${NC}      ${BACKUP_MODE}"
echo -e "${BLUE}Backup:${NC}     ${BACKUP_FILE}"
echo -e "${BLUE}Ziel:${NC}       ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

echo "📁 Erstelle Files-Backup..."
echo ""

# Wechsle in Project Root
cd "$PROJECT_ROOT"

# Prüfe ob Pfade existieren
echo "Prüfe Backup-Pfade:"
for path in "${BACKUP_PATHS[@]}"; do
    if [ -e "$path" ]; then
        echo -e "   ${GREEN}✓${NC} $path"
    else
        echo -e "   ${YELLOW}⚠${NC}  $path (nicht gefunden, wird übersprungen)"
    fi
done
echo ""

# Erstelle Backup mit bzip2 (bessere Kompression als gzip)
echo "💾 Packe und komprimiere..."

# Nutze -h um Symlinks zu dereferenzieren
tar -cjf "$BACKUP_PATH" "${EXCLUDES[@]}" -h "${BACKUP_PATHS[@]}" 2>&1 | grep -v "Entferne führendes" || true

if [ ${PIPESTATUS[0]} -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: Backup fehlgeschlagen${NC}"
    send_error_email "tar fehlgeschlagen (Modus: $BACKUP_MODE)"
    exit 1
fi

echo -e "${GREEN}✓ Backup erstellt${NC}"

# Integritätsprüfung: verifiziert Bzip2- UND Tar-Struktur, damit ein
# stillschweigend korruptes Archiv nicht erst beim Restore aktenkundig wird
echo "🔍 Prüfe Archiv-Integrität..."
if ! tar -tjf "$BACKUP_PATH" >/dev/null 2>&1; then
    echo -e "${RED}✗ FEHLER: Backup-Archiv ist beschädigt (tar -tjf fehlgeschlagen)${NC}"
    rm -f "$BACKUP_PATH"
    send_error_email "Backup-Archiv beschädigt: $BACKUP_FILE"
    exit 1
fi
echo -e "${GREEN}✓ Archiv-Integrität OK${NC}"

# Dateigröße
FILE_SIZE=$(stat -c%s "$BACKUP_PATH" 2>/dev/null || stat -f%z "$BACKUP_PATH" 2>/dev/null)
FILE_SIZE_MB=$((FILE_SIZE / 1024 / 1024))
echo "   Größe: ${FILE_SIZE_MB} MB"
echo ""

# Checksumme
echo "🔐 Erstelle Checksumme..."
cd "$BACKUP_DIR"
sha256sum "$BACKUP_FILE" > "${BACKUP_FILE}.sha256"
CHECKSUM=$(cut -d' ' -f1 "${BACKUP_FILE}.sha256")
echo -e "${GREEN}✓ SHA256: $CHECKSUM${NC}"
echo ""

# ============================================================================
# ALTE BACKUPS LÖSCHEN
# ============================================================================

if [ -n "$BACKUP_RETENTION_DAYS" ] && [ "$BACKUP_RETENTION_DAYS" -gt 0 ]; then
    echo "🗑️  Prüfe alte Backups (älter als $BACKUP_RETENTION_DAYS Tage)..."

    # Finde alte Backups (nur gleicher Modus; Pattern muss zum tatsächlichen
    # Dateinamen passen, siehe BACKUP_FILE oben: TIMESTAMP_PROJECT_TYPO3-vXX_Files_MODE.tar.bz2)
    OLD_BACKUPS=$(find "$BACKUP_DIR" -name "*_Files_${BACKUP_MODE}.tar.bz2" -mtime "+$BACKUP_RETENTION_DAYS" 2>/dev/null || true)

    if [ -n "$OLD_BACKUPS" ]; then
        OLD_COUNT=$(echo "$OLD_BACKUPS" | grep -c "tar.bz2" || echo "0")

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
echo -e "${BLUE}Modus:${NC}       ${BACKUP_MODE}"
echo -e "${BLUE}Größe:${NC}       ${FILE_SIZE_MB} MB"
echo -e "${BLUE}Checksumme:${NC}  ${CHECKSUM:0:16}..."
echo -e "${BLUE}Speicherort:${NC} ${BACKUP_DIR}"
echo -e "${CYAN}==========================================${NC}"
echo ""

date
exit 0