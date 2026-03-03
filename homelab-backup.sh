#!/bin/bash

# Konfiguracija
BACKUP_DIR="/opt/backup"
TIMESTAMP=$(date +%Y-%m-%d_%H-%M)
BACKUP_NAME="homelab-backup-${TIMESTAMP}"
WORK_DIR="${BACKUP_DIR}/${BACKUP_NAME}"
ZIP_FILE="${BACKUP_DIR}/${BACKUP_NAME}.zip"
DROPBOX_PATH="backup/labubu"
RETENTION_DAYS=30
NOTIFY="/var/www/html/notifierbot/notify"

WARNINGS=()
FATAL=false

# Cleanup radnog direktorijuma pri izlasku
cleanup() {
    rm -rf "${WORK_DIR}"
    rm -f "${ZIP_FILE}"
}
trap cleanup EXIT

# Helper: backup baze
backup_db() {
    local db_path="$1"
    local output_name="$2"
    if [[ ! -f "${db_path}" ]]; then
        WARNINGS+=("Baza ${db_path} ne postoji")
        echo "  SKIP: ${db_path} ne postoji"
        return
    fi
    sqlite3 "${db_path}" ".dump" > "${WORK_DIR}/databases/${output_name}"
    echo "  OK: ${db_path}"
}

# Helper: backup direktorijuma (cp -r)
backup_dir() {
    local src="$1"
    local dest="$2"
    if [[ ! -d "${src}" ]]; then
        WARNINGS+=("Folder ${src} ne postoji")
        echo "  SKIP: ${src} ne postoji"
        return
    fi
    mkdir -p "$(dirname "${dest}")"
    cp -r "${src}" "${dest}"
    echo "  OK: ${src}"
}

# Helper: backup fajlova po patternu (find + cp)
backup_files() {
    local src_dir="$1"
    local dest_dir="$2"
    local pattern="$3"
    if [[ ! -d "${src_dir}" ]]; then
        WARNINGS+=("Folder ${src_dir} ne postoji")
        echo "  SKIP: ${src_dir} ne postoji"
        return
    fi
    mkdir -p "${dest_dir}"
    find "${src_dir}" -name "${pattern}" -exec cp {} "${dest_dir}/" \;
    echo "  OK: ${src_dir} (${pattern})"
}

# Helper: backup config fajla
backup_config() {
    local src="$1"
    local dest="$2"
    cp "${src}" "${dest}" 2>/dev/null || true
}

# Kreiraj radni direktorijum
mkdir -p "${WORK_DIR}"

echo "[$(date)] Pokrećem backup..."

# 1. SQLite baze - dump
echo "Backup SQLite baza..."
mkdir -p "${WORK_DIR}/databases"
backup_db /var/www/html/kosmos/kosmos.db kosmos.sql

# 2. Data folderi
echo "Backup data foldera..."
backup_dir /var/www/html/klopas/data "${WORK_DIR}/klopas/data"
backup_files /var/www/html/legomil/data "${WORK_DIR}/legomil/data" "*.md"
backup_files /var/www/html/crneploce/records "${WORK_DIR}/crneploce/records" "*.json"
backup_dir /var/www/html/troskovnik/data "${WORK_DIR}/troskovnik/data"

# 3. Konfiguracijski fajlovi (.env)
echo "Backup konfiguracija..."
mkdir -p "${WORK_DIR}/config"
backup_config /var/www/html/kosmos/.env "${WORK_DIR}/config/kosmos.env"
backup_config /var/www/html/klopas/.env "${WORK_DIR}/config/klopas.env"
backup_config /var/www/html/legomil/.env "${WORK_DIR}/config/legomil.env"
backup_config /var/www/html/troskovnik/.env "${WORK_DIR}/config/troskovnik.env"
backup_config /var/www/html/crneploce/config.php "${WORK_DIR}/config/crneploce-config.php"

# 4. Kreiraj ZIP
echo "Kreiram ZIP arhivu..."
cd "${BACKUP_DIR}"
if ! zip -r "${ZIP_FILE}" "${BACKUP_NAME}"; then
    FATAL=true
    FATAL_MSG="ZIP kreiranje neuspešno"
fi

# 5. Upload na Dropbox
if ! $FATAL; then
    echo "Upload na Dropbox..."
    if ! rclone copy "${ZIP_FILE}" "dropbox:${DROPBOX_PATH}/"; then
        FATAL=true
        FATAL_MSG="Upload na Dropbox neuspešan"
    fi
fi

# 6. Notifikacija na Telegram - UVEK se šalje
echo "Slanje notifikacije..."
if $FATAL; then
    ${NOTIFY} error "❌ Backup NEUSPEŠAN: ${FATAL_MSG}" \
        --file="${BACKUP_NAME}.zip" || true
elif [[ ${#WARNINGS[@]} -gt 0 ]]; then
    ZIP_SIZE=$(stat -c%s "${ZIP_FILE}" 2>/dev/null || echo "0")
    ZIP_SIZE_MB=$(echo "scale=2; $ZIP_SIZE / 1048576" | bc)
    WARN_LIST=$(printf '%s\n' "${WARNINGS[@]}" | paste -sd ', ' -)
    ${NOTIFY} warning "⚠️ Backup završen sa upozorenjima" \
        --file="${BACKUP_NAME}.zip" \
        --size="${ZIP_SIZE_MB}MB" \
        --destination="Dropbox" \
        --preskočeno="${WARN_LIST}" || true
else
    ZIP_SIZE=$(stat -c%s "${ZIP_FILE}" 2>/dev/null || echo "0")
    ZIP_SIZE_MB=$(echo "scale=2; $ZIP_SIZE / 1048576" | bc)
    ${NOTIFY} backup "✅ Homelab backup završen" \
        --file="${BACKUP_NAME}.zip" \
        --size="${ZIP_SIZE_MB}MB" \
        --destination="Dropbox" || true
fi

# 7. Čišćenje starih backup-a na Dropbox-u (starije od RETENTION_DAYS)
if ! $FATAL; then
    echo "Čišćenje starih backup-a..."
    rclone delete --min-age ${RETENTION_DAYS}d "dropbox:${DROPBOX_PATH}/" || true
fi

if $FATAL; then
    echo "[$(date)] Backup NEUSPEŠAN: ${FATAL_MSG}"
    exit 1
elif [[ ${#WARNINGS[@]} -gt 0 ]]; then
    echo "[$(date)] Backup završen sa ${#WARNINGS[@]} upozorenja."
else
    echo "[$(date)] Backup završen uspešno!"
fi
