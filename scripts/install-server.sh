#!/usr/bin/env bash
#
# Installs the newest GymSLunity release as a production instance on a Debian
# or Ubuntu server, see docs/installation.md:
#
#   curl -fsSLO https://raw.githubusercontent.com/Abiturientia-am-GymSL-e-V/GymSLunity/main/scripts/install-server.sh
#   sudo bash install-server.sh               # install
#   sudo bash install-server.sh --uninstall   # remove again
#
# Afterwards the instance runs completely: own system user and PHP-FPM pool,
# Nginx with HTTPS, MariaDB or SQLite, .env, administrator account, queue
# worker, scheduler and daily backups. Updates stay manual
# (docs/installation.md, "Updates").
#
# Existing software is left alone: PHP 8.4 is installed next to other PHP
# versions without changing the default, Nginx and PHP-FPM only get their own
# site and pool and are reloaded, never restarted. Every change is recorded in
# a journal. On an error or Ctrl+C the installer undoes the changes of the
# current run; --uninstall replays the same journal later and keeps the data
# unless their deletion is confirmed. The shared parts live in
# install-common.sh.
#
# All questions can be answered in advance with environment variables (see
# --help), which together with --yes allows unattended installs.

set -Eeuo pipefail
umask 022

readonly repo="${GYMSLUNITY_REPO:-Abiturientia-am-GymSL-e-V/GymSLunity}"
readonly state_base=/var/lib/gymslunity-installer
readonly installer_label=install-server.sh

script_dir=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi

# The shared functions come from next to this script or, when it was
# downloaded alone, from the same branch on GitHub.
if [[ -n $script_dir && -f $script_dir/install-common.sh ]]; then
    # shellcheck source=install-common.sh
    source "$script_dir/install-common.sh"
else
    common_file="$(mktemp)"
    common_url="https://raw.githubusercontent.com/$repo/${GYMSLUNITY_REF:-main}/scripts/install-common.sh"
    if ! { curl -fsSL "$common_url" -o "$common_file" 2> /dev/null || wget -qO "$common_file" "$common_url"; }; then
        echo "Fehler: $common_url konnte nicht geladen werden." >&2
        exit 1
    fi
    # shellcheck source=install-common.sh
    source "$common_file"
    rm -f "$common_file"
fi

mode=install
fail_after_variable=GYMSLUNITY_TEST_FAIL_AFTER
app_dir=""
backup_dir=""
db_driver=""
db_host=127.0.0.1
db_port=3306
db_name=""
db_user=""
db_password=""
db_ca=""
db_tested=false
mariadb_present=false

# --- Usage -------------------------------------------------------------------

usage() {
    cat << EOF
Installiert das neueste GymSLunity-Release als Produktivinstanz auf einem
Debian- oder Ubuntu-Server (siehe docs/installation.md).

Aufruf:
  sudo bash install-server.sh [--yes]
  sudo bash install-server.sh --uninstall [--yes]

Optionen:
  --yes         Keine Rückfragen, Vorgaben und Umgebungsvariablen verwenden
  --uninstall   Eine mit diesem Skript eingerichtete Instanz entfernen
                (Daten bleiben, außer ihre Löschung wird bestätigt)
  -h, --help    Diese Hilfe

Umgebungsvariablen (Antworten auf die Rückfragen):
  GYMSLUNITY_NAME            Kurzname für Benutzer, Datenbank, Dienste und Dateien (gymslunity)
  GYMSLUNITY_DOMAIN          Domain der Instanz (Pflicht bei --yes)
  GYMSLUNITY_DIR             Installationsverzeichnis (/var/www/<name>)
  GYMSLUNITY_TLS             letsencrypt, existing oder proxy (letsencrypt)
  GYMSLUNITY_LE_EMAIL        E-Mail-Adresse für Let's Encrypt (optional)
  GYMSLUNITY_LE_STAGING      1 = Test-Zertifikate von Let's Encrypt
  GYMSLUNITY_CERT, GYMSLUNITY_CERT_KEY   Zertifikat und Schlüssel bei GYMSLUNITY_TLS=existing
  GYMSLUNITY_PROXIES         Adressen des Reverse Proxys bei GYMSLUNITY_TLS=proxy
  GYMSLUNITY_DB              mariadb, external oder sqlite (mariadb)
  GYMSLUNITY_DB_HOST, GYMSLUNITY_DB_PORT, GYMSLUNITY_DB_DATABASE,
  GYMSLUNITY_DB_USERNAME, GYMSLUNITY_DB_PASSWORD
                             Zugangsdaten bei GYMSLUNITY_DB=external
  GYMSLUNITY_DB_SSL_CA       CA-Zertifikat für TLS zur externen Datenbank (optional)
  GYMSLUNITY_CLUB_NAME       Name des Vereins als Absender der E-Mails (Pflicht bei --yes)
  GYMSLUNITY_MAIL_FROM       Absenderadresse (noreply@<domain>)
  GYMSLUNITY_BACKUP_DIR      Verzeichnis der täglichen Backups (/var/backups/<name>)
  GYMSLUNITY_ADMIN_NAME, GYMSLUNITY_ADMIN_EMAIL, GYMSLUNITY_ADMIN_PASSWORD
                             Erstes Administratorkonto (Pflicht bei --yes)
  GYMSLUNITY_OPEN_FIREWALL   1/0: Ports 80/443 in ufw freigeben, falls aktiv (1)
  GYMSLUNITY_RELEASE_TAG     Zu installierendes Release (neuestes)
  GYMSLUNITY_ARCHIVE         Lokales Release-Archiv statt Download
  GYMSLUNITY_UNINSTALL_DATA  1 = bei --uninstall --yes auch Datenbank, Dateien und Backups löschen
  GYMSLUNITY_UNINSTALL_PACKAGES   1 = bei --uninstall --yes auch Pakete entfernen
  GYMSLUNITY_REPO            GitHub-Repository ($repo)
EOF
}

