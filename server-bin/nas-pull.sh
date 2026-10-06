#!/bin/bash

##########################################################################
# TYPO3 NAS Backup Puller
# Läuft auf deinem Mac, zieht Backup vom Server auf NAS
# Start aus Projekt-Root: ./bin/nas-pull.sh
##########################################################################

# Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo ""
echo -e "${CYAN}=========================================="
echo "  TYPO3 NAS Backup Puller"
echo "==========================================${NC}"
echo ""

# Prüfe ob im Projekt-Verzeichnis
PROJECT_DIR=$(pwd)
PROJECT_NAME=$(basename "$PROJECT_DIR")

echo -e "Projekt: ${PROJECT_NAME}"
echo ""

# Prüfe .env im bin/ Verzeichnis
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

if [ ! -f "$ENV_FILE" ]; then
    echo -e "${RED}✗ FEHLER: $ENV_FILE nicht gefunden${NC}"
    echo "Bitte .env im bin/ Verzeichnis anlegen:"
    echo "  $SCRIPT_DIR/.env"
    echo ""
    echo "Aktuelles Verzeichnis: $PROJECT_DIR"
    exit 1
fi
echo -e "${GREEN}✓${NC} $ENV_FILE gefunden"

# Parse .env
parse_env() {
    grep "^${1}=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'"
}

SERVER_HOST=$(parse_env "SERVER_HOST")
SERVER_BASE=$(parse_env "SERVER_BASE")
SERVER_PATH=$(parse_env "SERVER_PATH")
SERVER_BACKUP_PATH=$(parse_env "SERVER_BACKUP_PATH")
SERVER_SBOM_PATH=$(parse_env "SERVER_SBOM_PATH")
NAS_PATH=$(parse_env "NAS_PATH")
NAS_SBOM_PATH=$(parse_env "NAS_SBOM_PATH")
PROJEKT_NAME=$(parse_env "PROJECT_NAME")

# Fallback für PROJECT_NAME
[ -z "$PROJEKT_NAME" ] && PROJEKT_NAME="$PROJECT_NAME"

# Expandiere $SERVER_BASE in SERVER_PATH/SERVER_BACKUP_PATH/SERVER_SBOM_PATH falls vorhanden
if [ -n "$SERVER_BASE" ]; then
    SERVER_PATH=$(echo "$SERVER_PATH" | sed "s|\$SERVER_BASE|$SERVER_BASE|g")
    SERVER_BACKUP_PATH=$(echo "$SERVER_BACKUP_PATH" | sed "s|\$SERVER_BASE|$SERVER_BASE|g")
    SERVER_SBOM_PATH=$(echo "$SERVER_SBOM_PATH" | sed "s|\$SERVER_BASE|$SERVER_BASE|g")
fi

# Modus 'sbom' ohne expliziten SERVER_SBOM_PATH/NAS_SBOM_PATH: Pfade relativ zum
# bereits vorhandenen SERVER_PATH ("current"-Symlink, liegt in deploy_path) bzw.
# NAS_PATH ableiten - sbom/ liegt als Nachbarordner von current/releases/shared
# direkt IN deploy_path (pro Umgebung getrennt, z. B. nur live/production statt
# geteilt über stage+production hinweg). Dadurch braucht ein Projekt dafür keine
# eigenen SERVER_SBOM_PATH/NAS_SBOM_PATH-Einträge mehr - funktioniert generisch,
# solange SERVER_PATH/NAS_PATH gesetzt sind (ohnehin Pflicht für db/files-Modus).
if [ -z "$SERVER_SBOM_PATH" ] && [ -n "$SERVER_PATH" ]; then
    SERVER_SBOM_PATH="${SERVER_PATH%/*}/sbom"
fi
if [ -z "$NAS_SBOM_PATH" ] && [ -n "$NAS_PATH" ]; then
    NAS_SBOM_PATH="$(dirname "${NAS_PATH%/}")/sbom"
fi

