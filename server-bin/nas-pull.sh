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
SERVER_BACKUP_PATH=$(parse_env "SERVER_BACKUP_PATH")
NAS_PATH=$(parse_env "NAS_PATH")
PROJEKT_NAME=$(parse_env "PROJECT_NAME")

# Fallback für PROJECT_NAME
[ -z "$PROJEKT_NAME" ] && PROJEKT_NAME="$PROJECT_NAME"

# Expandiere $SERVER_BASE in SERVER_BACKUP_PATH falls vorhanden
if [ -n "$SERVER_BASE" ]; then
    SERVER_BACKUP_PATH=$(echo "$SERVER_BACKUP_PATH" | sed "s|\$SERVER_BASE|$SERVER_BASE|g")
fi

# Stelle sicher dass Pfad mit / endet
SERVER_BACKUP_PATH="${SERVER_BACKUP_PATH%/}/"

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

# Erstelle NAS-Verzeichnis
NAS_PROJECT_PATH="${NAS_PATH%/}/"
mkdir -p "$NAS_PROJECT_PATH" 2>/dev/null
if [ $? -ne 0 ]; then
    echo -e "${RED}✗ FEHLER: NAS nicht erreichbar oder keine Schreibrechte${NC}"
    echo "  Pfad: $NAS_PROJECT_PATH"
    exit 1
fi
echo -e "${GREEN}✓${NC} NAS-Verzeichnis bereit"

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

# Erkenne Backup-Typ automatisch
# Wenn aus Files-Backup aufgerufen: Suche Files
# Sonst: Suche DB (Standard)

# Prüfe ob NAS_PULL_MODE gesetzt ist (wird von typo3-backup-helpers.zsh gesetzt)
BACKUP_TYPE="${NAS_PULL_MODE:-db}"

if [ "$BACKUP_TYPE" = "files" ]; then
    # Files-Backups: YYYY-MM-DD_HH-MM-SS_PROJECT_TYPO3-vXX_Files_MODE.tar.bz2
    BACKUP_PATTERN="*_Files_*.tar.bz2"
    echo "Suche Files-Backups..."
else
    # DB-Backups: YYYY-MM-DD_HH-MM-SS_PROJECT_TYPO3-vXX_DB_dbname.tar.gz
    BACKUP_PATTERN="*_DB_*.tar.gz"
    echo "Suche DB-Backups..."
fi

BACKUP_COUNT=$(ssh $SSH_OPTS "$SSH_TARGET" "ls -1 '$SERVER_BACKUP_PATH'$BACKUP_PATTERN 2>/dev/null | wc -l" | tr -d ' ')

if [ "$BACKUP_COUNT" -eq 0 ]; then
    echo -e "${YELLOW}⚠ Keine Backups gefunden${NC}"
    echo "Suche-Pattern: $SERVER_BACKUP_PATH$BACKUP_PATTERN"
    exit 0
fi
echo -e "${GREEN}✓${NC} $BACKUP_COUNT Backup(s) gefunden"

# Neuestes Backup
LATEST=$(ssh $SSH_OPTS "$SSH_TARGET" "ls -t '$SERVER_BACKUP_PATH'$BACKUP_PATTERN 2>/dev/null | head -1")
FILENAME=$(basename "$LATEST")
LOCAL_FILE="${NAS_PROJECT_PATH}${FILENAME}"

echo ""
echo -e "${CYAN}Neuestes Backup:${NC}"
echo "$FILENAME"
echo ""

# Prüfe ob bereits existiert
if [ -f "$LOCAL_FILE" ]; then
    echo -e "${YELLOW}⚠ Backup existiert bereits auf NAS${NC}"
    echo "Trotzdem herunterladen? (y/N)"
    read -r response
    if [ "$response" != "y" ] && [ "$response" != "Y" ]; then
        echo "Übersprungen."
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