# --- Validators --------------------------------------------------------------

valid_db() {
    [[ $1 == mariadb || $1 == external || $1 == sqlite ]] || {
        warn "mariadb, external oder sqlite."
        return 1
    }
}

valid_db_name() {
    [[ $1 =~ ^[A-Za-z0-9_\$-]{1,64}$ ]] || {
        warn "Buchstaben, Ziffern, _, - und \$, höchstens 64 Zeichen."
        return 1
    }
}

valid_db_user() {
    [[ $1 =~ ^[^[:space:]\'\"\\]{1,80}$ ]] || {
        warn "Ohne Leerzeichen, Anführungszeichen und Backslash."
        return 1
    }
}

# The password goes into the .env in single quotes, which cannot contain one.
valid_db_password() {
    [[ -n $1 && $1 != *"'"* && $1 != *$'\n'* ]] || {
        warn "Passwörter mit ' werden nicht unterstützt."
        return 1
    }
}

# The password rules of GymSLunity (Password::defaults), checked early so the
# installation does not fail at the very end.
valid_admin_password() {
    local p=$1
    [[ ${#p} -ge 12 && ${#p} -le 72 && $p =~ [a-z] && $p =~ [A-Z] && $p =~ [0-9] && $p =~ [^A-Za-z0-9] ]] || {
        warn "12 bis 72 Zeichen mit Groß- und Kleinbuchstaben, Ziffer und Sonderzeichen."
        return 1
    }
}

valid_backup_dir() {
    valid_new_dir "$1" || return 1
    [[ $1 != "$GYMSLUNITY_DIR"/* ]] || {
        warn "Das Backup-Verzeichnis darf nicht im Installationsverzeichnis liegen."
        return 1
    }
}

# --- Configuration -----------------------------------------------------------

configure() {
    step "Einstellungen"
    info "Enter übernimmt den Wert in eckigen Klammern."
    echo

    ask GYMSLUNITY_NAME "Kurzname für Benutzer, Datenbank, Dienste und Dateien" gymslunity valid_name
    inst_name=$GYMSLUNITY_NAME
    state_dir="$state_base/$inst_name"
    [[ ! -e $state_dir ]] || die "Für '$inst_name' gibt es bereits eine Installation. Zuerst mit --uninstall entfernen."

    ask GYMSLUNITY_DOMAIN "Domain, unter der GymSLunity erreichbar sein soll" "" valid_domain
    ask GYMSLUNITY_DIR "Installationsverzeichnis" "/var/www/$inst_name" valid_new_dir
    GYMSLUNITY_DIR=${GYMSLUNITY_DIR%/}
    app_dir=$GYMSLUNITY_DIR
    domains=("$GYMSLUNITY_DOMAIN")
    site_publics=("$app_dir/public")

    ask_tls GYMSLUNITY

    echo
    info "Datenbank:"
    info "  mariadb   lokaler MariaDB-Server; wird bei Bedarf installiert, Datenbank und Benutzer legt das Skript an"
    info "  external  vorhandene, leere Datenbank auf diesem oder einem anderen Server (MariaDB oder MySQL)"
    info "  sqlite    eine Datei, ohne Datenbankserver; genügt für die meisten Vereine"
    ask GYMSLUNITY_DB "Datenbank" mariadb valid_db
    # shellcheck disable=SC2153 # set by ask
    db_driver=$GYMSLUNITY_DB
    case "$db_driver" in
        mariadb)
            extra_php_packages=("php$php_version-mysql")
            extra_php_extensions=(pdo_mysql)
            db_name=$inst_name
            db_user=$inst_name
            if [[ -x /usr/sbin/mariadbd || -x /usr/sbin/mysqld ]]; then
                mariadb_present=true
            else
                extra_packages=(mariadb-server mariadb-client)
            fi
            ;;
        external)
            extra_php_packages=("php$php_version-mysql")
            extra_php_extensions=(pdo_mysql)
            # The client tests the access and makes the backups.
            command -v mariadb-dump > /dev/null || command -v mysqldump > /dev/null ||
                extra_packages=(mariadb-client)
            ask_external_database
            ;;
    esac

    echo
    ask GYMSLUNITY_CLUB_NAME "Name des Vereins (Absender der E-Mails)" "" valid_env_text
    ask GYMSLUNITY_MAIL_FROM "Absenderadresse der E-Mails" "noreply@$GYMSLUNITY_DOMAIN" valid_email
    ask GYMSLUNITY_BACKUP_DIR "Verzeichnis der täglichen Backups" "/var/backups/$inst_name" valid_backup_dir
    GYMSLUNITY_BACKUP_DIR=${GYMSLUNITY_BACKUP_DIR%/}
    backup_dir=$GYMSLUNITY_BACKUP_DIR

    echo
    info "Erstes Administratorkonto:"
    ask GYMSLUNITY_ADMIN_NAME "Name" "" valid_env_text
    ask GYMSLUNITY_ADMIN_EMAIL "E-Mail-Adresse (Anmeldename)" "" valid_email
    GYMSLUNITY_ADMIN_EMAIL=${GYMSLUNITY_ADMIN_EMAIL,,}
    ask_secret GYMSLUNITY_ADMIN_PASSWORD "Passwort (12 Zeichen, Groß-/Kleinbuchstaben, Ziffer, Sonderzeichen)" valid_admin_password

    ask_firewall GYMSLUNITY
}

# ask_external_database — asks for the access data of an existing database and
# tests it right away if a client is available, otherwise only whether the
# server answers; the full test then follows after the package installation.
ask_external_database() {
    echo
    info "Die Datenbank muss bereits existieren und leer sein; der Benutzer braucht alle Rechte darauf."
    while true; do
        ask GYMSLUNITY_DB_HOST "Datenbankserver (Hostname oder IP-Adresse)" "" valid_host
        ask GYMSLUNITY_DB_PORT "Port" 3306 valid_port
        ask GYMSLUNITY_DB_DATABASE "Name der Datenbank" "$inst_name" valid_db_name
        ask GYMSLUNITY_DB_USERNAME "Benutzer" "$inst_name" valid_db_user
        ask_secret GYMSLUNITY_DB_PASSWORD "Passwort" valid_db_password once
        ask GYMSLUNITY_DB_SSL_CA "CA-Zertifikat für TLS zur Datenbank (optional, leer = ohne TLS)" "" valid_optional_file
        db_host=$GYMSLUNITY_DB_HOST
        db_port=$GYMSLUNITY_DB_PORT
        db_name=$GYMSLUNITY_DB_DATABASE
        db_user=$GYMSLUNITY_DB_USERNAME
        db_password=$GYMSLUNITY_DB_PASSWORD
        db_ca=$GYMSLUNITY_DB_SSL_CA

        local problem
        if command -v "$(mariadb_client)" > /dev/null; then
            if problem="$(test_database)"; then
                db_tested=true
                info "Verbindung, Anmeldung und Rechte in Ordnung, die Datenbank ist leer."
                break
            fi
        elif timeout 5 bash -c "exec 3<> /dev/tcp/$db_host/$db_port" 2> /dev/null; then
            info "Der Server antwortet. Anmeldung und Rechte werden nach der Installation des Datenbank-Clients geprüft."
            break
        else
            problem="$db_host:$db_port ist nicht erreichbar."
        fi
        retry_database "$problem"
    done
    if [[ -z $db_ca && ! $db_host =~ ^(127\.|localhost$|::1$) ]]; then
        warn "Die Verbindung zu $db_host ist nicht verschlüsselt. Nur in einem vertrauenswürdigen Netz verwenden oder ein CA-Zertifikat angeben."
    fi
}

# retry_database PROBLEM — reports the problem and asks for new access data.
retry_database() {
    warn "$1"
    if $assume_yes || ! confirm "Zugangsdaten erneut eingeben?" y; then
        die "Die Datenbank ist nicht nutzbar."
    fi
    # Asks again; the previous answers are the defaults, except the password.
    GYMSLUNITY_DB_PASSWORD=""
}

# test_database — checks login, access, emptiness and the right to create
# tables; prints the problem and fails otherwise.
test_database() {
    local options client output
    options="$(mktemp)"
    db_option_file "$options" "$db_host" "$db_port" "$db_user" "$db_password" "$db_ca"
    client="$(mariadb_client)"
    if ! output="$("$client" --defaults-extra-file="$options" -N -B -e \
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" "$db_name" 2>&1)"; then
        rm -f -- "$options"
        echo "Anmeldung als $db_user an $db_host:$db_port, Datenbank $db_name, fehlgeschlagen: $(tail -n1 <<< "$output")"
        return 1
    fi
    if [[ $output != 0 ]]; then
        rm -f -- "$options"
        echo "Die Datenbank $db_name ist nicht leer ($output Tabellen). GymSLunity braucht eine eigene, leere Datenbank."
        return 1
    fi
    if ! output="$("$client" --defaults-extra-file="$options" -e \
        "CREATE TABLE gymslunity_install_check (id INT); DROP TABLE gymslunity_install_check;" "$db_name" 2>&1)"; then
        rm -f -- "$options"
        echo "$db_user darf in $db_name keine Tabellen anlegen: $(tail -n1 <<< "$output")"
        return 1
    fi
    rm -f -- "$options"
}

# verify_database — the full test of an external database, if the client was
# missing during the questions. Offers to correct the access data.
verify_database() {
    [[ $db_driver == external ]] && ! $db_tested || return 0
    step "Externe Datenbank"
    local problem
    until problem="$(test_database)"; do
        retry_database "$problem"
        ask_external_database
        $db_tested && break
    done
    db_tested=true
    info "Verbindung, Anmeldung und Rechte in Ordnung, die Datenbank ist leer."
}

check_conflicts() {
    step "Prüfe das bestehende System"
    problems=()
    check_common_conflicts \
        "/etc/systemd/system/$inst_name-queue.service" \
        "/etc/systemd/system/$inst_name-schedule.service" \
        "/etc/systemd/system/$inst_name-schedule.timer"

    if $mariadb_present; then
        local client
        client="$(mariadb_client)"
        if ! "$client" -e 'SELECT 1' > /dev/null 2>&1; then
            problems+=("Der vorhandene Datenbankserver ist nicht erreichbar oder root hat keinen Zugriff per Socket ('sudo $client' muss ohne Passwort funktionieren). Alternativ SQLite wählen.")
        else
            if [[ -n "$("$client" -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$inst_name'")" ]]; then
                problems+=("Die Datenbank '$inst_name' existiert bereits.")
            fi
            if [[ -n "$("$client" -N -e "SELECT User FROM mysql.user WHERE User = '$inst_name'")" ]]; then
                problems+=("Der Datenbankbenutzer '$inst_name' existiert bereits.")
            fi
            # GymSLunity connects over TCP, on the port the server really uses.
            local port skip_networking
            read -r port skip_networking < <("$client" -N -B -e 'SELECT @@port, @@skip_networking')
            if [[ $skip_networking == 1 ]]; then
                problems+=("Der vorhandene Datenbankserver nimmt keine TCP-Verbindungen an (skip-networking). Alternativ SQLite wählen.")
            fi
            db_port=${port:-3306}
        fi
        if ! command -v mariadb-dump > /dev/null && ! command -v mysqldump > /dev/null; then
            [[ -x /usr/sbin/mariadbd ]] && extra_packages+=(mariadb-client) || extra_packages+=(mysql-client)
        fi
    fi
    report_conflicts
}

summary() {
    step "Zusammenfassung"
    info "Adresse:           https://$GYMSLUNITY_DOMAIN"
    info "Verzeichnis:       $app_dir"
    info "Benutzer/Dienste:  $inst_name"
    info "HTTPS:             $tls_mode"
    case "$db_driver" in
        mariadb) info "Datenbank:         lokaler MariaDB-Server, Datenbank und Benutzer '$inst_name' (Port $db_port)" ;;
        external) info "Datenbank:         $db_user@$db_host:$db_port, Datenbank $db_name ($([[ -n $db_ca ]] && echo "TLS" || echo "ohne TLS"))" ;;
        sqlite) info "Datenbank:         SQLite ($app_dir/storage/database/database.sqlite)" ;;
    esac
    info "Backups:           $backup_dir, täglich um 02:30 Uhr"
    info "Administrator:     $GYMSLUNITY_ADMIN_NAME <$GYMSLUNITY_ADMIN_EMAIL>"
    echo
    confirm "Installation starten?" y || exit 1
}

# --- Installation steps ------------------------------------------------------

fetch_release() {
    download_release "${GYMSLUNITY_RELEASE_TAG:-}" "${GYMSLUNITY_ARCHIVE:-}"
    tar -xzOf "$archive" "$(head -n1 <<< "$release_listing" | cut -d/ -f1)/app/Console/Commands/InstallApplication.php" |
        grep -q 'admin-password-file' ||
        die "$release_tag unterstützt die unbeaufsichtigte Einrichtung noch nicht. Mit GYMSLUNITY_RELEASE_TAG ein neueres Release wählen."
}

create_user_and_directories() {
    step "Benutzer und Verzeichnisse"
    record data undo_remove_user "$inst_name"
    useradd --system --user-group --home-dir "$app_dir" --no-create-home --shell /usr/sbin/nologin "$inst_name"
    info "Systembenutzer $inst_name ohne Anmeldung angelegt."

    # The code belongs to root and is only readable for PHP, so a compromised
    # application cannot change it. Nginx needs public/ and may traverse to
    # storage/app/public; everything else in storage/ stays private.
    record data undo_remove_tree "$app_dir"
    install -d -o root -g root -m 0755 "$app_dir"
    tar -xzf "$archive" -C "$app_dir" --strip-components=1 --no-same-owner
    local dir
    for dir in app/private app/public framework/cache/data framework/sessions framework/views logs; do
        install -d "$app_dir/storage/$dir"
    done
    chown -R "$inst_name:$inst_name" "$app_dir/storage" "$app_dir/bootstrap/cache"
    find "$app_dir/storage" "$app_dir/bootstrap/cache" -type d -exec chmod 0700 {} +
    find "$app_dir/storage" "$app_dir/bootstrap/cache" -type f -exec chmod 0600 {} +
    chmod 0711 "$app_dir/storage" "$app_dir/storage/app"
    find "$app_dir/storage/app/public" -type d -exec chmod 0755 {} +
    find "$app_dir/storage/app/public" -type f -exec chmod 0644 {} +
    [[ -e $app_dir/public/storage ]] || ln -s ../storage/app/public "$app_dir/public/storage"
    if [[ -n $db_ca ]]; then
        install -m 0640 -o root -g "$inst_name" "$db_ca" "$app_dir/storage/database-ca.pem"
    fi
    if [[ $db_driver == sqlite ]]; then
        install -d -o "$inst_name" -g "$inst_name" -m 0700 "$app_dir/storage/database"
        install -m 0600 -o "$inst_name" -g "$inst_name" /dev/null "$app_dir/storage/database/database.sqlite"
    fi
    info "$app_dir ($release_tag)"

    record data undo_remove_tree "$backup_dir"
    install -d -o "$inst_name" -g "$inst_name" -m 0700 "$backup_dir"
    info "$backup_dir"
}

setup_database() {
    if [[ $db_driver == external ]]; then
        # Empty before the installation, so a rollback or a confirmed deletion
        # removes only what GymSLunity created.
        record data undo_empty_database "$app_dir/.env"
        return 0
    fi
    [[ $db_driver == mariadb ]] || return 0
    step "MariaDB"
    systemctl is-active -q mariadb 2> /dev/null || systemctl is-active -q mysql 2> /dev/null ||
        systemctl start mariadb
    db_password="$(openssl rand -hex 24)"
    record data undo_drop_mariadb "$inst_name"
    "$(mariadb_client)" << EOF
CREATE DATABASE \`$inst_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$inst_name'@'localhost' IDENTIFIED BY '$db_password';
CREATE USER '$inst_name'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`$inst_name\`.* TO '$inst_name'@'localhost', '$inst_name'@'127.0.0.1';
FLUSH PRIVILEGES;
EOF
    info "Datenbank und Benutzer '$inst_name' mit zufälligem Passwort angelegt."
}

# env_set FILE KEY VALUE — sets a value, also when it is commented out.
env_set() {
    local file=$1 key=$2 value=$3
    # ENVIRON instead of -v, which would interpret backslashes in the value.
    KEY=$key VALUE=$value awk '
        !done && ($0 ~ "^" ENVIRON["KEY"] "=" || $0 ~ "^# *" ENVIRON["KEY"] "=") { print ENVIRON["KEY"] "=" ENVIRON["VALUE"]; done = 1; next }
        { print }
        END { if (!done) print ENVIRON["KEY"] "=" ENVIRON["VALUE"] }
    ' "$file" > "$file.new"
    cat "$file.new" > "$file"
    rm -f -- "$file.new"
}

write_env_file() {
    step "Konfiguration (.env)"
    local env="$app_dir/.env"
    # Readable for PHP, but only root may change it.
    install -m 0640 -o root -g "$inst_name" "$app_dir/.env.example" "$env"
    env_set "$env" APP_ENV production
    env_set "$env" APP_KEY "base64:$(random_base64)"
    env_set "$env" APP_DEBUG false
    env_set "$env" APP_URL "https://$GYMSLUNITY_DOMAIN"
    env_set "$env" PASSKEYS_USER_HANDLE_SECRET "base64:$(random_base64)"
    env_set "$env" LOG_STACK daily
    env_set "$env" LOG_LEVEL warning
    env_set "$env" SESSION_SECURE_COOKIE true
    env_set "$env" BACKUP_PATH "$backup_dir"
    env_set "$env" MAIL_FROM_ADDRESS "\"$GYMSLUNITY_MAIL_FROM\""
    env_set "$env" MAIL_FROM_NAME "\"$GYMSLUNITY_CLUB_NAME\""
    if [[ $db_driver != sqlite ]]; then
        env_set "$env" DB_CONNECTION mysql
        env_set "$env" DB_HOST "$db_host"
        env_set "$env" DB_PORT "$db_port"
        env_set "$env" DB_DATABASE "$db_name"
        env_set "$env" DB_USERNAME "$db_user"
        # Single quotes keep $, # and spaces literal.
        env_set "$env" DB_PASSWORD "'$db_password'"
        [[ -z $db_ca ]] || env_set "$env" MYSQL_ATTR_SSL_CA "$app_dir/storage/database-ca.pem"
    else
        env_set "$env" DB_CONNECTION sqlite
        env_set "$env" DB_DATABASE "$app_dir/storage/database/database.sqlite"
    fi
    info "$env (APP_KEY und PASSKEYS_USER_HANDLE_SECRET zufällig erzeugt)"
}

run_app_install() {
    step "GymSLunity einrichten"
    # The password goes through stdin, never into the process list.
    printf '%s\n' "$GYMSLUNITY_ADMIN_PASSWORD" |
        (cd "$app_dir" && runuser -u "$inst_name" -- "$php_bin" artisan app:install --force --skip-storage-link \
            --admin-name="$GYMSLUNITY_ADMIN_NAME" --admin-email="$GYMSLUNITY_ADMIN_EMAIL" --admin-password-file=-)
    [[ -f $app_dir/storage/app/installed ]] || die "Die Einrichtung hat den Browser-Installer nicht gesperrt."
}

install_units() {
    step "Queue-Worker und Scheduler"
    local dir=/etc/systemd/system hardening after=network.target
    hardening="$(unit_hardening "$app_dir/storage" "$app_dir/bootstrap/cache" "$backup_dir")"
    [[ $db_driver == mariadb ]] && after+=" mariadb.service mysql.service"

    record core undo_remove_unit_files "$dir/$inst_name-queue.service" "$dir/$inst_name-schedule.service" "$dir/$inst_name-schedule.timer"
    cat > "$dir/$inst_name-queue.service" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity $inst_name: Queue-Worker
After=$after
StartLimitIntervalSec=0

[Service]
WorkingDirectory=$app_dir
ExecStart=$php_bin artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5
$hardening

[Install]
WantedBy=multi-user.target
EOF
    cat > "$dir/$inst_name-schedule.service" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity $inst_name: Scheduler
After=$after

[Service]
Type=oneshot
WorkingDirectory=$app_dir
ExecStart=$php_bin artisan schedule:run
$hardening
EOF
    cat > "$dir/$inst_name-schedule.timer" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity $inst_name: Scheduler jede Minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    record core undo_remove_units "$inst_name-queue.service" "$inst_name-schedule.timer"
    systemctl enable --now -q "$inst_name-queue.service" "$inst_name-schedule.timer"
    info "$inst_name-queue.service, $inst_name-schedule.timer"
}

save_state() {
    cat > "$state_dir/config" << EOF
# $installer_label, $(date -Iseconds)
GYMSLUNITY_NAME=$inst_name
GYMSLUNITY_DOMAIN=$GYMSLUNITY_DOMAIN
GYMSLUNITY_DIR=$app_dir
GYMSLUNITY_TLS=$tls_mode
GYMSLUNITY_DB=$db_driver
GYMSLUNITY_DB_HOST=$db_host
GYMSLUNITY_DB_DATABASE=$db_name
GYMSLUNITY_BACKUP_DIR=$backup_dir
NGINX_CONF=$nginx_conf
RELEASE_TAG=$release_tag
EOF
    chmod 0600 "$state_dir/config"
}

check_instance() {
    step "Prüfe die Instanz"
    local path
    for path in /up /login; do
        if [[ "$(http_status "$GYMSLUNITY_DOMAIN" "$path")" == 200 ]]; then
            info "$path antwortet."
        else
            warn "$path antwortet nicht. Logs: $app_dir/storage/logs/"
        fi
    done
    # Fails on the mail transport until SMTP is configured, so only report.
    (cd "$app_dir" && runuser -u "$inst_name" -- "$php_bin" artisan security:check) ||
        info "Hinweise von security:check siehe oben; die Prüfung des Mailtransports schlägt bis zur Einrichtung von SMTP fehl."
}

print_result() {
    printf '\n%sGymSLunity ist eingerichtet.%s\n\n' "$c_green" "$c_off"
    info "Adresse:        https://$GYMSLUNITY_DOMAIN   ($release_tag)"
    info "Anmeldung:      $GYMSLUNITY_ADMIN_EMAIL"
    info "Verzeichnis:    $app_dir"
    info "Backups:        $backup_dir (täglich 02:30 Uhr, 30 Tage)"
    info "Logs:           $app_dir/storage/logs/, journalctl -u '$inst_name-*'"
    cat << EOF

${c_blue}==> Noch zu tun${c_off}
    1. Anmelden und unter Konfiguration → E-Mail-Versand den SMTP-Server
       einrichten und eine Testmail senden. Bis dahin landen E-Mails nur im Log.
    2. Die .env ($app_dir/.env) an einem zweiten, geschützten Ort sichern.
       Ohne APP_KEY sind verschlüsselte Daten und Backups unbrauchbar.
    3. Backups regelmäßig verschlüsselt auf ein anderes System kopieren.
    4. Updates von Hand nach docs/installation.md, Abschnitt Updates; artisan-Befehle
       dort als '$inst_name' ausführen: sudo -u $inst_name $php_bin artisan …

    GymSLunity sendet einen HSTS-Header auch für Subdomains. Müssen Subdomains
    ohne HTTPS erreichbar bleiben, SECURITY_HSTS_MAX_AGE=0 in der .env setzen.

    Entfernen: sudo bash install-server.sh --uninstall (Daten bleiben erhalten,
    außer ihre Löschung wird ausdrücklich bestätigt)
EOF
}

install_server() {
    open_terminal
    preflight
    configure
    check_conflicts
    summary

    begin_install
    run_tasks install_packages verify_database open_firewall_ports fetch_release \
        create_user_and_directories setup_database write_env_file \
        configure_php_fpm run_app_install configure_nginx install_units save_state
    check_instance
    print_result
}

# --- Uninstall ---------------------------------------------------------------

# final_backup — a last complete backup outside the directories to be deleted.
final_backup() {
    local dir=${GYMSLUNITY_DIR:-} target newest
    [[ -n $dir && -f $dir/artisan ]] || return 1
    step "Letztes Backup"
    (cd "$dir" && runuser -u "$inst_name" -- "$php_bin" artisan app:backup) || return 1
    newest="$(find "$GYMSLUNITY_BACKUP_DIR" -maxdepth 1 -name '*.zip' -printf '%T@ %p\n' 2> /dev/null | sort -rn | head -n1 | cut -d' ' -f2-)"
    [[ -n $newest ]] || return 1
    target="/root/$inst_name-letztes-backup-$(date +%Y%m%d-%H%M%S).zip"
    install -m 0600 -o root -g root "$newest" "$target"
    info "$target"
    info "Es enthält die .env mit APP_KEY; sicher aufbewahren oder löschen."
}

uninstall_server() {
    open_terminal
    [[ $EUID -eq 0 ]] || die "Bitte mit sudo oder als root ausführen."
    select_install GYMSLUNITY Installation
    if [[ -f $state_dir/config ]]; then
        # shellcheck source=/dev/null
        source <(grep -E '^GYMSLUNITY_(DIR|BACKUP_DIR|DB|DB_HOST|DB_DATABASE|DOMAIN)=' "$state_dir/config")
    fi

    step "GymSLunity '$inst_name' entfernen"
    [[ -f $state_dir/config ]] && sed -n 's/^\(GYMSLUNITY_[A-Z_]*\)=/    \1: /p' "$state_dir/config"
    [[ -f $state_dir/config ]] || warn "Die Installation wurde nicht abgeschlossen; es wird entfernt, was angelegt wurde."
    echo
    info "Entfernt werden Nginx-Site, PHP-FPM-Pool, Worker, Scheduler und das Let's-Encrypt-Zertifikat."
    confirm "GymSLunity '$inst_name' entfernen?" "$($assume_yes && echo y || echo n)" || exit 1

    local kinds="core" delete_data=false
    echo
    info "Datenbank, hochgeladene Dateien, .env, Backups und der Benutzer '$inst_name' bleiben"
    info "erhalten, außer du bestätigst ihre Löschung."
    [[ ${GYMSLUNITY_DB:-} != external ]] ||
        info "Bei der externen Datenbank werden dann nur ihre Tabellen gelöscht, die Datenbank und ihr Benutzer bleiben."
    local data_default=n
    [[ ${GYMSLUNITY_UNINSTALL_DATA:-0} == 1 ]] && data_default=y
    if confirm "Auch alle Daten unwiderruflich löschen?" "$data_default"; then
        if ! $assume_yes; then
            local answer=""
            ask answer "Zur Bestätigung den Namen '$inst_name' eingeben"
            [[ $answer == "$inst_name" ]] || die "Abgebrochen, die Daten bleiben erhalten."
        fi
        delete_data=true
        kinds+=" data"
        if ! final_backup; then
            warn "Das letzte Backup ist fehlgeschlagen."
            confirm "Trotzdem ohne Backup löschen?" n || die "Abgebrochen, es wurde nichts entfernt."
        fi
    fi
    if ask_remove_packages GYMSLUNITY_UNINSTALL_PACKAGES; then
        kinds+=" pkg repo"
    fi

    step "Entfernen"
    undo_journal "$kinds"
    printf '\n%sGymSLunity %s entfernt.%s\n' "$c_green" "$inst_name" "$c_off"
    if ! $delete_data; then
        info "Erhalten geblieben sind:"
        info "  ${GYMSLUNITY_DIR:-Installationsverzeichnis} (Code, .env, storage/)"
        [[ ${GYMSLUNITY_DB:-} == mariadb ]] && info "  MariaDB-Datenbank und -Benutzer '$inst_name'"
        [[ ${GYMSLUNITY_DB:-} == external ]] && info "  Tabellen in der externen Datenbank ${GYMSLUNITY_DB_DATABASE:-} auf ${GYMSLUNITY_DB_HOST:-}"
        info "  ${GYMSLUNITY_BACKUP_DIR:-Backup-Verzeichnis}"
        info "  Systembenutzer '$inst_name'"
    fi
}

# --- Main --------------------------------------------------------------------

while (($#)); do
    case "$1" in
        --uninstall) mode=uninstall ;;
        --yes | -y) assume_yes=true ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            exit 2
            ;;
    esac
    shift
done

if [[ $mode == uninstall ]]; then
    uninstall_server
else
    install_server
fi