# Stelle sicher dass Pfad mit / endet
SERVER_BACKUP_PATH="${SERVER_BACKUP_PATH%/}/"
[ -n "$SERVER_SBOM_PATH" ] && SERVER_SBOM_PATH="${SERVER_SBOM_PATH%/}/"

# Validierung
if [ -z "$SERVER_HOST" ] || [ -z "$SERVER_BACKUP_PATH" ] || [ -z "$NAS_PATH" ]; then
    echo -e "${RED}✗ FEHLER: Unvollständige .env${NC}"
    echo ""
    echo "Benötigt in .env:"
    echo "  SERVER_HOST=projekt1-server      # SSH-Config Alias"
    echo "  SERVER_BACKUP_PATH=~/backups/"
    echo "  NAS_PATH=/Volumes/NAS/TYPO3-Backups/"
    echo "  PROJECT_NAME=projekt1"
    exit 1
fi

echo -e "${GREEN}✓${NC} Konfiguration geladen"
echo ""

# Backup-Typ ermitteln (wird von typo3-backup-helpers.zsh per NAS_PULL_MODE gesetzt).
# Muss vor der NAS-Verzeichnis-Anlage feststehen, da SBOM-Dateien auf dem NAS in
# einen eigenen Nachbarordner wandern (Geschwisterordner von NAS_PATH, s.o.),
# nicht in den DB/Files-Backup-Ordner.
BACKUP_TYPE="${NAS_PULL_MODE:-db}"

if [ "$BACKUP_TYPE" = "sbom" ]; then
    if [ -z "$NAS_SBOM_PATH" ]; then
        echo -e "${RED}✗ FEHLER: NAS_SBOM_PATH konnte nicht ermittelt werden${NC}"
        echo "Benötigt für Modus 'sbom': entweder NAS_PATH in .env (davon abgeleitet) oder NAS_SBOM_PATH explizit"
        exit 1
    fi
    NAS_PROJECT_PATH="${NAS_SBOM_PATH%/}/"
else
    NAS_PROJECT_PATH="${NAS_PATH%/}/"
fi

