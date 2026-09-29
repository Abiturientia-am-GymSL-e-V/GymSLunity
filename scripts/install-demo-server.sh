#!/usr/bin/env bash
#
# Sets up a Debian or Ubuntu server for the public GymSLunity demo with the
# two instances "release" (newest release) and "next" (current main), see
# docs/demo.md:
#
#   curl -fsSLO https://raw.githubusercontent.com/Abiturientia-am-GymSL-e-V/GymSLunity/main/scripts/install-demo-server.sh
#   sudo bash install-demo-server.sh               # install
#   sudo bash install-demo-server.sh --uninstall   # remove again
#
# Existing software is left alone: PHP 8.4 is installed next to other PHP
# versions without changing the default, Nginx and PHP-FPM only get their own
# site and pool and are reloaded, never restarted. Every change is recorded in
# a journal. On an error or Ctrl+C the installer undoes the changes of the
# current run in reverse order; --uninstall replays the same journal later.
#
# All questions can be answered in advance with environment variables (see
# --help), which together with --yes allows unattended installs.

set -Eeuo pipefail
umask 022

readonly repo="${GYMSLUNITY_REPO:-Abiturientia-am-GymSL-e-V/GymSLunity}"
readonly php_version=8.4
readonly php_bin=/usr/bin/php$php_version
readonly state_base=/var/lib/gymslunity-demo-installer
readonly instances=(release next)
readonly php_packages=(
    "php$php_version-fpm" "php$php_version-cli" "php$php_version-opcache"
    "php$php_version-sqlite3" "php$php_version-mbstring" "php$php_version-xml"
    "php$php_version-curl" "php$php_version-zip" "php$php_version-gd"
    "php$php_version-intl"
)
readonly php_extensions=(
    ctype curl dom fileinfo filter gd hash iconv intl mbstring openssl pcre
    pdo pdo_sqlite session tokenizer xml zip
)

script_dir=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi

mode=install
assume_yes=false
journal=()
journal_file=""
state_dir=""
installing=false
completed=false
work_dir=""
deploy_key=""
key_owner=root

# --- Output and questions ----------------------------------------------------

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

# Questions are read from the terminal, so the script also works when it is
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

# --- Validators --------------------------------------------------------------

