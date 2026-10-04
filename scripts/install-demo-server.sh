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
# The shared parts live in install-common.sh.
#
# All questions can be answered in advance with environment variables (see
# --help), which together with --yes allows unattended installs.

set -Eeuo pipefail
umask 022

readonly repo="${GYMSLUNITY_REPO:-Abiturientia-am-GymSL-e-V/GymSLunity}"
readonly state_base=/var/lib/gymslunity-demo-installer
readonly installer_label=install-demo-server.sh
readonly instances=(release next)
readonly sshd_dropin_dir=/etc/ssh/sshd_config.d

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
fail_after_variable=DEMO_TEST_FAIL_AFTER
deploy_key=""
deploy_script=""
key_owner=root
known_hosts=""
ssh_target=""
sshd_allow=""
github_deploy=false

# Prints the AllowUsers/AllowGroups lines sshd needs so that the demo user
# may log in, or nothing if it already may (or sshd restricts nothing).
sshd_missing_allow() {
    local config
    config="$(sshd -T 2> /dev/null)" || return 0
    if grep -q '^allowusers ' <<< "$config" && ! grep -qx "allowusers $inst_name" <<< "$config"; then
        echo "AllowUsers $inst_name"
    fi
    if grep -q '^allowgroups ' <<< "$config" && ! grep -qx "allowgroups $inst_name" <<< "$config"; then
        echo "AllowGroups $inst_name"
    fi
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
  DEMO_SSHD_ALLOW        1/0: Demo-Benutzer bei AllowUsers/AllowGroups ergänzen, falls nötig (1)
  DEMO_OPEN_FIREWALL     1/0: Ports 80/443 in ufw freigeben, falls aktiv (1)
  DEMO_RELEASE_TAG       Release für das erste Deployment (neuestes)
  DEMO_UNINSTALL_PACKAGES   1 = bei --uninstall --yes auch Pakete entfernen
  GYMSLUNITY_REPO        GitHub-Repository ($repo)
EOF
}

# --- Configuration -----------------------------------------------------------

configure() {
    step "Einstellungen"
    info "Enter übernimmt den Wert in eckigen Klammern."
    echo

    ask DEMO_NAME "Kurzname für Benutzer, Dienste und Dateien" gymslunity-demo valid_name
    inst_name=$DEMO_NAME
    state_dir="$state_base/$inst_name"
    [[ ! -e $state_dir ]] || die "Für '$inst_name' gibt es bereits eine Installation. Zuerst mit --uninstall entfernen."

    ask DEMO_RELEASE_DOMAIN "Domain der Instanz 'release' (neuestes Release)" "" valid_domain
    ask DEMO_NEXT_DOMAIN "Domain der Instanz 'next' (aktueller Stand von main)" "next.$DEMO_RELEASE_DOMAIN" valid_domain
    [[ $DEMO_RELEASE_DOMAIN != "$DEMO_NEXT_DOMAIN" ]] || die "Beide Instanzen brauchen eigene Domains."
    ask DEMO_ROOT "Installationsverzeichnis" "/srv/$inst_name" valid_new_dir
    DEMO_ROOT=${DEMO_ROOT%/}
    domains=("$DEMO_RELEASE_DOMAIN" "$DEMO_NEXT_DOMAIN")
    site_publics=("$DEMO_ROOT/release/current/public" "$DEMO_ROOT/next/current/public")

    ask_tls DEMO

    echo
    info "Die Demo leitet /impressum und /datenschutz auf die Seiten des Betreibers um."
    info "Ohne Angabe zeigt sie die Seiten des fiktiven Mustervereins."
    ask DEMO_IMPRINT_URL "Adresse des Impressums" "" valid_optional_url
    ask DEMO_PRIVACY_URL "Adresse der Datenschutzerklärung" "" valid_optional_url
    ask DEMO_PASSWORD "Passwort der Demo-Konten (wird auf der Anmeldeseite angezeigt)" Demo-Passwort-2026 valid_demo_password
    ask DEMO_RESET_AT "Uhrzeit der nächtlichen Zurücksetzung" 00:00 valid_time
    ask DEMO_MAIL_FROM "Absenderadresse der Mails im Demo-Postfach" "noreply@$DEMO_RELEASE_DOMAIN" valid_email

    echo
    info "GitHub Actions kann beide Instanzen automatisch aktualisieren (nach jedem Release"
    info "und jedem erfolgreichen Testlauf auf main). Dafür richtet das Skript einen SSH-Schlüssel"
    info "ein, der ausschließlich das Deploy-Skript ausführen darf."
    if confirm "Deploy-Schlüssel für GitHub Actions einrichten?" "$(flag_default DEMO_GITHUB_DEPLOY)"; then
        github_deploy=true
        ask DEMO_SSH_HOST "SSH-Adresse dieses Servers für GitHub Actions" "$DEMO_RELEASE_DOMAIN" valid_host
        ask DEMO_SSH_PORT "SSH-Port" "$(detect_ssh_port)" valid_port
        # The private key goes to the admin who called sudo, so it can be read
        # over SSH without a sudo password.
        key_owner=${SUDO_USER:-root}
        deploy_key="$(getent passwd "$key_owner" | cut -d: -f6)/$inst_name-deploy-key"

        local missing_allow
        missing_allow="$(sshd_missing_allow)"
        if [[ -n $missing_allow ]]; then
            echo
            info "sshd lässt nur bestimmte Benutzer oder Gruppen zu (AllowUsers/AllowGroups),"
            info "'$inst_name' ist nicht dabei. Ohne Freigabe scheitern die Deployments."
            if ! grep -Eqi "^[[:space:]]*Include[[:space:]]+$sshd_dropin_dir/\*\.conf" /etc/ssh/sshd_config 2> /dev/null; then
                warn "/etc/ssh/sshd_config bindet $sshd_dropin_dir nicht ein. Bitte '$inst_name' dort selbst bei AllowUsers/AllowGroups ergänzen."
            else
                info "Die bestehenden Einträge bleiben erhalten, es wird nur '$inst_name' ergänzt."
                if confirm "Freigabe in $sshd_dropin_dir/$inst_name.conf anlegen?" "$(flag_default DEMO_SSHD_ALLOW)"; then
                    sshd_allow=$missing_allow
                else
                    warn "Dann '$inst_name' bitte selbst bei AllowUsers/AllowGroups ergänzen."
                fi
            fi
        fi
    fi

    ask_firewall DEMO
}