# Erstelle NAS-Verzeichnis
mkdir -p "$NAS_PROJECT_PATH" 2>/dev/null
if [ $? -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: NAS nicht erreichbar oder keine Schreibrechte${NC}"
    echo "  Pfad: $NAS_PROJECT_PATH"
    exit 1
fi
echo -e "${GREEN}✓${NC} NAS-Verzeichnis bereit ($NAS_PROJECT_PATH)"

# Verbinde zu Server (nutzt SSH-Config)
echo ""
echo "Verbinde zu Server..."

# Nutze nur SSH-Config - keine expliziten Parameter
SSH_OPTS="-o ConnectTimeout=10"
SSH_TARGET="$SERVER_HOST"

SSH_TEST=$(ssh $SSH_OPTS "$SSH_TARGET" "exit" 2>&1)
if [ $? -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: Keine Verbindung zum Server${NC}"
    echo ""
    echo "SSH Fehlermeldung:"
    echo "$SSH_TEST"
    echo ""
    echo "Prüfe SSH-Config:"
    echo "  cat ~/.ssh/config | grep -A5 '$SERVER_HOST'"
    echo ""
    echo "Teste manuell:"
    echo "  ssh $SERVER_HOST"
    exit 1
fi
echo -e "${GREEN}✓${NC} Verbunden mit $SERVER_HOST"

# Suche Backups (NEUES Format)
echo ""
echo "Suche Backups..."

if [ "$BACKUP_TYPE" = "files" ]; then
    # Files-Backups: YYYY-MM-DD_HH-MM-SS_PROJECT_TYPO3-vXX_Files_MODE.tar.bz2
    BACKUP_PATTERN="*_Files_*.tar.bz2"
    SEARCH_PATH="$SERVER_BACKUP_PATH"
    echo "Suche Files-Backups..."
elif [ "$BACKUP_TYPE" = "sbom" ]; then
    # SBOM-Backups: YYYY-MM-DD_HH-MM-SS_PROJECT_TYPO3-vXX_SBOM.json, liegt als
    # Nachbarordner von current/releases/shared direkt in deploy_path (siehe
    # SERVER_SBOM_PATH-Ableitung oben) - pro Host/Umgebung getrennt.
    BACKUP_PATTERN="*_SBOM.json"
    SEARCH_PATH="$SERVER_SBOM_PATH"
    echo "Suche SBOM-Backups..."
else
    # DB-Backups: YYYY-MM-DD_HH-MM-SS_PROJECT_TYPO3-vXX_DB_dbname.tar.gz
    BACKUP_PATTERN="*_DB_*.tar.gz"
    SEARCH_PATH="$SERVER_BACKUP_PATH"
    echo "Suche DB-Backups..."
fi

if [ -z "$SEARCH_PATH" ]; then
    echo -e "${RED}✗ FEHLER: Kein Server-Pfad für Modus '$BACKUP_TYPE' konfiguriert${NC}"
    echo "Benötigt in .env: SERVER_PATH (davon abgeleitet) oder SERVER_SBOM_PATH explizit (für Modus 'sbom') bzw. SERVER_BACKUP_PATH"
    exit 1
fi

BACKUP_COUNT=$(ssh $SSH_OPTS "$SSH_TARGET" "ls -1 '$SEARCH_PATH'$BACKUP_PATTERN 2>/dev/null | wc -l" | tr -d ' ')

if [ "$BACKUP_COUNT" -eq 0 ]; then
    echo -e "${YELLOW}⚠ Keine Backups gefunden${NC}"
    echo "Suche-Pattern: $SEARCH_PATH$BACKUP_PATTERN"
    exit 0
fi
echo -e "${GREEN}✓${NC} $BACKUP_COUNT Backup(s) gefunden"

# Neuestes Backup
LATEST=$(ssh $SSH_OPTS "$SSH_TARGET" "ls -t '$SEARCH_PATH'$BACKUP_PATTERN 2>/dev/null | head -1")
FILENAME=$(basename "$LATEST")
LOCAL_FILE="${NAS_PROJECT_PATH}${FILENAME}"

echo ""
echo -e "${CYAN}Neuestes Backup:${NC}"
echo "$FILENAME"
echo ""

# Prüfe ob bereits existiert
if [ -f "$LOCAL_FILE" ]; then
    echo -e "${YELLOW}⚠ Backup existiert bereits auf NAS${NC}"
    if [ -t 0 ]; then
        echo "Trotzdem herunterladen? (y/N)"
        read -r response
        if [ "$response" != "y" ] && [ "$response" != "Y" ]; then
            echo "Übersprungen."
            exit 0
        fi
    else
        # Nicht-interaktiv (Cronjob/Task Scheduler) - kein read, das ohne TTY
        # hängen oder sich unterschiedlich verhalten könnte. Gleiches Ergebnis
        # wie Antwort "N": vorhandenes Backup wird nicht erneut geladen.
        echo "Nicht-interaktiv - bereits vorhandenes Backup wird nicht erneut geladen."
        exit 0
    fi
fi

# Download (ohne Nachfrage)
echo ""
echo "Lade herunter..."

# Rsync (nutzt SSH-Config)
rsync -avz --progress -e "ssh" "$SSH_TARGET:$LATEST" "$NAS_PROJECT_PATH"

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Backup erfolgreich auf NAS gespeichert${NC}"
    echo "  $LOCAL_FILE"

    # Lade auch Checksumme
    CHECKSUM_FILE="${LATEST}.sha256"
    ssh $SSH_OPTS "$SSH_TARGET" "[ -f '$CHECKSUM_FILE' ]" 2>/dev/null
    if [ $? -eq 0 ]; then
        echo ""
        echo "Lade Checksumme..."
        rsync -az -e "ssh" "$SSH_TARGET:$CHECKSUM_FILE" "$NAS_PROJECT_PATH"
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✓${NC} Checksumme geladen"
        fi
    fi
else
    echo ""
    echo -e "${RED}✗ Download fehlgeschlagen${NC}"
    exit 1
fi

echo ""
exit 0