valid_name() {
    [[ $1 =~ ^[a-z][a-z0-9-]{1,30}$ ]] || {
        warn "Nur Kleinbuchstaben, Ziffern und Bindestriche, beginnend mit einem Buchstaben."
        return 1
    }
}
valid_domain() {
    [[ $1 =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]] || {
        warn "Bitte einen Domainnamen wie demo.example.org angeben (Kleinbuchstaben)."
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
valid_root() {
    [[ $1 =~ ^/[A-Za-z0-9._/-]+$ && $1 != *..* && $(tr -cd / <<< "${1%/}" | wc -c) -ge 2 ]] || {
        warn "Bitte einen absoluten Pfad mit mindestens zwei Ebenen ohne Leerzeichen angeben, z. B. /srv/gymslunity-demo."
        return 1
    }
    if [[ -e $1 ]] && [[ -n "$(ls -A "$1" 2> /dev/null)" ]]; then
        warn "$1 existiert bereits und ist nicht leer."
        return 1
    fi
}
valid_password() {
    [[ ${#1} -ge 8 && $1 != *[\"\\\$\`]* ]] || {
        warn "Mindestens 8 Zeichen, ohne Anführungszeichen, Backslash, \$ und Backtick."
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

# --- Journal -----------------------------------------------------------------

# record KIND COMMAND… — remembers how to undo the next change. KIND is "core"
# (always undone), "pkg" or "repo" (only undone on rollback or when confirmed
# during --uninstall). Record before changing anything, so a half-done step is
# undone as well. Undo commands may only use the undo_* helpers below and
# standard tools, because a later version of this script replays them.
record() {
    local kind=$1
    shift
    journal+=("$kind"$'\t'"$(printf '%q ' "$@")")
    if [[ -n $journal_file ]]; then
        printf '%s\n' "${journal[-1]}" >> "$journal_file"
    fi
}

undo_journal() {
    local with_packages=$1 i entry kind cmd
    set +e
    for ((i = ${#journal[@]} - 1; i >= 0; i--)); do
        entry=${journal[i]}
        kind=${entry%%$'\t'*}
        cmd=${entry#*$'\t'}
        if [[ $kind != core && $with_packages != true ]]; then
            info "übersprungen: $cmd"
            continue
        fi
        info "$cmd"
        eval "$cmd" > /dev/null 2>&1 || warn "Rückgängigmachen fehlgeschlagen: $cmd"
    done
    set -e
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
    pkill -u "$1" 2> /dev/null || true
    sleep 1
    userdel "$1" 2> /dev/null || true
    if getent group "$1" > /dev/null; then
        groupdel "$1" 2> /dev/null || true
    fi
}

undo_remove_apt_source() {
    rm -f -- "$@"
    apt-get update -qq || true
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
        undo_journal true
        printf '%sRollback abgeschlossen. Das System ist im Zustand vor der Installation.%s\n' "$c_yellow" "$c_off"
        warn "Aktualisierte Paketlisten und als Abhängigkeit installierte Pakete bleiben; 'apt autoremove' räumt Letztere auf."
    fi
    exit "$status"
}

# --- Helpers -----------------------------------------------------------------

package_installed() {
    [[ "$(dpkg-query -W -f='${db:Status-Status}' "$1" 2> /dev/null)" == installed ]]
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

detect_ssh_port() {
    local port
    port="$(sshd -T 2> /dev/null | awk '$1 == "port" { print $2; exit }')" || true
    printf '%s' "${port:-22}"
}

# --- Usage -------------------------------------------------------------------

usage() {
    cat << EOF
Richtet einen Debian- oder Ubuntu-Server für die öffentliche GymSLunity-Demo ein
(Instanzen "release" und "next", siehe docs/demo.md).

Aufruf:
  sudo bash install-demo-server.sh [--yes]
  sudo bash install-demo-server.sh --uninstall [--yes]

Optionen:
  --yes         Keine Rückfragen, Vorgaben und Umgebungsvariablen verwenden
  --uninstall   Eine mit diesem Skript eingerichtete Demo wieder entfernen
  -h, --help    Diese Hilfe

Umgebungsvariablen (Antworten auf die Rückfragen):
  DEMO_NAME              Kurzname für Benutzer, Dienste und Dateien (gymslunity-demo)
  DEMO_RELEASE_DOMAIN    Domain der Instanz "release" (Pflicht bei --yes)
  DEMO_NEXT_DOMAIN       Domain der Instanz "next" (next.<release-domain>)
  DEMO_ROOT              Installationsverzeichnis (/srv/<name>)
  DEMO_TLS               letsencrypt, existing oder proxy (letsencrypt)
  DEMO_LE_EMAIL          E-Mail-Adresse für Let's Encrypt (optional)
  DEMO_LE_STAGING        1 = Test-Zertifikate von Let's Encrypt
  DEMO_CERT, DEMO_CERT_KEY   Zertifikat und Schlüssel bei DEMO_TLS=existing
  DEMO_PROXIES           Adressen des Reverse Proxys bei DEMO_TLS=proxy
  DEMO_IMPRINT_URL, DEMO_PRIVACY_URL   Impressum und Datenschutz des Betreibers
  DEMO_PASSWORD          Passwort der Demo-Konten (Demo-Passwort-2026)
  DEMO_RESET_AT          Uhrzeit der nächtlichen Zurücksetzung (00:00)
  DEMO_MAIL_FROM         Absenderadresse (noreply@<release-domain>)
  DEMO_GITHUB_DEPLOY     1/0: Deploy-Schlüssel für GitHub Actions einrichten (1)
  DEMO_SSH_HOST, DEMO_SSH_PORT   SSH-Adresse des Servers für GitHub Actions
  DEMO_OPEN_FIREWALL     1/0: Ports 80/443 in ufw freigeben, falls aktiv (1)
  DEMO_RELEASE_TAG       Release für das erste Deployment (neuestes)
  GYMSLUNITY_REPO        GitHub-Repository ($repo)
EOF
}

# --- Preflight ---------------------------------------------------------------

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
    [[ -n $os_codename ]] || die "Die Codename der Distribution ist unbekannt."
    [[ -d /run/systemd/system ]] || die "systemd läuft nicht. Das Skript braucht systemd für Worker und Scheduler."
    command -v apt-get > /dev/null || die "apt-get fehlt."
}

# --- Configuration -----------------------------------------------------------

configure() {
    step "Einstellungen"
    info "Enter übernimmt den Wert in eckigen Klammern."
    echo

    ask DEMO_NAME "Kurzname für Benutzer, Dienste und Dateien" gymslunity-demo valid_name
    state_dir="$state_base/$DEMO_NAME"
    [[ ! -e $state_dir ]] || die "Für '$DEMO_NAME' gibt es bereits eine Installation. Zuerst mit --uninstall entfernen."

    ask DEMO_RELEASE_DOMAIN "Domain der Instanz 'release' (neuestes Release)" "" valid_domain
    ask DEMO_NEXT_DOMAIN "Domain der Instanz 'next' (aktueller Stand von main)" "next.$DEMO_RELEASE_DOMAIN" valid_domain
    [[ $DEMO_RELEASE_DOMAIN != "$DEMO_NEXT_DOMAIN" ]] || die "Beide Instanzen brauchen eigene Domains."
    ask DEMO_ROOT "Installationsverzeichnis" "/srv/$DEMO_NAME" valid_root
    DEMO_ROOT=${DEMO_ROOT%/}

    echo
    info "HTTPS:"
    info "  letsencrypt  Zertifikat automatisch von Let's Encrypt (DNS muss auf diesen Server zeigen)"
    info "  existing     vorhandenes Zertifikat (z. B. Wildcard) verwenden"
    info "  proxy        ein vorgeschalteter Reverse Proxy übernimmt HTTPS, Nginx lauscht nur auf Port 80"
    ask DEMO_TLS "HTTPS-Variante" letsencrypt valid_tls
    case "$DEMO_TLS" in
        letsencrypt)
            ask DEMO_LE_EMAIL "E-Mail für Let's Encrypt (Ablaufwarnungen, optional)" "" valid_optional_email
            ;;
        existing)
            ask DEMO_CERT "Zertifikatskette (fullchain.pem) für beide Domains" "" valid_file
            ask DEMO_CERT_KEY "Privater Schlüssel (privkey.pem)" "" valid_file
            ;;
        proxy)
            ask DEMO_PROXIES "IP-Adressen des Reverse Proxys (Komma-getrennt)" "" valid_proxies
            ;;
    esac

    echo
    info "Die Demo leitet /impressum und /datenschutz auf die Seiten des Betreibers um."
    info "Ohne Angabe zeigt sie die Seiten des fiktiven Mustervereins."
    ask DEMO_IMPRINT_URL "Adresse des Impressums" "" valid_optional_url
    ask DEMO_PRIVACY_URL "Adresse der Datenschutzerklärung" "" valid_optional_url
    ask DEMO_PASSWORD "Passwort der Demo-Konten (wird auf der Anmeldeseite angezeigt)" Demo-Passwort-2026 valid_password
    ask DEMO_RESET_AT "Uhrzeit der nächtlichen Zurücksetzung" 00:00 valid_time
    ask DEMO_MAIL_FROM "Absenderadresse der Mails im Demo-Postfach" "noreply@$DEMO_RELEASE_DOMAIN" valid_email

    echo
    info "GitHub Actions kann beide Instanzen automatisch aktualisieren (nach jedem Release"
    info "und jedem erfolgreichen Testlauf auf main). Dafür richtet das Skript einen SSH-Schlüssel"
    info "ein, der ausschließlich das Deploy-Skript ausführen darf."
    local deploy_default=y
    [[ ${DEMO_GITHUB_DEPLOY:-1} == 0 ]] && deploy_default=n
    if confirm "Deploy-Schlüssel für GitHub Actions einrichten?" "$deploy_default"; then
        github_deploy=true
        ask DEMO_SSH_HOST "SSH-Adresse dieses Servers für GitHub Actions" "$DEMO_RELEASE_DOMAIN" valid_host
        ask DEMO_SSH_PORT "SSH-Port" "$(detect_ssh_port)" valid_port
        # The private key goes to the admin who called sudo, so it can be read
        # over SSH without a sudo password.
        key_owner=${SUDO_USER:-root}
        deploy_key="$(getent passwd "$key_owner" | cut -d: -f6)/$DEMO_NAME-deploy-key"
    else
        github_deploy=false
    fi

    open_firewall=false
    if command -v ufw > /dev/null && ufw status 2> /dev/null | grep -q '^Status: active'; then
        local fw_default=y
        [[ ${DEMO_OPEN_FIREWALL:-1} == 0 ]] && fw_default=n
        if confirm "Die Firewall ufw ist aktiv. Ports 80 und 443 freigeben?" "$fw_default"; then
            open_firewall=true
        fi
    fi
}

# Everything that could collide with the existing system is checked before
# anything is changed.
check_conflicts() {
    step "Prüfe das bestehende System"
    local path problems=()

    if id -u "$DEMO_NAME" > /dev/null 2>&1 || getent group "$DEMO_NAME" > /dev/null; then
        problems+=("Benutzer oder Gruppe '$DEMO_NAME' existiert bereits.")
    fi
    for path in \
        "/usr/local/bin/$DEMO_NAME-deploy" \
        "/usr/local/lib/$DEMO_NAME" \
        "/etc/php/$php_version/fpm/pool.d/$DEMO_NAME.conf" \
        "/run/php/$DEMO_NAME.sock" \
        "/etc/nginx/sites-available/$DEMO_NAME" \
        "/etc/nginx/sites-enabled/$DEMO_NAME" \
        "/etc/nginx/conf.d/$DEMO_NAME.conf" \
        "/etc/systemd/system/$DEMO_NAME-queue@.service" \
        "/etc/systemd/system/$DEMO_NAME-schedule@.service" \
        "/etc/systemd/system/$DEMO_NAME-schedule@.timer" \
        "/etc/letsencrypt/renewal/$DEMO_NAME.conf" \
        "/var/www/$DEMO_NAME-acme" \
        ${deploy_key:+"$deploy_key"}; do
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
            for domain in "$DEMO_RELEASE_DOMAIN" "$DEMO_NEXT_DOMAIN"; do
                if grep -Eq "^[[:space:]]*server_name[^;]*[[:space:]]${domain//./\\.}([[:space:];]|$)" <<< "$dump"; then
                    problems+=("Nginx liefert $domain bereits aus.")
                fi
            done
        fi
    fi

    if command -v ss > /dev/null; then
        local port ports=(80) listeners
        [[ $DEMO_TLS != proxy ]] && ports+=(443)
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

    if ((${#problems[@]})); then
        printf '  - %s\n' "${problems[@]}" >&2
        die "Die Installation würde bestehende Einstellungen überschreiben und wird nicht gestartet."
    fi
    info "Keine Konflikte gefunden."
}

summary() {
    step "Zusammenfassung"
    info "Instanz release:   https://$DEMO_RELEASE_DOMAIN"
    info "Instanz next:      https://$DEMO_NEXT_DOMAIN"
    info "Verzeichnis:       $DEMO_ROOT"
    info "Benutzer/Dienste:  $DEMO_NAME"
    info "HTTPS:             $DEMO_TLS"
    info "Zurücksetzung:     täglich um $DEMO_RESET_AT"
    info "GitHub Actions:    $($github_deploy && echo "ja, SSH $DEMO_SSH_HOST:$DEMO_SSH_PORT" || echo nein)"
    echo
    info "Beide Instanzen löschen bei jedem Deployment und jede Nacht alle Daten."
    info "Niemals auf einem Server mit echten Vereinsdaten im selben Verzeichnis betreiben."
    echo
    confirm "Installation starten?" y || exit 1
}

# --- Installation steps ------------------------------------------------------

start_journal() {
    installing=true
    install -d -m 0700 "$state_base"
    record core undo_remove_tree "$state_dir"
    install -d -m 0700 "$state_dir"
    journal_file="$state_dir/journal"
    printf '%s\n' "${journal[@]}" > "$journal_file"
    chmod 0600 "$journal_file"
}

add_php_repository() {
    local list="/etc/apt/sources.list.d/$DEMO_NAME-php.list" key url
    step "PHP $php_version ist in den Paketquellen nicht verfügbar"
    if [[ $os_id == ubuntu ]]; then
        info "Es wird aus dem PPA ondrej/php installiert (neben vorhandenen PHP-Versionen)."
        key="/etc/apt/keyrings/$DEMO_NAME-php.asc"
        url="https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C"
    else
        info "Es wird aus packages.sury.org installiert (neben vorhandenen PHP-Versionen)."
        key="/etc/apt/keyrings/$DEMO_NAME-php.gpg"
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
    local wanted=(ca-certificates curl tar gzip openssl openssh-client nginx "${php_packages[@]}") missing=() pkg
    if [[ $DEMO_TLS == letsencrypt ]] && ! command -v certbot > /dev/null; then
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
    if [[ $DEMO_TLS == letsencrypt ]] && command -v certbot > /dev/null; then
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
            record pkg apt-get remove -y "${base[@]}"
            DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "${base[@]}"
        fi
        if ((${#php[@]})); then
            package_available "php$php_version-fpm" || add_php_repository
            # Installing a newer PHP switches the "php" command to it via
            # update-alternatives. Keep whatever was the default before.
            local alternative previous=()
            for alternative in php phar phar.phar; do
                previous+=("$(readlink -f "/etc/alternatives/$alternative" 2> /dev/null || true)")
            done
            record pkg apt-get remove -y "${php[@]}"
            DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "${php[@]}"
            local i=0
            for alternative in php phar phar.phar; do
                if [[ -n ${previous[i]} && -e ${previous[i]} && "$(readlink -f "/etc/alternatives/$alternative")" != "${previous[i]}" ]]; then
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
    for ext in "${php_extensions[@]}"; do
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
    [[ $DEMO_TLS != proxy ]] && ports+=(443/tcp)
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

create_user_and_directories() {
    step "Benutzer und Verzeichnisse"
    record core undo_remove_user "$DEMO_NAME"
    useradd --system --user-group --home-dir "$DEMO_ROOT" --no-create-home --shell /bin/bash "$DEMO_NAME"
    # "*" instead of the locked "!": no password login, but sshd accepts the key.
    usermod -p '*' "$DEMO_NAME"
    info "Systembenutzer $DEMO_NAME ohne Passwort und ohne sudo angelegt."

    # The home directory belongs to root, so the demo user (and therefore PHP)
    # cannot replace .ssh/authorized_keys and lift the forced command.
    record core undo_remove_tree "$DEMO_ROOT"
    install -d -o root -g root -m 0711 "$DEMO_ROOT"

    local target base
    for target in "${instances[@]}"; do
        base="$DEMO_ROOT/$target"
        # Nginx (www-data) may only traverse to public/ and storage/app/public.
        install -d -o "$DEMO_NAME" -g "$DEMO_NAME" -m 0711 "$base" "$base/shared" "$base/shared/storage" "$base/shared/storage/app"
        install -d -o "$DEMO_NAME" -g "$DEMO_NAME" -m 0755 "$base/releases" "$base/shared/storage/app/public"
        install -d -o "$DEMO_NAME" -g "$DEMO_NAME" -m 0700 \
            "$base/shared/storage/app/private" \
            "$base/shared/storage/framework" \
            "$base/shared/storage/framework/cache" \
            "$base/shared/storage/framework/cache/data" \
            "$base/shared/storage/framework/sessions" \
            "$base/shared/storage/framework/views" \
            "$base/shared/storage/logs"
        install -m 0600 -o "$DEMO_NAME" -g "$DEMO_NAME" /dev/null "$base/shared/database.sqlite"
        # Keeps the browser installer closed; demo:reset does not delete it.
        install -m 0600 -o "$DEMO_NAME" -g "$DEMO_NAME" /dev/null "$base/shared/storage/app/installed"
    done
    info "$DEMO_ROOT/{release,next}"
}

write_env_files() {
    step "Konfiguration (.env)"
    local target base domain
    for target in "${instances[@]}"; do
        base="$DEMO_ROOT/$target"
        [[ $target == release ]] && domain=$DEMO_RELEASE_DOMAIN || domain=$DEMO_NEXT_DOMAIN
        install -m 0600 -o "$DEMO_NAME" -g "$DEMO_NAME" /dev/null "$base/shared/.env"
        cat > "$base/shared/.env" << EOF
# Erzeugt von install-demo-server.sh. Beschreibung aller Werte: docs/konfiguration.md
APP_NAME=GymSLunity
APP_ENV=production
APP_KEY=base64:$(random_base64)
APP_DEBUG=false
APP_URL=https://$domain
GYMSLUNITY_UPDATE_CHECK=false
PASSKEYS_USER_HANDLE_SECRET=base64:$(random_base64)

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=$base/shared/database.sqlite
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_FROM_ADDRESS="$DEMO_MAIL_FROM"
MAIL_FROM_NAME="GymSLunity Demo"

DEMO_MODE=true
DEMO_PASSWORD="$DEMO_PASSWORD"
DEMO_RESET_AT=$DEMO_RESET_AT
EOF
        [[ -z $DEMO_IMPRINT_URL ]] || echo "DEMO_IMPRINT_URL=$DEMO_IMPRINT_URL" >> "$base/shared/.env"
        [[ -z $DEMO_PRIVACY_URL ]] || echo "DEMO_PRIVACY_URL=$DEMO_PRIVACY_URL" >> "$base/shared/.env"
        info "$base/shared/.env"
    done
}

install_deploy_script() {
    step "Deploy-Skript"
    local lib="/usr/local/lib/$DEMO_NAME" src=$deploy_script
    record core undo_remove_tree "$lib"
    install -d -m 0755 "$lib" "$lib/bin"
    install -m 0755 "$src" "$lib/deploy-demo.sh"
    # deploy-demo.sh calls "php"; this keeps it on PHP 8.4 even when the
    # system default is another version.
    ln -s "$php_bin" "$lib/bin/php"
    write_file "/usr/local/bin/$DEMO_NAME-deploy" 0755 root:root << EOF
#!/bin/sh
# Erzeugt von install-demo-server.sh. Aufruf als $DEMO_NAME:
#   $DEMO_NAME-deploy release|next < gymslunity-vX.Y.Z.tar.gz
PATH=$lib/bin:/usr/local/bin:/usr/bin:/bin
DEMO_ROOT=$DEMO_ROOT
export PATH DEMO_ROOT
exec $lib/deploy-demo.sh "\$@"
EOF
    info "/usr/local/bin/$DEMO_NAME-deploy"
}

configure_php_fpm() {
    step "PHP-FPM-Pool"
    local pool="/etc/php/$php_version/fpm/pool.d/$DEMO_NAME.conf"
    record core undo_remove_fpm_pool "$pool"
    install -m 0644 /dev/null "$pool"
    cat > "$pool" << EOF
; Erzeugt von install-demo-server.sh
[$DEMO_NAME]
user = $DEMO_NAME
group = $DEMO_NAME
listen = /run/php/$DEMO_NAME.sock
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
        printf '/etc/nginx/sites-available/%s' "$DEMO_NAME"
    else
        printf '/etc/nginx/conf.d/%s.conf' "$DEMO_NAME"
    fi
}

write_nginx_conf() {
    local phase=$1 conf=$2 listen_v6=false http2_directive=true acme="/var/www/$DEMO_NAME-acme"
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
        local target=$1 domain=$2
        cat << EOF

server {
$(if [[ $DEMO_TLS == proxy ]]; then listen 80; else listen 443 " ssl"; fi)
    server_name $domain;
    server_tokens off;

    root $DEMO_ROOT/$target/current/public;
    index index.php;
    charset utf-8;
EOF
        case "$DEMO_TLS" in
            letsencrypt)
                echo "    ssl_certificate /etc/letsencrypt/live/$DEMO_NAME/fullchain.pem;"
                echo "    ssl_certificate_key /etc/letsencrypt/live/$DEMO_NAME/privkey.pem;"
                ;;
            existing)
                echo "    ssl_certificate $DEMO_CERT;"
                echo "    ssl_certificate_key $DEMO_CERT_KEY;"
                ;;
            proxy)
                # The proxy terminates HTTPS: take the client address from it
                # (rate limits, logs) and tell PHP that the request was secure.
                local proxy
                IFS=, read -ra proxy_list <<< "$DEMO_PROXIES"
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
        limit_req zone=$DEMO_NAME burst=30 nodelay;
        limit_req_status 429;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
$([[ $DEMO_TLS == proxy ]] && echo "        fastcgi_param HTTPS on;")
        fastcgi_pass unix:/run/php/$DEMO_NAME.sock;
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
        echo "# Erzeugt von install-demo-server.sh für die GymSLunity-Demo '$DEMO_NAME'."
        echo "limit_req_zone \$binary_remote_addr zone=$DEMO_NAME:10m rate=10r/s;"
        if [[ $DEMO_TLS != proxy ]]; then
            cat << EOF

server {
$(listen 80)
    server_name $DEMO_RELEASE_DOMAIN $DEMO_NEXT_DOMAIN;
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
            app_server release "$DEMO_RELEASE_DOMAIN"
            app_server next "$DEMO_NEXT_DOMAIN"
        fi
    } > "$conf"
}

configure_nginx() {
    step "Nginx"
    nginx_conf="$(nginx_conf_path)"
    local enabled=""
    [[ $nginx_conf == /etc/nginx/sites-available/* ]] && enabled="/etc/nginx/sites-enabled/$DEMO_NAME"
    record core undo_remove_nginx_conf "$nginx_conf" ${enabled:+"$enabled"}
    install -m 0644 /dev/null "$nginx_conf"
    [[ -z $enabled ]] || ln -s "$nginx_conf" "$enabled"

    if [[ $DEMO_TLS == letsencrypt ]]; then
        record core undo_remove_tree "/var/www/$DEMO_NAME-acme"
        install -d -m 0755 "/var/www/$DEMO_NAME-acme/.well-known/acme-challenge"
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
    local domain token challenge="/var/www/$DEMO_NAME-acme/.well-known/acme-challenge" unreachable=()
    # Let's Encrypt only answers if both domains reach this server on port 80.
    token="gymslunity-check-$(openssl rand -hex 8)"
    echo "$token" > "$challenge/$token"
    for domain in "$DEMO_RELEASE_DOMAIN" "$DEMO_NEXT_DOMAIN"; do
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

    local args=(certonly --webroot -w "/var/www/$DEMO_NAME-acme" --cert-name "$DEMO_NAME"
        -d "$DEMO_RELEASE_DOMAIN" -d "$DEMO_NEXT_DOMAIN"
        --non-interactive --agree-tos --deploy-hook "systemctl reload nginx")
    if [[ -n $DEMO_LE_EMAIL ]]; then
        args+=(--email "$DEMO_LE_EMAIL")
    else
        args+=(--register-unsafely-without-email)
    fi
    [[ ${DEMO_LE_STAGING:-0} == 1 ]] && args+=(--test-cert)
    record core certbot delete --non-interactive --cert-name "$DEMO_NAME"
    certbot "${args[@]}"
    info "Zertifikat für $DEMO_RELEASE_DOMAIN und $DEMO_NEXT_DOMAIN erhalten; certbot verlängert es automatisch."
}

download_release() {
    step "Release herunterladen"
    local tag api
    tag=${DEMO_RELEASE_TAG:-}
    if [[ -z $tag ]]; then
        # Like the GitHub workflow: the newest release including pre-releases.
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

    local listing
    listing="$(tar -tzf "$work_dir/$name")"
    grep -q '/app/Console/Commands/ResetDemo.php$' <<< "$listing" ||
        die "$tag enthält den Demo-Modus noch nicht (ab v1.0.0-beta.4). Mit DEMO_RELEASE_TAG ein neueres Release wählen."
    archive="$work_dir/$name"
    info "$tag, Prüfsumme in Ordnung."

    # The deploy script comes from the checkout next to this script, otherwise
    # from the release itself.
    if [[ -n $script_dir && -f $script_dir/deploy-demo.sh ]]; then
        deploy_script="$script_dir/deploy-demo.sh"
    else
        grep -q '/scripts/deploy-demo.sh$' <<< "$listing" || die "$tag enthält scripts/deploy-demo.sh nicht."
        tar -xzf "$archive" -C "$work_dir" --wildcards '*/scripts/deploy-demo.sh' --strip-components=2
        deploy_script="$work_dir/deploy-demo.sh"
    fi
    release_tag=$tag
}

deploy_instances() {
    local target
    for target in "${instances[@]}"; do
        step "Erstes Deployment: $target ($release_tag)"
        (cd / && env -u SSH_ORIGINAL_COMMAND runuser -u "$DEMO_NAME" -- "/usr/local/bin/$DEMO_NAME-deploy" "$target" < "$archive")
    done
}

install_units() {
    step "Queue-Worker und Scheduler"
    local dir=/etc/systemd/system protect_home="ProtectHome=yes"
    [[ $DEMO_ROOT == /home/* || $DEMO_ROOT == /root/* || $DEMO_ROOT == /run/user/* ]] && protect_home=""
    local hardening
    hardening="$(
        cat << EOF
User=$DEMO_NAME
Group=$DEMO_NAME
UMask=0027
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ReadWritePaths=$DEMO_ROOT
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
    )"

    record core undo_remove_unit_files "$dir/$DEMO_NAME-queue@.service" "$dir/$DEMO_NAME-schedule@.service" "$dir/$DEMO_NAME-schedule@.timer"
    cat > "$dir/$DEMO_NAME-queue@.service" << EOF
# Erzeugt von install-demo-server.sh
[Unit]
Description=GymSLunity-Demo $DEMO_NAME: Queue-Worker (%i)
After=network.target
StartLimitIntervalSec=0

[Service]
WorkingDirectory=$DEMO_ROOT/%i/current
ExecStart=$php_bin artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5
$hardening

[Install]
WantedBy=multi-user.target
EOF
    cat > "$dir/$DEMO_NAME-schedule@.service" << EOF
# Erzeugt von install-demo-server.sh
[Unit]
Description=GymSLunity-Demo $DEMO_NAME: Scheduler (%i)

[Service]
Type=oneshot
WorkingDirectory=$DEMO_ROOT/%i/current
ExecStart=$php_bin artisan schedule:run
$hardening
EOF
    cat > "$dir/$DEMO_NAME-schedule@.timer" << EOF
# Erzeugt von install-demo-server.sh
[Unit]
Description=GymSLunity-Demo $DEMO_NAME: Scheduler jede Minute (%i)

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload

    local target units=()
    for target in "${instances[@]}"; do
        units+=("$DEMO_NAME-queue@$target.service" "$DEMO_NAME-schedule@$target.timer")
    done
    record core undo_remove_units "${units[@]}"
    systemctl enable --now -q "${units[@]}"
    info "${units[*]}"
}

setup_deploy_key() {
    $github_deploy || return 0
    step "Deploy-Schlüssel für GitHub Actions"
    record core rm -f -- "$deploy_key" "$deploy_key.pub"
    ssh-keygen -q -t ed25519 -N '' -C "github-actions-$DEMO_NAME" -f "$deploy_key"
    chown "$key_owner:" "$deploy_key" "$deploy_key.pub"

    # Owned by root, so the demo user cannot change the restriction.
    install -d -o root -g root -m 0755 "$DEMO_ROOT/.ssh"
    install -m 0644 -o root -g root /dev/null "$DEMO_ROOT/.ssh/authorized_keys"
    echo "command=\"/usr/local/bin/$DEMO_NAME-deploy\",restrict $(cat "$deploy_key.pub")" > "$DEMO_ROOT/.ssh/authorized_keys"

    local host_key hostspec=$DEMO_SSH_HOST
    [[ $DEMO_SSH_PORT == 22 ]] || hostspec="[$DEMO_SSH_HOST]:$DEMO_SSH_PORT"
    known_hosts=""
    for host_key in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_ecdsa_key.pub /etc/ssh/ssh_host_rsa_key.pub; do
        if [[ -r $host_key ]]; then
            known_hosts="$hostspec $(cut -d' ' -f1-2 "$host_key")"
            break
        fi
    done
    [[ -n $known_hosts ]] || warn "Kein SSH-Host-Schlüssel gefunden. Läuft ein SSH-Server? DEMO_SSH_KNOWN_HOSTS muss dann von Hand mit ssh-keyscan ermittelt werden."

    if [[ $DEMO_SSH_PORT == 22 ]]; then
        ssh_target="$DEMO_NAME@$DEMO_SSH_HOST"
    else
        ssh_target="ssh://$DEMO_NAME@$DEMO_SSH_HOST:$DEMO_SSH_PORT"
    fi

    local sshd_config
    if sshd_config="$(sshd -T 2> /dev/null)"; then
        grep -Eq '^(allowusers|allowgroups) ' <<< "$sshd_config" &&
            warn "sshd beschränkt die Anmeldung mit AllowUsers/AllowGroups. '$DEMO_NAME' dort ergänzen, sonst scheitern die Deployments."
        grep -q '^pubkeyauthentication no' <<< "$sshd_config" &&
            warn "sshd erlaubt keine Anmeldung mit Schlüsseln (PubkeyAuthentication no)."
    else
        warn "Kein SSH-Server gefunden. GitHub Actions braucht SSH-Zugang zu diesem Server."
    fi
    info "Öffentlicher Schlüssel in $DEMO_ROOT/.ssh/authorized_keys (nur Deploy-Skript erlaubt)."
}

save_state() {
    cat > "$state_dir/config" << EOF
# install-demo-server.sh, $(date -Iseconds)
DEMO_NAME=$DEMO_NAME
DEMO_ROOT=$DEMO_ROOT
DEMO_RELEASE_DOMAIN=$DEMO_RELEASE_DOMAIN
DEMO_NEXT_DOMAIN=$DEMO_NEXT_DOMAIN
DEMO_TLS=$DEMO_TLS
NGINX_CONF=$nginx_conf
RELEASE_TAG=$release_tag
EOF
    chmod 0600 "$state_dir/config"
}

check_instances() {
    step "Prüfe die Instanzen"
    local target domain url code resolve=()
    for target in "${instances[@]}"; do
        [[ $target == release ]] && domain=$DEMO_RELEASE_DOMAIN || domain=$DEMO_NEXT_DOMAIN
        if [[ $DEMO_TLS == proxy ]]; then
            url="http://$domain/login"
            resolve=(--resolve "$domain:80:127.0.0.1")
        else
            url="https://$domain/login"
            resolve=(--resolve "$domain:443:127.0.0.1" -k)
        fi
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${resolve[@]}" "$url" 2> /dev/null || true)"
        if [[ $code == 200 ]]; then
            info "$target: Anmeldeseite antwortet."
        else
            warn "$target: $url antwortet mit '$code'. Logs: $DEMO_ROOT/$target/shared/storage/logs/"
        fi
    done
}

print_result() {
    local scheme=https
    printf '\n%sDie Demo ist eingerichtet.%s\n\n' "$c_green" "$c_off"
    info "release:  $scheme://$DEMO_RELEASE_DOMAIN   ($release_tag)"
    info "next:     $scheme://$DEMO_NEXT_DOMAIN   (vorerst ebenfalls $release_tag)"
    info "Passwort der Demo-Konten: $DEMO_PASSWORD (steht auf der Anmeldeseite)"
    info "Demo-Postfach: /demo/postfach"
    echo
    info "Entfernen:  sudo bash install-demo-server.sh --uninstall"
    info "Logs:       $DEMO_ROOT/<instanz>/shared/storage/logs/, journalctl -u '$DEMO_NAME-*'"

    if $github_deploy; then
        cat << EOF

${c_blue}==> GitHub Actions verbinden${c_off}
    Auf einem Rechner mit angemeldeter GitHub-CLI (gh) ausführen. Der private
    Schlüssel geht dabei direkt vom Server zu GitHub und wird danach gelöscht.

REPO=$repo
gh api -X PUT "repos/\$REPO/environments/demo" > /dev/null
ssh $key_owner@$DEMO_SSH_HOST 'cat $deploy_key' | gh secret set DEMO_SSH_KEY --env demo -R "\$REPO"
gh secret set DEMO_SSH_KNOWN_HOSTS --env demo -R "\$REPO" --body '$known_hosts'
gh variable set DEMO_SSH_TARGET -R "\$REPO" --body '$ssh_target'
gh variable set DEMO_DEPLOY -R "\$REPO" --body true
ssh $key_owner@$DEMO_SSH_HOST 'shred -u $deploy_key $deploy_key.pub'
gh workflow run demo-deploy.yml -R "\$REPO" -f target=next

    Die letzte Zeile bringt 'next' sofort auf den Stand von main; sonst geschieht
    das nach dem nächsten erfolgreichen Testlauf. GitHub Actions verbindet sich
    von wechselnden Adressen, SSH (Port $DEMO_SSH_PORT) muss daher offen sein.
    Details: docs/demo.md, Abschnitt 5.
EOF
    fi
}

install_demo() {
    open_terminal
    preflight
    configure
    check_conflicts
    summary

    trap on_exit EXIT
    trap 'exit 130' INT TERM
    work_dir="$(mktemp -d)"
    start_journal

    local task
    for task in install_packages open_firewall_ports download_release \
        create_user_and_directories write_env_files install_deploy_script \
        configure_php_fpm configure_nginx deploy_instances install_units \
        setup_deploy_key save_state; do
        "$task"
        # Lets the CI test the rollback after any step.
        [[ ${DEMO_TEST_FAIL_AFTER:-} != "$task" ]] || die "Testabbruch nach $task (DEMO_TEST_FAIL_AFTER)."
    done
    completed=true
    check_instances
    print_result
}

# --- Uninstall ---------------------------------------------------------------

uninstall_demo() {
    open_terminal
    [[ $EUID -eq 0 ]] || die "Bitte mit sudo oder als root ausführen."
    local names=() dir
    for dir in "$state_base"/*/; do
        [[ -f $dir/journal ]] && names+=("$(basename "$dir")")
    done
    ((${#names[@]})) || die "Keine mit diesem Skript eingerichtete Demo gefunden ($state_base)."

    if [[ -z ${DEMO_NAME:-} && ${#names[@]} -eq 1 ]]; then
        DEMO_NAME=${names[0]}
    else
        info "Gefunden: ${names[*]}"
        ask DEMO_NAME "Welche Demo entfernen?" "${names[0]}" valid_name
    fi
    state_dir="$state_base/$DEMO_NAME"
    [[ -f $state_dir/journal ]] || die "Keine Installation '$DEMO_NAME' gefunden."

    mapfile -t journal < "$state_dir/journal"
    step "Demo '$DEMO_NAME' entfernen"
    [[ -f $state_dir/config ]] && sed -n 's/^\(DEMO_[A-Z_]*\)=/    \1: /p' "$state_dir/config"
    [[ -f $state_dir/config ]] || warn "Die Installation wurde nicht abgeschlossen; es wird entfernt, was angelegt wurde."
    echo
    info "Entfernt werden Benutzer, Verzeichnisse mit allen Demo-Daten, Nginx-Site,"
    info "PHP-FPM-Pool, Dienste, Deploy-Skript und das Let's-Encrypt-Zertifikat der Demo."
    confirm "Demo '$DEMO_NAME' vollständig entfernen?" "$($assume_yes && echo y || echo n)" || exit 1

    local with_packages=false entry extras=()
    for entry in "${journal[@]}"; do
        [[ $entry == core$'\t'* ]] || extras+=("${entry#*$'\t'}")
    done
    if ((${#extras[@]})); then
        info "Bei der Installation wurden außerdem hinzugefügt:"
        printf '      %s\n' "${extras[@]}"
        info "Andere Anwendungen könnten sie inzwischen nutzen."
        confirm "Auch diese Pakete bzw. Paketquellen entfernen?" n && with_packages=true
    fi

    undo_journal "$with_packages"
    printf '\n%sDemo %s entfernt.%s\n' "$c_green" "$DEMO_NAME" "$c_off"
    info "GitHub: Variable DEMO_DEPLOY löschen oder auf false setzen, sonst schlagen die Deployments fehl."
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
    uninstall_demo
else
    install_demo
fi