valid_demo_password() {
    [[ ${#1} -ge 8 ]] || {
        warn "Mindestens 8 Zeichen."
        return 1
    }
    valid_env_text "$1"
}

check_conflicts() {
    step "Prüfe das bestehende System"
    problems=()
    check_common_conflicts \
        "/usr/local/bin/$inst_name-deploy" \
        "/usr/local/lib/$inst_name" \
        "/etc/systemd/system/$inst_name-queue@.service" \
        "/etc/systemd/system/$inst_name-schedule@.service" \
        "/etc/systemd/system/$inst_name-schedule@.timer" \
        "$sshd_dropin_dir/$inst_name.conf" \
        ${deploy_key:+"$deploy_key"}
    report_conflicts
}

summary() {
    step "Zusammenfassung"
    info "Instanz release:   https://$DEMO_RELEASE_DOMAIN"
    info "Instanz next:      https://$DEMO_NEXT_DOMAIN"
    info "Verzeichnis:       $DEMO_ROOT"
    info "Benutzer/Dienste:  $inst_name"
    info "HTTPS:             $tls_mode"
    info "Zurücksetzung:     täglich um $DEMO_RESET_AT"
    info "GitHub Actions:    $($github_deploy && echo "ja, SSH $DEMO_SSH_HOST:$DEMO_SSH_PORT" || echo nein)"
    [[ -z $sshd_allow ]] || info "SSH-Freigabe:      $sshd_allow ($sshd_dropin_dir/$inst_name.conf)"
    echo
    info "Beide Instanzen löschen bei jedem Deployment und jede Nacht alle Daten."
    info "Niemals auf einem Server mit echten Vereinsdaten im selben Verzeichnis betreiben."
    echo
    confirm "Installation starten?" y || exit 1
}

# --- Installation steps ------------------------------------------------------

fetch_demo_release() {
    download_release "${DEMO_RELEASE_TAG:-}"
    grep -q '/app/Console/Commands/ResetDemo.php$' <<< "$release_listing" ||
        die "$release_tag enthält den Demo-Modus noch nicht (ab v1.0.0-beta.4). Mit DEMO_RELEASE_TAG ein neueres Release wählen."

    # The deploy script comes from the checkout next to this script, otherwise
    # from the release itself.
    if [[ -n $script_dir && -f $script_dir/deploy-demo.sh ]]; then
        deploy_script="$script_dir/deploy-demo.sh"
    else
        grep -q '/scripts/deploy-demo.sh$' <<< "$release_listing" || die "$release_tag enthält scripts/deploy-demo.sh nicht."
        tar -xzf "$archive" -C "$work_dir" --wildcards '*/scripts/deploy-demo.sh' --strip-components=2
        deploy_script="$work_dir/deploy-demo.sh"
    fi
}

create_user_and_directories() {
    step "Benutzer und Verzeichnisse"
    record core undo_remove_user "$inst_name"
    useradd --system --user-group --home-dir "$DEMO_ROOT" --no-create-home --shell /bin/bash "$inst_name"
    # "*" instead of the locked "!": no password login, but sshd accepts the key.
    usermod -p '*' "$inst_name"
    info "Systembenutzer $inst_name ohne Passwort und ohne sudo angelegt."

    # The home directory belongs to root, so the demo user (and therefore PHP)
    # cannot replace .ssh/authorized_keys and lift the forced command.
    record core undo_remove_tree "$DEMO_ROOT"
    install -d -o root -g root -m 0711 "$DEMO_ROOT"

    local target base
    for target in "${instances[@]}"; do
        base="$DEMO_ROOT/$target"
        # Nginx (www-data) may only traverse to public/ and storage/app/public.
        install -d -o "$inst_name" -g "$inst_name" -m 0711 "$base" "$base/shared" "$base/shared/storage" "$base/shared/storage/app"
        install -d -o "$inst_name" -g "$inst_name" -m 0755 "$base/releases" "$base/shared/storage/app/public"
        install -d -o "$inst_name" -g "$inst_name" -m 0700 \
            "$base/shared/storage/app/private" \
            "$base/shared/storage/framework" \
            "$base/shared/storage/framework/cache" \
            "$base/shared/storage/framework/cache/data" \
            "$base/shared/storage/framework/sessions" \
            "$base/shared/storage/framework/views" \
            "$base/shared/storage/logs"
        install -m 0600 -o "$inst_name" -g "$inst_name" /dev/null "$base/shared/database.sqlite"
        # Keeps the browser installer closed; demo:reset does not delete it.
        install -m 0600 -o "$inst_name" -g "$inst_name" /dev/null "$base/shared/storage/app/installed"
    done
    info "$DEMO_ROOT/{release,next}"
}

write_env_files() {
    step "Konfiguration (.env)"
    local i base
    for i in "${!instances[@]}"; do
        base="$DEMO_ROOT/${instances[i]}"
        install -m 0600 -o "$inst_name" -g "$inst_name" /dev/null "$base/shared/.env"
        cat > "$base/shared/.env" << EOF
# Erzeugt von $installer_label. Beschreibung aller Werte: docs/konfiguration.md
APP_NAME=GymSLunity
APP_ENV=production
APP_KEY=base64:$(random_base64)
APP_DEBUG=false
APP_URL=https://${domains[i]}
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
    local lib="/usr/local/lib/$inst_name"
    record core undo_remove_tree "$lib"
    install -d -m 0755 "$lib" "$lib/bin"
    install -m 0755 "$deploy_script" "$lib/deploy-demo.sh"
    # deploy-demo.sh calls "php"; this keeps it on PHP 8.4 even when the
    # system default is another version.
    ln -s "$php_bin" "$lib/bin/php"
    write_file "/usr/local/bin/$inst_name-deploy" 0755 root:root << EOF
#!/bin/sh
# Erzeugt von $installer_label. Aufruf als $inst_name:
#   $inst_name-deploy release|next < gymslunity-vX.Y.Z.tar.gz
PATH=$lib/bin:/usr/local/bin:/usr/bin:/bin
DEMO_ROOT=$DEMO_ROOT
export PATH DEMO_ROOT
exec $lib/deploy-demo.sh "\$@"
EOF
    info "/usr/local/bin/$inst_name-deploy"
}

deploy_instances() {
    local target
    for target in "${instances[@]}"; do
        step "Erstes Deployment: $target ($release_tag)"
        (cd / && env -u SSH_ORIGINAL_COMMAND runuser -u "$inst_name" -- "/usr/local/bin/$inst_name-deploy" "$target" < "$archive")
    done
}

install_units() {
    step "Queue-Worker und Scheduler"
    local dir=/etc/systemd/system hardening
    hardening="$(unit_hardening "$DEMO_ROOT")"

    record core undo_remove_unit_files "$dir/$inst_name-queue@.service" "$dir/$inst_name-schedule@.service" "$dir/$inst_name-schedule@.timer"
    cat > "$dir/$inst_name-queue@.service" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity-Demo $inst_name: Queue-Worker (%i)
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
    cat > "$dir/$inst_name-schedule@.service" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity-Demo $inst_name: Scheduler (%i)

[Service]
Type=oneshot
WorkingDirectory=$DEMO_ROOT/%i/current
ExecStart=$php_bin artisan schedule:run
$hardening
EOF
    cat > "$dir/$inst_name-schedule@.timer" << EOF
# Erzeugt von $installer_label
[Unit]
Description=GymSLunity-Demo $inst_name: Scheduler jede Minute (%i)

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload

    local target units=()
    for target in "${instances[@]}"; do
        units+=("$inst_name-queue@$target.service" "$inst_name-schedule@$target.timer")
    done
    record core undo_remove_units "${units[@]}"
    systemctl enable --now -q "${units[@]}"
    info "${units[*]}"
}

setup_deploy_key() {
    $github_deploy || return 0
    step "Deploy-Schlüssel für GitHub Actions"
    record core rm -f -- "$deploy_key" "$deploy_key.pub"
    ssh-keygen -q -t ed25519 -N '' -C "github-actions-$inst_name" -f "$deploy_key"
    chown "$key_owner:" "$deploy_key" "$deploy_key.pub"

    # Owned by root, so the demo user cannot change the restriction.
    install -d -o root -g root -m 0755 "$DEMO_ROOT/.ssh"
    install -m 0644 -o root -g root /dev/null "$DEMO_ROOT/.ssh/authorized_keys"
    echo "command=\"/usr/local/bin/$inst_name-deploy\",restrict $(cat "$deploy_key.pub")" > "$DEMO_ROOT/.ssh/authorized_keys"

    local host_key hostspec=$DEMO_SSH_HOST
    [[ $DEMO_SSH_PORT == 22 ]] || hostspec="[$DEMO_SSH_HOST]:$DEMO_SSH_PORT"
    for host_key in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_ecdsa_key.pub /etc/ssh/ssh_host_rsa_key.pub; do
        if [[ -r $host_key ]]; then
            known_hosts="$hostspec $(cut -d' ' -f1-2 "$host_key")"
            break
        fi
    done
    [[ -n $known_hosts ]] || warn "Kein SSH-Host-Schlüssel gefunden. Läuft ein SSH-Server? DEMO_SSH_KNOWN_HOSTS muss dann von Hand mit ssh-keyscan ermittelt werden."

    if [[ $DEMO_SSH_PORT == 22 ]]; then
        ssh_target="$inst_name@$DEMO_SSH_HOST"
    else
        ssh_target="ssh://$inst_name@$DEMO_SSH_HOST:$DEMO_SSH_PORT"
    fi

    if [[ -n $sshd_allow ]]; then
        # AllowUsers and AllowGroups add up across files, the existing
        # entries stay valid.
        local dropin="$sshd_dropin_dir/$inst_name.conf"
        record core undo_remove_sshd_dropin "$dropin"
        install -m 0644 /dev/null "$dropin"
        {
            echo "# Erzeugt von $installer_label: Deployments der GitHub-Demo '$inst_name'."
            echo "$sshd_allow"
        } > "$dropin"
        sshd -t || die "Die SSH-Konfiguration ist mit $dropin ungültig (sshd -t)."
        if systemctl is-active -q ssh; then
            systemctl reload ssh
        fi
        [[ -z "$(sshd_missing_allow)" ]] || die "sshd übernimmt die Freigabe aus $dropin nicht."
        info "sshd lässt '$inst_name' zu ($dropin)."
    fi

    local sshd_config
    if sshd_config="$(sshd -T 2> /dev/null)"; then
        if [[ -n "$(sshd_missing_allow)" ]]; then
            warn "sshd beschränkt die Anmeldung mit AllowUsers/AllowGroups. '$inst_name' dort ergänzen, sonst scheitern die Deployments."
        fi
        if grep -q '^pubkeyauthentication no' <<< "$sshd_config"; then
            warn "sshd erlaubt keine Anmeldung mit Schlüsseln (PubkeyAuthentication no)."
        fi
    else
        warn "Kein SSH-Server gefunden. GitHub Actions braucht SSH-Zugang zu diesem Server."
    fi
    info "Öffentlicher Schlüssel in $DEMO_ROOT/.ssh/authorized_keys (nur Deploy-Skript erlaubt)."
}

save_state() {
    cat > "$state_dir/config" << EOF
# $installer_label, $(date -Iseconds)
DEMO_NAME=$inst_name
DEMO_ROOT=$DEMO_ROOT
DEMO_RELEASE_DOMAIN=$DEMO_RELEASE_DOMAIN
DEMO_NEXT_DOMAIN=$DEMO_NEXT_DOMAIN
DEMO_TLS=$tls_mode
NGINX_CONF=$nginx_conf
RELEASE_TAG=$release_tag
EOF
    chmod 0600 "$state_dir/config"
}

check_instances() {
    step "Prüfe die Instanzen"
    local i
    for i in "${!instances[@]}"; do
        if [[ "$(http_status "${domains[i]}" /login)" == 200 ]]; then
            info "${instances[i]}: Anmeldeseite antwortet."
        else
            warn "${instances[i]}: Die Anmeldeseite antwortet nicht. Logs: $DEMO_ROOT/${instances[i]}/shared/storage/logs/"
        fi
    done
}

print_result() {
    local ssh_port=""
    [[ ${DEMO_SSH_PORT:-22} == 22 ]] || ssh_port=" -p $DEMO_SSH_PORT"
    printf '\n%sDie Demo ist eingerichtet.%s\n\n' "$c_green" "$c_off"
    info "release:  https://$DEMO_RELEASE_DOMAIN   ($release_tag)"
    info "next:     https://$DEMO_NEXT_DOMAIN   (vorerst ebenfalls $release_tag)"
    info "Passwort der Demo-Konten: $DEMO_PASSWORD (steht auf der Anmeldeseite)"
    info "Demo-Postfach: /demo/postfach"
    echo
    info "Entfernen:  sudo bash install-demo-server.sh --uninstall"
    info "Logs:       $DEMO_ROOT/<instanz>/shared/storage/logs/, journalctl -u '$inst_name-*'"

    if $github_deploy; then
        cat << EOF

${c_blue}==> GitHub Actions verbinden${c_off}
    Auf einem Rechner mit angemeldeter GitHub-CLI (gh) ausführen. Der private
    Schlüssel geht dabei direkt vom Server zu GitHub und wird danach gelöscht.

REPO=$repo
gh api -X PUT "repos/\$REPO/environments/demo" > /dev/null
ssh$ssh_port $key_owner@$DEMO_SSH_HOST 'cat $deploy_key' | gh secret set DEMO_SSH_KEY --env demo -R "\$REPO"
gh secret set DEMO_SSH_KNOWN_HOSTS --env demo -R "\$REPO" --body '$known_hosts'
gh variable set DEMO_SSH_TARGET -R "\$REPO" --body '$ssh_target'
gh variable set DEMO_DEPLOY -R "\$REPO" --body true
ssh$ssh_port $key_owner@$DEMO_SSH_HOST 'shred -u $deploy_key $deploy_key.pub'
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

    begin_install
    run_tasks install_packages open_firewall_ports fetch_demo_release \
        create_user_and_directories write_env_files install_deploy_script \
        configure_php_fpm configure_nginx deploy_instances install_units \
        setup_deploy_key save_state
    check_instances
    print_result
}

# --- Uninstall ---------------------------------------------------------------

uninstall_demo() {
    open_terminal
    [[ $EUID -eq 0 ]] || die "Bitte mit sudo oder als root ausführen."
    select_install DEMO Demo

    step "Demo '$inst_name' entfernen"
    [[ -f $state_dir/config ]] && sed -n 's/^\(DEMO_[A-Z_]*\)=/    \1: /p' "$state_dir/config"
    [[ -f $state_dir/config ]] || warn "Die Installation wurde nicht abgeschlossen; es wird entfernt, was angelegt wurde."
    echo
    info "Entfernt werden Benutzer, Verzeichnisse mit allen Demo-Daten, Nginx-Site,"
    info "PHP-FPM-Pool, Dienste, Deploy-Skript, SSH-Freigabe und das Let's-Encrypt-Zertifikat der Demo."
    confirm "Demo '$inst_name' vollständig entfernen?" "$($assume_yes && echo y || echo n)" || exit 1

    local kinds="core data"
    if ask_remove_packages DEMO_UNINSTALL_PACKAGES; then
        kinds+=" pkg repo"
    fi
    undo_journal "$kinds"
    if ((undo_failures == 0)); then
        printf '\n%sDemo %s entfernt.%s\n' "$c_green" "$inst_name" "$c_off"
    else
        printf '\n%sDemo %s nur teilweise entfernt: %s Schritte sind fehlgeschlagen (siehe oben).%s\n' \
            "$c_red" "$inst_name" "$undo_failures" "$c_off"
    fi
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
