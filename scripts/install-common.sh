# shellcheck shell=bash
# shellcheck disable=SC2034,SC2154 # settings shared with the calling scripts
#
# Shared functions of install-server.sh and install-demo-server.sh. Both
# scripts source this file from next to themselves or, when they were
# downloaded alone, from GitHub.
#
# The calling script sets repo, state_base and installer_label before sourcing
# and fills the settings below during its configuration.
#
# Journal entries replay undo_* helpers from this file, also in later
# versions. Never rename or remove one of them.

# --- Settings of the calling script -------------------------------------------

readonly php_version=8.4
readonly php_bin=/usr/bin/php$php_version
readonly base_php_packages=(
    "php$php_version-fpm" "php$php_version-cli" "php$php_version-opcache"
    "php$php_version-sqlite3" "php$php_version-mbstring" "php$php_version-xml"
    "php$php_version-curl" "php$php_version-zip" "php$php_version-gd"
    "php$php_version-intl"
)
readonly base_php_extensions=(
    ctype curl dom fileinfo filter gd hash iconv intl mbstring openssl pcre
    pdo pdo_sqlite session tokenizer xml zip
)

inst_name=""         # user, group, PHP-FPM pool, Nginx site, unit prefix
tls_mode=""          # letsencrypt, existing or proxy
le_email=""
le_staging=0
cert_file=""
cert_key_file=""
proxies=""
open_firewall=false
domains=()           # server names, one per site
site_publics=()      # document root (public/) per site, same order as domains
extra_packages=()    # additional Debian packages, e.g. mariadb-server
extra_php_packages=()
extra_php_extensions=()
fail_after_variable=INSTALLER_TEST_FAIL_AFTER

assume_yes=false
journal=()
journal_file=""
state_dir=""
installing=false
completed=false
work_dir=""
nginx_conf=""
archive=""
release_tag=""
release_listing=""
os_id=""
os_codename=""

# --- Output and questions -----------------------------------------------------

if [[ -t 1 ]]; then
    c_blue=$'\033[1;34m' c_yellow=$'\033[1;33m' c_red=$'\033[1;31m' c_green=$'\033[1;32m' c_off=$'\033[0m'
else
    c_blue="" c_yellow="" c_red="" c_green="" c_off=""
fi

step() { printf '\n%s==> %s%s\n' "$c_blue" "$*" "$c_off"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%sWarnung:%s %s\n' "$c_yellow" "$c_off" "$*" >&2; }
die() {
    printf '%sFehler:%s %s\n' "$c_red" "$c_off" "$*" >&2
    exit 1
}

# Questions are read from the terminal, so the scripts also work when they are
# piped into bash (curl … | sudo bash).
open_terminal() {
    if $assume_yes; then
        return
    fi
    if ! { exec 3< /dev/tty; } 2> /dev/null; then
        die "Kein Terminal für Rückfragen verfügbar. Mit --yes und Umgebungsvariablen unbeaufsichtigt ausführen (siehe --help)."
    fi
}

# ask VAR "Question" [default] [validator] — a preset environment variable
# replaces the default.
ask() {
    local var=$1 question=$2 default=${3-} validator=${4-} answer
    if [[ -n "${!var-}" ]]; then
        default=${!var}
    fi
    while true; do
        if $assume_yes; then
            answer=$default
        else
            printf '%s%s: ' "$question" "${default:+ [$default]}" > /dev/tty
            IFS= read -r answer <&3 || die "Eingabe abgebrochen."
            answer=${answer:-$default}
        fi
        if [[ -z "$validator" ]] || "$validator" "$answer"; then
            printf -v "$var" '%s' "$answer"
            return
        fi
        if $assume_yes; then
            die "Ungültiger Wert für $var: '$answer'"
        fi
    done
}

# ask_secret VAR "Question" [validator] [once] — hidden input, repeated for
# confirmation unless "once" is given; a preset environment variable is taken
# as it is.
ask_secret() {
    local var=$1 question=$2 validator=${3-} once=${4-} answer repeated
    if [[ -n "${!var-}" ]] || $assume_yes; then
        answer=${!var-}
        [[ -z "$validator" ]] || "$validator" "$answer" || die "Ungültiger Wert für $var."
        return
    fi
    while true; do
        printf '%s: ' "$question" > /dev/tty
        IFS= read -rs answer <&3 || die "Eingabe abgebrochen."
        printf '\n' > /dev/tty
        if [[ -n "$validator" ]] && ! "$validator" "$answer"; then
            continue
        fi
        if [[ $once == once ]]; then
            printf -v "$var" '%s' "$answer"
            return
        fi
        printf 'Wiederholen: ' > /dev/tty
        IFS= read -rs repeated <&3 || die "Eingabe abgebrochen."
        printf '\n' > /dev/tty
        if [[ $answer == "$repeated" ]]; then
            printf -v "$var" '%s' "$answer"
            return
        fi
        warn "Die Eingaben stimmen nicht überein."
    done
}

# confirm "Question" [default y|n]
confirm() {
    local question=$1 default=${2:-n} answer hint
    if $assume_yes; then
        [[ $default == y ]]
        return
    fi
    [[ $default == y ]] && hint="J/n" || hint="j/N"
    while true; do
        printf '%s [%s]: ' "$question" "$hint" > /dev/tty
        IFS= read -r answer <&3 || die "Eingabe abgebrochen."
        case "${answer:-$default}" in
            [jJyY]*) return 0 ;;
            [nN]*) return 1 ;;
        esac
    done
}

# flag_default VAR — "y" unless the environment variable is 0.
flag_default() {
    [[ ${!1:-1} == 0 ]] && echo n || echo y
}

# --- Validators ---------------------------------------------------------------

valid_name() {
    [[ $1 =~ ^[a-z][a-z0-9-]{1,30}$ ]] || {
        warn "Nur Kleinbuchstaben, Ziffern und Bindestriche, beginnend mit einem Buchstaben."
        return 1
    }
}
valid_domain() {
    [[ $1 =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]] || {
        warn "Bitte einen Domainnamen wie verein.example.org angeben (Kleinbuchstaben)."
        return 1
    }
}
valid_optional_email() {
    [[ -z $1 || $1 =~ ^[^[:space:]@\"]+@[^[:space:]@\"]+\.[^[:space:]@\"]+$ ]] || {
        warn "Keine gültige E-Mail-Adresse."
        return 1
    }
}
valid_email() {
    [[ -n $1 ]] && valid_optional_email "$1"
}
valid_optional_url() {
    [[ -z $1 || $1 =~ ^https?://[^[:space:]\"]+$ ]] || {
        warn "Bitte eine Adresse mit http:// oder https:// angeben."
        return 1
    }
}
# A new, absolute directory at least two levels deep.
valid_new_dir() {
    [[ $1 =~ ^/[A-Za-z0-9._/-]+$ && $1 != *..* && $(tr -cd / <<< "${1%/}" | wc -c) -ge 2 ]] || {
        warn "Bitte einen absoluten Pfad mit mindestens zwei Ebenen ohne Leerzeichen angeben."
        return 1
    }
    if [[ -e $1 ]] && [[ -n "$(ls -A "$1" 2> /dev/null)" ]]; then
        warn "$1 existiert bereits und ist nicht leer."
        return 1
    fi
}
valid_root() {
    valid_new_dir "$@"
}
valid_env_text() {
    [[ -n $1 && $1 != *[\"\\\$\`]* ]] || {
        warn "Bitte ohne Anführungszeichen, Backslash, \$ und Backtick."
        return 1
    }
}
valid_time() {
    [[ $1 =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || {
        warn "Uhrzeit im Format HH:MM."
        return 1
    }
}
valid_tls() {
    [[ $1 == letsencrypt || $1 == existing || $1 == proxy ]] || {
        warn "letsencrypt, existing oder proxy."
        return 1
    }
}
valid_file() {
    [[ -r $1 ]] || {
        warn "$1 ist nicht lesbar."
        return 1
    }
}
valid_optional_file() {
    [[ -z $1 ]] || valid_file "$1"
}
valid_proxies() {
    [[ $1 =~ ^[0-9A-Fa-f.:/]+(,[0-9A-Fa-f.:/]+)*$ ]] || {
        warn "IP-Adressen oder Netze, durch Kommas getrennt, z. B. 10.0.0.2,10.0.1.0/24."
        return 1
    }
}
valid_port() {
    [[ $1 =~ ^[0-9]+$ && $1 -ge 1 && $1 -le 65535 ]] || {
        warn "Ungültiger Port."
        return 1
    }
}
valid_host() {
    [[ $1 =~ ^[A-Za-z0-9.:-]+$ ]] || {
        warn "Ungültiger Hostname."
        return 1
    }
}

# --- Journal ------------------------------------------------------------------

# record KIND COMMAND… — remembers how to undo the next change. Kinds:
#   core  configuration and services, always undone
#   data  data that may be worth keeping (database, files, backups)
#   pkg   installed packages, repo  added package sources
# A rollback undoes everything, --uninstall asks for data, pkg and repo.
# Record before changing anything, so a half-done step is undone as well.
record() {
    local kind=$1
    shift
    journal+=("$kind"$'\t'"$(printf '%q ' "$@")")
    if [[ -n $journal_file ]]; then
        printf '%s\n' "${journal[-1]}" >> "$journal_file"
    fi
}

# undo_journal "KIND …" — undoes the entries of these kinds in reverse order.
# (Older demo installs called it with true/false for "with packages".)
undo_journal() {
    local kinds=$1 i entry kind cmd
    case "$kinds" in
        true) kinds="core pkg repo" ;;
        false) kinds="core" ;;
    esac
    set +e
    for ((i = ${#journal[@]} - 1; i >= 0; i--)); do
        entry=${journal[i]}
        kind=${entry%%$'\t'*}
        cmd=${entry#*$'\t'}
        if [[ " $kinds " != *" $kind "* ]]; then
            info "bleibt: $cmd"
            continue
        fi
        info "$cmd"
        eval "$cmd" > /dev/null 2>&1 || warn "Rückgängigmachen fehlgeschlagen: $cmd"
    done
    set -e
}

# journal_has KIND — whether the journal contains entries of this kind.
journal_has() {
    local entry
    for entry in "${journal[@]}"; do
        [[ $entry == "$1"$'\t'* ]] && return 0
    done
    return 1
}

undo_remove_tree() {
    local path=$1
    # Refuse anything that is not an absolute path at least two levels deep.
    [[ $path =~ ^/[^/]+/.+ && $path != *..* ]] || return 1
    rm -rf --one-file-system -- "$path"
}

undo_remove_nginx_conf() {
    rm -f -- "$@"
    if nginx -t -q 2> /dev/null && systemctl is-active -q nginx; then
        systemctl reload nginx
    fi
}

undo_remove_fpm_pool() {
    rm -f -- "$1"
    if systemctl is-active -q "php$php_version-fpm"; then
        systemctl reload "php$php_version-fpm"
    fi
}

undo_remove_units() {
    local unit
    for unit in "$@"; do
        systemctl disable --now "$unit" 2> /dev/null || true
    done
}

undo_remove_unit_files() {
    rm -f -- "$@"
    systemctl daemon-reload
}

undo_remove_user() {
    # Only processes of the host: a container process can run with the same
    # numeric user ID and must not be hit.
    pkill --ns $$ --nslist pid,mnt -u "$1" 2> /dev/null || true
    sleep 1
    userdel "$1" 2> /dev/null || true
    if getent group "$1" > /dev/null; then
        groupdel "$1" 2> /dev/null || true
    fi
}

undo_remove_sshd_dropin() {
    rm -f -- "$1"
    if sshd -t 2> /dev/null && systemctl is-active -q ssh; then
        systemctl reload ssh
    fi
}

undo_remove_apt_source() {
    rm -f -- "$@"
    apt-get update -qq || true
}

# undo_drop_mariadb NAME — drops the database and its users of that name.
undo_drop_mariadb() {
    local name=$1
    "$(mariadb_client)" -e "DROP DATABASE IF EXISTS \`$name\`; DROP USER IF EXISTS '$name'@'localhost'; DROP USER IF EXISTS '$name'@'127.0.0.1';"
}

# undo_empty_database ENV_FILE — drops all tables of the MariaDB/MySQL
# database configured in this .env. Only for databases that were empty when
# the installation started; the database itself and its users stay.
undo_empty_database() {
    local env=$1 options tables client
    [[ -f $env ]] || return 0
    options="$(mktemp)"
    db_option_file "$options" "$(env_value "$env" DB_HOST)" "$(env_value "$env" DB_PORT)" \
        "$(env_value "$env" DB_USERNAME)" "$(env_value "$env" DB_PASSWORD)" "$(env_value "$env" MYSQL_ATTR_SSL_CA)"
    client="$(mariadb_client)"
    tables="$("$client" --defaults-extra-file="$options" -N -B -e \
        "SELECT GROUP_CONCAT(CONCAT('\`', TABLE_NAME, '\`')) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" \
        "$(env_value "$env" DB_DATABASE)")" || {
        rm -f -- "$options"
        return 1
    }
    if [[ -n $tables && $tables != NULL ]]; then
        "$client" --defaults-extra-file="$options" -e "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS $tables;" "$(env_value "$env" DB_DATABASE)"
    fi
    rm -f -- "$options"
}

on_exit() {
    local status=$?
    if [[ -n $work_dir ]]; then
        rm -rf -- "$work_dir"
    fi
    if $installing && ! $completed; then
        trap - INT TERM
        printf '\n%sDie Installation ist fehlgeschlagen (Status %s). Änderungen werden rückgängig gemacht:%s\n' \
            "$c_red" "$status" "$c_off"
        undo_journal "core data pkg repo"
        printf '%sRollback abgeschlossen. Das System ist im Zustand vor der Installation.%s\n' "$c_yellow" "$c_off"
        warn "Aktualisiert bleiben die Paketlisten und bereits vorhandene Pakete, die eine Abhängigkeit auf eine neuere Version gehoben hat (z. B. php-common)."
    fi
    exit "$status"
}

# begin_install — from here on every failure rolls back.
begin_install() {
    trap on_exit EXIT
    trap 'exit 130' INT TERM
    work_dir="$(mktemp -d)"
    installing=true
    install -d -m 0700 "$state_base"
    record core undo_remove_tree "$state_dir"
    install -d -m 0700 "$state_dir"
    journal_file="$state_dir/journal"
    printf '%s\n' "${journal[@]}" > "$journal_file"
    chmod 0600 "$journal_file"
}

# run_tasks TASK… — runs the installation steps; the variable named in
# fail_after_variable lets the CI test the rollback after any of them.
run_tasks() {
    local task fail_after=${!fail_after_variable:-}
    for task in "$@"; do
        "$task"
        [[ $fail_after != "$task" ]] || die "Testabbruch nach $task ($fail_after_variable)."
    done
    completed=true
}

# --- Helpers ------------------------------------------------------------------

package_installed() {
    [[ "$(dpkg-query -W -f='${db:Status-Status}' "$1" 2> /dev/null)" == installed ]]
}

# installed_packages — names of all installed packages, sorted.
installed_packages() {
    dpkg-query -W -f='${db:Status-Status} ${binary:Package}\n' 2> /dev/null | awk '$1 == "installed" { print $2 }' | sort
}

# apt_install PACKAGE… — installs without recommendations and records the
# removal of everything that came with it, dependencies included, so a
# rollback leaves exactly the packages that were there before.
apt_install() {
    local before new
    before="$(installed_packages)"
    record pkg apt-get remove -y "$@"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "$@"
    new="$(comm -13 <(printf '%s\n' "$before") <(installed_packages))"
    if [[ -n $new ]]; then
        # shellcheck disable=SC2086 # one package name per word
        record pkg apt-get remove -y $new
    fi
}

package_available() {
    local candidate
    candidate="$(apt-cache policy "$1" 2> /dev/null | awk '/Candidate:/ { print $2 }')"
    [[ -n $candidate && $candidate != "(none)" ]]
}

version_at_least() {
    [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]
}

nginx_reload() {
    nginx -t -q || die "Die Nginx-Konfiguration ist ungültig (nginx -t)."
    if systemctl is-active -q nginx; then
        systemctl reload nginx
    else
        systemctl start nginx
    fi
}

# write_file PATH MODE OWNER — writes stdin to a new file and records its removal.
write_file() {
    local path=$1 file_mode=$2 owner=$3
    [[ ! -e $path ]] || die "$path existiert bereits."
    record core rm -f -- "$path"
    install -m "$file_mode" -o "${owner%:*}" -g "${owner#*:}" /dev/null "$path"
    cat > "$path"
}

random_base64() {
    openssl rand -base64 32
}

# env_value FILE KEY — the value of KEY in a .env file, without quotes.
env_value() {
    local line
    line="$(grep -m1 "^$2=" "$1" 2> /dev/null)" || return 0
    line=${line#*=}
    if [[ $line == \'*\' || $line == \"*\" ]]; then
        line=${line:1:${#line}-2}
    fi
    printf '%s' "$line"
}

# option_value VALUE — quoted for a MariaDB/MySQL option file.
option_value() {
    local value=${1//\\/\\\\}
    value=${value//\"/\\\"}
    printf '"%s"' "$value"
}

# db_option_file FILE HOST PORT USER PASSWORD [CA] — client options, so the
# password never appears in the process list.
db_option_file() {
    local file=$1
    install -m 0600 /dev/null "$file"
    {
        echo "[client]"
        echo "host=$(option_value "$2")"
        echo "port=$(option_value "$3")"
        echo "user=$(option_value "$4")"
        echo "password=$(option_value "$5")"
        echo "protocol=TCP"
        if [[ -n ${6-} ]]; then
            echo "ssl-ca=$(option_value "$6")"
            echo "ssl-verify-server-cert"
        fi
    } > "$file"
}

# mariadb_client — the MariaDB or MySQL command line client.
mariadb_client() {
    command -v mariadb || command -v mysql || echo mariadb
}

detect_ssh_port() {
    local port
    port="$(sshd -T 2> /dev/null | awk '$1 == "port" { print $2; exit }')" || true
    printf '%s' "${port:-22}"
}

# --- Preflight ----------------------------------------------------------------

preflight() {
    [[ $EUID -eq 0 ]] || die "Bitte mit sudo oder als root ausführen."
    [[ -r /etc/os-release ]] || die "/etc/os-release fehlt, das System wird nicht unterstützt."
    # shellcheck source=/dev/null
    . /etc/os-release
    os_id=${ID:-}
    os_codename=${VERSION_CODENAME:-}
    case "$os_id" in
        debian | ubuntu) ;;
        *)
            if [[ " ${ID_LIKE:-} " == *" debian "* || " ${ID_LIKE:-} " == *" ubuntu "* ]]; then
                warn "$PRETTY_NAME ist nicht getestet, nur Debian und Ubuntu werden unterstützt."
                confirm "Trotzdem fortfahren?" n || exit 1
                [[ " ${ID_LIKE:-} " == *" ubuntu "* ]] && os_id=ubuntu || os_id=debian
                os_codename=${UBUNTU_CODENAME:-$os_codename}
            else
                die "Nur Debian und Ubuntu werden unterstützt (gefunden: ${PRETTY_NAME:-unbekannt})."
            fi
            ;;
    esac
    [[ -n $os_codename ]] || die "Der Codename der Distribution ist unbekannt."
    [[ -d /run/systemd/system ]] || die "systemd läuft nicht. Das Skript braucht systemd für Worker und Scheduler."
    command -v apt-get > /dev/null || die "apt-get fehlt."
}

# --- Shared questions ---------------------------------------------------------

# ask_tls PREFIX — asks <PREFIX>_TLS and the values of the chosen variant.
ask_tls() {
    local p=$1 v
    echo
    info "HTTPS:"
    info "  letsencrypt  Zertifikat automatisch von Let's Encrypt (DNS muss auf diesen Server zeigen)"
    info "  existing     vorhandenes Zertifikat (z. B. Wildcard) verwenden"
    info "  proxy        ein vorgeschalteter Reverse Proxy übernimmt HTTPS, Nginx lauscht nur auf Port 80"
    ask "${p}_TLS" "HTTPS-Variante" letsencrypt valid_tls
    v="${p}_TLS"
    tls_mode=${!v}
    case "$tls_mode" in
        letsencrypt)
            ask "${p}_LE_EMAIL" "E-Mail für Let's Encrypt (Ablaufwarnungen, optional)" "" valid_optional_email
            v="${p}_LE_EMAIL"
            le_email=${!v}
            v="${p}_LE_STAGING"
            le_staging=${!v:-0}
            ;;
        existing)
            ask "${p}_CERT" "Zertifikatskette (fullchain.pem)" "" valid_file
            ask "${p}_CERT_KEY" "Privater Schlüssel (privkey.pem)" "" valid_file
            v="${p}_CERT"
            cert_file=${!v}
            v="${p}_CERT_KEY"
            cert_key_file=${!v}
            ;;
        proxy)
            ask "${p}_PROXIES" "IP-Adressen des Reverse Proxys (Komma-getrennt)" "" valid_proxies
            v="${p}_PROXIES"
            proxies=${!v}
            ;;
    esac
}

# ask_firewall PREFIX — offers to open the web ports when ufw is active.
ask_firewall() {
    open_firewall=false
    if command -v ufw > /dev/null && ufw status 2> /dev/null | grep -q '^Status: active'; then
        if confirm "Die Firewall ufw ist aktiv. Ports 80 und 443 freigeben?" "$(flag_default "${1}_OPEN_FIREWALL")"; then
            open_firewall=true
        fi
    fi
}

# --- Conflict check -----------------------------------------------------------

# check_common_conflicts PATH… — adds to the array "problems" everything that
# would collide with the existing system: user, the given paths and those of
# the shared steps, Nginx sites with the same domains and occupied ports.
check_common_conflicts() {
    local path
    if id -u "$inst_name" > /dev/null 2>&1 || getent group "$inst_name" > /dev/null; then
        problems+=("Benutzer oder Gruppe '$inst_name' existiert bereits.")
    fi
    for path in "$@" \
        "/etc/php/$php_version/fpm/pool.d/$inst_name.conf" \
        "/run/php/$inst_name.sock" \
        "/etc/nginx/sites-available/$inst_name" \
        "/etc/nginx/sites-enabled/$inst_name" \
        "/etc/nginx/conf.d/$inst_name.conf" \
        "/etc/letsencrypt/renewal/$inst_name.conf" \
        "/var/www/$inst_name-acme"; do
        [[ ! -e $path ]] || problems+=("$path existiert bereits.")
    done

    if command -v nginx > /dev/null; then
        if ! package_installed nginx && ! package_installed nginx-core && ! package_installed nginx-light && ! package_installed nginx-full && ! package_installed nginx-extras; then
            problems+=("Nginx ist vorhanden, aber nicht als Debian-Paket installiert. Das wird nicht unterstützt.")
        elif ! nginx -t -q 2> /dev/null; then
            problems+=("Die bestehende Nginx-Konfiguration ist fehlerhaft (nginx -t). Bitte zuerst reparieren.")
        else
            local domain dump
            dump="$(nginx -T 2> /dev/null)"
            for domain in "${domains[@]}"; do
                if grep -Eq "^[[:space:]]*server_name[^;]*[[:space:]]${domain//./\\.}([[:space:];]|$)" <<< "$dump"; then
                    problems+=("Nginx liefert $domain bereits aus.")
                fi
            done
        fi
    fi

    if command -v ss > /dev/null; then
        local port ports=(80) listeners
        [[ $tls_mode != proxy ]] && ports+=(443)
        for port in "${ports[@]}"; do
            listeners="$(ss -Hltnp "sport = :$port" 2> /dev/null || true)"
            if [[ -n $listeners ]] && ! grep -q '"nginx"' <<< "$listeners"; then
                problems+=("Port $port ist von einem anderen Programm belegt: $(grep -o 'users:(("[^"]*"' <<< "$listeners" | head -n1 | cut -d'"' -f2)")
            fi
        done
    fi

    if package_installed "php$php_version-fpm" && ! systemctl is-active -q "php$php_version-fpm"; then
        warn "php$php_version-fpm ist installiert, läuft aber nicht. Das Skript startet den Dienst."
    fi
}

report_conflicts() {
    if ((${#problems[@]})); then
        printf '  - %s\n' "${problems[@]}" >&2
        die "Die Installation würde bestehende Einstellungen überschreiben und wird nicht gestartet."
    fi
    info "Keine Konflikte gefunden."
}

# --- Packages -----------------------------------------------------------------

add_php_repository() {
    local list="/etc/apt/sources.list.d/$inst_name-php.list" key url
    step "PHP $php_version ist in den Paketquellen nicht verfügbar"
    if [[ $os_id == ubuntu ]]; then
        info "Es wird aus dem PPA ondrej/php installiert (neben vorhandenen PHP-Versionen)."
        key="/etc/apt/keyrings/$inst_name-php.asc"
        url="https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C"
    else
        info "Es wird aus packages.sury.org installiert (neben vorhandenen PHP-Versionen)."
        key="/etc/apt/keyrings/$inst_name-php.gpg"
        url="https://packages.sury.org/php/apt.gpg"
    fi
    warn "Eine zusätzliche Paketquelle kann bei späteren 'apt upgrade' auch andere PHP-Pakete aktualisieren."
    confirm "Paketquelle hinzufügen?" y || die "PHP $php_version ist nicht verfügbar."

    install -d -m 0755 /etc/apt/keyrings
    record repo undo_remove_apt_source "$list" "$key"
    curl -fsSL "$url" -o "$key"
    chmod 0644 "$key"
    if [[ $os_id == ubuntu ]]; then
        echo "deb [signed-by=$key] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $os_codename main" > "$list"
    else
        echo "deb [signed-by=$key] https://packages.sury.org/php/ $os_codename main" > "$list"
    fi
    apt-get update -qq
    package_available "php$php_version-fpm" || die "php$php_version-fpm ist auch in der neuen Paketquelle nicht verfügbar."
}

install_packages() {
    step "Prüfe benötigte Pakete"
    local wanted=(ca-certificates curl tar gzip openssl openssh-client nginx "${extra_packages[@]}"
        "${base_php_packages[@]}" "${extra_php_packages[@]}") missing=() pkg
    if [[ $tls_mode == letsencrypt ]] && ! command -v certbot > /dev/null; then
        wanted+=(certbot)
    fi
    for pkg in "${wanted[@]}"; do
        if [[ $pkg == nginx ]] && command -v nginx > /dev/null; then
            info "vorhanden: nginx"
        elif package_installed "$pkg"; then
            info "vorhanden: $pkg"
        else
            missing+=("$pkg")
        fi
    done
    if [[ $tls_mode == letsencrypt ]] && command -v certbot > /dev/null; then
        info "vorhanden: certbot ($(command -v certbot))"
    fi

    if ((${#missing[@]} == 0)); then
        info "Alle Pakete sind bereits installiert."
    else
        info "Fehlend: ${missing[*]}"
        confirm "Fehlende Pakete installieren? Bestehende Pakete werden nicht aktualisiert." y || die "Abgebrochen."
        apt-get update -qq
        # Tools such as curl first, they are needed to add the PHP repository.
        local base=() php=()
        for pkg in "${missing[@]}"; do
            [[ $pkg == php* ]] && php+=("$pkg") || base+=("$pkg")
        done
        if ((${#base[@]})); then
            apt_install "${base[@]}"
        fi
        if ((${#php[@]})); then
            package_available "php$php_version-fpm" || add_php_repository
            # Installing a newer PHP switches the "php" command to it via
            # update-alternatives. Keep whatever was the default before.
            local alternative previous=()
            for alternative in php phar phar.phar; do
                previous+=("$(readlink "/etc/alternatives/$alternative" 2> /dev/null || true)")
            done
            apt_install "${php[@]}"
            local i=0
            for alternative in php phar phar.phar; do
                if [[ -n ${previous[i]} && -e ${previous[i]} && "$(readlink "/etc/alternatives/$alternative")" != "${previous[i]}" ]]; then
                    update-alternatives --quiet --set "$alternative" "${previous[i]}"
                    info "Der Befehl $alternative bleibt bei ${previous[i]}."
                fi
                i=$((i + 1))
            done
        fi
    fi

    "$php_bin" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' ||
        die "GymSLunity braucht PHP 8.4.1 oder neuer, installiert ist $("$php_bin" -r 'echo PHP_VERSION;')."
    local modules ext missing_ext=()
    modules="$("$php_bin" -m | tr '[:upper:]' '[:lower:]')"
    for ext in "${base_php_extensions[@]}" "${extra_php_extensions[@]}"; do
        grep -qx "$ext" <<< "$modules" || missing_ext+=("$ext")
    done
    ((${#missing_ext[@]} == 0)) || die "Fehlende PHP-Erweiterungen: ${missing_ext[*]}"
    info "PHP $("$php_bin" -r 'echo PHP_VERSION;') mit allen Erweiterungen."

    systemctl is-active -q "php$php_version-fpm" || systemctl start "php$php_version-fpm"
    systemctl is-active -q nginx || systemctl start nginx
}

open_firewall_ports() {
    $open_firewall || return 0
    step "Firewall"
    local port output ports=(80/tcp)
    [[ $tls_mode != proxy ]] && ports+=(443/tcp)
    for port in "${ports[@]}"; do
        output="$(ufw allow "$port" 2>&1)"
        if [[ $output == *Skipping* ]]; then
            info "$port war bereits freigegeben."
        else
            record core ufw delete allow "$port"
            info "$port freigegeben."
        fi
    done
}

# --- Release ------------------------------------------------------------------

# download_release [TAG] [LOCAL_ARCHIVE] — downloads and verifies the given or
# newest release (including pre-releases), or takes a local archive built with
# scripts/build-release.sh; sets archive, release_tag and release_listing.
download_release() {
    local tag=${1-} local_archive=${2-} api
    if [[ -n $local_archive ]]; then
        step "Lokales Release-Archiv"
        [[ -r $local_archive ]] || die "$local_archive ist nicht lesbar."
        if [[ -r $local_archive.sha256 ]]; then
            (cd "$(dirname "$local_archive")" && sha256sum -c --quiet "$(basename "$local_archive").sha256") ||
                die "Die Prüfsumme von $local_archive stimmt nicht."
        fi
        archive="$work_dir/$(basename "$local_archive")"
        cp "$local_archive" "$archive"
        release_listing="$(tar -tzf "$archive")"
        # Only the top-level VERSION, vendor packages have their own.
        release_tag="v$(tar -xzOf "$archive" "$(head -n1 <<< "$release_listing" | cut -d/ -f1)/VERSION" | tr -d '[:space:]')"
        info "$local_archive ($release_tag)"
        return
    fi
    step "Release herunterladen"
    if [[ -z $tag ]]; then
        api="$(curl -fsSL -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$repo/releases?per_page=10")" ||
            die "Die Releases von $repo konnten nicht abgerufen werden."
        # shellcheck disable=SC2016 # PHP code, not shell
        tag="$("$php_bin" -r '
            foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $release) {
                if (! $release["draft"]) { echo $release["tag_name"]; break; }
            }' <<< "$api")"
        [[ -n $tag ]] || die "In $repo wurde kein Release gefunden."
    fi
    [[ $tag =~ ^v[0-9A-Za-z.-]+$ ]] || die "Ungültiger Release-Tag: $tag"

    local name="gymslunity-$tag.tar.gz" base_url="https://github.com/$repo/releases/download/$tag"
    curl -fsSL "$base_url/$name" -o "$work_dir/$name"
    curl -fsSL "$base_url/$name.sha256" -o "$work_dir/$name.sha256"
    (cd "$work_dir" && sha256sum -c --quiet "$name.sha256") || die "Die Prüfsumme von $name stimmt nicht."
    archive="$work_dir/$name"
    release_listing="$(tar -tzf "$archive")"
    release_tag=$tag
    info "$tag, Prüfsumme in Ordnung."
}

# --- PHP-FPM, Nginx and certificate -------------------------------------------

configure_php_fpm() {
    step "PHP-FPM-Pool"
    local pool="/etc/php/$php_version/fpm/pool.d/$inst_name.conf"
    record core undo_remove_fpm_pool "$pool"
    install -m 0644 /dev/null "$pool"
    cat > "$pool" << EOF
; Erzeugt von $installer_label
[$inst_name]
user = $inst_name
group = $inst_name
listen = /run/php/$inst_name.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 120s
security.limit_extensions = .php

php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 12M
EOF
    "php-fpm$php_version" -t 2> /dev/null || die "Die PHP-FPM-Konfiguration ist ungültig (php-fpm$php_version -t)."
    systemctl reload "php$php_version-fpm"
    info "$pool"
}

nginx_conf_path() {
    if nginx -T 2> /dev/null | grep -q 'include /etc/nginx/sites-enabled/'; then
        printf '/etc/nginx/sites-available/%s' "$inst_name"
    else
        printf '/etc/nginx/conf.d/%s.conf' "$inst_name"
    fi
}

write_nginx_conf() {
    local phase=$1 conf=$2 listen_v6=false http2_directive=true acme="/var/www/$inst_name-acme" i
    [[ -f /proc/net/if_inet6 ]] && listen_v6=true
    version_at_least "$(nginx -v 2>&1 | sed 's#.*/##; s# .*##')" 1.25.1 || http2_directive=false

    listen() {
        local port=$1 ssl=${2-}
        local http2=""
        [[ -n $ssl ]] && ! $http2_directive && http2=" http2"
        echo "    listen $port$ssl$http2;"
        $listen_v6 && echo "    listen [::]:$port$ssl$http2;"
        [[ -n $ssl ]] && $http2_directive && echo "    http2 on;"
        return 0
    }

    app_server() {
        local domain=$1 public=$2
        cat << EOF

server {
$(if [[ $tls_mode == proxy ]]; then listen 80; else listen 443 " ssl"; fi)
    server_name $domain;
    server_tokens off;

    root $public;
    index index.php;
    charset utf-8;
EOF
        case "$tls_mode" in
            letsencrypt)
                echo "    ssl_certificate /etc/letsencrypt/live/$inst_name/fullchain.pem;"
                echo "    ssl_certificate_key /etc/letsencrypt/live/$inst_name/privkey.pem;"
                ;;
            existing)
                echo "    ssl_certificate $cert_file;"
                echo "    ssl_certificate_key $cert_key_file;"
                ;;
            proxy)
                # The proxy terminates HTTPS: take the client address from it
                # (rate limits, logs) and tell PHP that the request was secure.
                local proxy proxy_list
                IFS=, read -ra proxy_list <<< "$proxies"
                for proxy in "${proxy_list[@]}"; do
                    echo "    set_real_ip_from $proxy;"
                done
                echo "    real_ip_header X-Forwarded-For;"
                echo "    real_ip_recursive on;"
                ;;
        esac
        cat << EOF

    # HSTS setzt GymSLunity selbst (SECURITY_HSTS_MAX_AGE).
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    client_max_body_size 12m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ ^/index\.php(/|\$) {
        limit_req zone=$inst_name burst=30 nodelay;
        limit_req_status 429;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
$([[ $tls_mode == proxy ]] && echo "        fastcgi_param HTTPS on;")
        fastcgi_pass unix:/run/php/$inst_name.sock;
        fastcgi_read_timeout 120s;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \.php\$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ^~ /build/assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
        access_log off;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|webp|ico|woff|woff2)\$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files \$uri =404;
        access_log off;
    }
}
EOF
    }

    {
        echo "# Erzeugt von $installer_label für '$inst_name'."
        echo "limit_req_zone \$binary_remote_addr zone=$inst_name:10m rate=10r/s;"
        if [[ $tls_mode != proxy ]]; then
            cat << EOF

server {
$(listen 80)
    server_name ${domains[*]};
    server_tokens off;

    location ^~ /.well-known/acme-challenge/ {
        root $acme;
        default_type text/plain;
    }

    location / {
        return $([[ $phase == http ]] && echo 503 || echo "301 https://\$host\$request_uri");
    }
}
EOF
        fi
        if [[ $phase == full ]]; then
            for i in "${!domains[@]}"; do
                app_server "${domains[i]}" "${site_publics[i]}"
            done
        fi
    } > "$conf"
}

configure_nginx() {
    step "Nginx"
    nginx_conf="$(nginx_conf_path)"
    local enabled=""
    [[ $nginx_conf == /etc/nginx/sites-available/* ]] && enabled="/etc/nginx/sites-enabled/$inst_name"
    record core undo_remove_nginx_conf "$nginx_conf" ${enabled:+"$enabled"}
    install -m 0644 /dev/null "$nginx_conf"
    [[ -z $enabled ]] || ln -s "$nginx_conf" "$enabled"

    if [[ $tls_mode == letsencrypt ]]; then
        record core undo_remove_tree "/var/www/$inst_name-acme"
        install -d -m 0755 "/var/www/$inst_name-acme/.well-known/acme-challenge"
        write_nginx_conf http "$nginx_conf"
        nginx_reload
        request_certificate
    fi
    write_nginx_conf full "$nginx_conf"
    nginx_reload
    info "$nginx_conf"
}

request_certificate() {
    step "Zertifikat von Let's Encrypt"
    local domain token challenge="/var/www/$inst_name-acme/.well-known/acme-challenge" unreachable=()
    # Let's Encrypt only answers if every domain reaches this server on port 80.
    token="gymslunity-check-$(openssl rand -hex 8)"
    echo "$token" > "$challenge/$token"
    for domain in "${domains[@]}"; do
        if [[ "$(curl -fsS --max-time 10 "http://$domain/.well-known/acme-challenge/$token" 2> /dev/null)" != "$token" ]]; then
            unreachable+=("$domain")
        fi
    done
    rm -f -- "$challenge/$token"
    if ((${#unreachable[@]})); then
        warn "Nicht über http:// auf diesem Server erreichbar: ${unreachable[*]}"
        warn "Stimmen die DNS-Einträge (A/AAAA) und ist Port 80 offen? Hinter NAT kann die Prüfung auch fälschlich scheitern."
        confirm "Trotzdem ein Zertifikat anfordern?" n || die "DNS oder Firewall prüfen und die Installation erneut starten."
    fi

    local args=(certonly --webroot -w "/var/www/$inst_name-acme" --cert-name "$inst_name"
        --non-interactive --agree-tos --deploy-hook "systemctl reload nginx")
    for domain in "${domains[@]}"; do
        args+=(-d "$domain")
    done
    if [[ -n $le_email ]]; then
        args+=(--email "$le_email")
    else
        args+=(--register-unsafely-without-email)
    fi
    [[ $le_staging == 1 ]] && args+=(--test-cert)
    record core certbot delete --non-interactive --cert-name "$inst_name"
    certbot "${args[@]}"
    info "Zertifikat für ${domains[*]} erhalten; certbot verlängert es automatisch."
}

# --- systemd ------------------------------------------------------------------

# unit_hardening WRITABLE_PATH… — [Service] lines that confine a unit of the
# instance user to the given writable paths.
unit_hardening() {
    local path protect_home="ProtectHome=yes"
    for path in "$@"; do
        [[ $path == /home/* || $path == /root/* || $path == /run/user/* ]] && protect_home=""
    done
    cat << EOF
User=$inst_name
Group=$inst_name
UMask=0027
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ReadWritePaths=$*
$protect_home
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
EOF
}

# --- Checks and uninstall -----------------------------------------------------

# http_status DOMAIN PATH — status code of the local site, without DNS.
http_status() {
    local domain=$1 path=$2 resolve
    if [[ $tls_mode == proxy ]]; then
        resolve=(--resolve "$domain:80:127.0.0.1" "http://$domain$path")
    else
        resolve=(--resolve "$domain:443:127.0.0.1" -k "https://$domain$path")
    fi
    curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${resolve[@]}" 2> /dev/null || true
}

# select_install PREFIX WHAT — picks an installation recorded under state_base
# (asking if there are several) and loads its journal.
select_install() {
    local var="${1}_NAME" what=$2 names=() dir
    for dir in "$state_base"/*/; do
        [[ -f $dir/journal ]] && names+=("$(basename "$dir")")
    done
    ((${#names[@]})) || die "Keine mit diesem Skript eingerichtete $what gefunden ($state_base)."

    if [[ -z ${!var:-} && ${#names[@]} -eq 1 ]]; then
        printf -v "$var" '%s' "${names[0]}"
    else
        info "Gefunden: ${names[*]}"
        ask "$var" "Welche $what entfernen?" "${names[0]}" valid_name
    fi
    inst_name=${!var}
    state_dir="$state_base/$inst_name"
    [[ -f $state_dir/journal ]] || die "Keine Installation '$inst_name' gefunden."
    mapfile -t journal < "$state_dir/journal"
}

# ask_remove_packages ENV_VAR — whether the packages and package sources of
# the installation go as well. Default no; ENV_VAR=1 answers yes.
ask_remove_packages() {
    local entry extras=() default=n
    for entry in "${journal[@]}"; do
        [[ $entry == pkg$'\t'* || $entry == repo$'\t'* ]] && extras+=("${entry#*$'\t'}")
    done
    ((${#extras[@]})) || return 1
    info "Bei der Installation wurden außerdem hinzugefügt:"
    printf '      %s\n' "${extras[@]}"
    info "Andere Anwendungen könnten sie inzwischen nutzen."
    [[ ${!1:-0} == 1 ]] && default=y
    confirm "Auch diese Pakete bzw. Paketquellen entfernen?" "$default"
}
