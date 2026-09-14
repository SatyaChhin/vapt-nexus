#!/usr/bin/env bash
#
# setup-lab-interface.sh - configure and verify the VMware host-only (VAPT lab)
# interface on a Linux VM (Kali, Nessus or Target) without touching the NAT uplink.
#
# Usage:
#   ./setup-lab-interface.sh inspect                    read-only network report
#   sudo ./setup-lab-interface.sh apply --role kali     static lab IP, no gateway
#   ./setup-lab-interface.sh verify --role kali         PASS / WARN / FAIL checks
#
# Options:
#   --role kali|nessus|target   use the planned lab IP (.10 / .20 / .30)
#   --ip ADDRESS                explicit lab IP (overrides --role)
#   --iface NAME                host-only interface (default: auto-detect)
#   --mac MAC                   pick the interface by MAC (VM Settings > adapter > Advanced)
#   -y, --yes                   skip the confirmation prompt
#
# Lab plan (/24, override via environment):
#   LAB_NET=192.168.56  LAB_HOST_IP=.1  LAB_KALI_IP=.10  LAB_NESSUS_IP=.20  LAB_TARGET_IP=.30
#   Nessus installed on Kali itself: LAB_NESSUS_IP=192.168.56.10
#
# Backends: NetworkManager (nmcli) when it manages the interface, otherwise netplan.
# The interface that carries the default route (NAT) is never modified.
# Use only on the authorized, isolated VAPT lab network.

set -euo pipefail

LAB_NET="${LAB_NET:-192.168.56}"
LAB_PREFIX=24
LAB_HOST_IP="${LAB_HOST_IP:-${LAB_NET}.1}"
LAB_KALI_IP="${LAB_KALI_IP:-${LAB_NET}.10}"
LAB_NESSUS_IP="${LAB_NESSUS_IP:-${LAB_NET}.20}"
LAB_TARGET_IP="${LAB_TARGET_IP:-${LAB_NET}.30}"
NESSUS_PORT="${NESSUS_PORT:-8834}"
CON_NAME="${CON_NAME:-vapt-hostonly}"
NETPLAN_FILE="${NETPLAN_FILE:-/etc/netplan/60-vapt-hostonly.yaml}"

if [[ -t 1 ]]; then
    C_OK=$'\e[32m' C_BAD=$'\e[31m' C_WARN=$'\e[33m' C_HEAD=$'\e[36m' C_OFF=$'\e[0m'
else
    C_OK='' C_BAD='' C_WARN='' C_HEAD='' C_OFF=''
fi

MODE="" ROLE="" LAB_IP="" IFACE="" MAC="" ASSUME_YES=0
PASS=0 WARN=0 FAIL=0
NESSUS_HTTP="" NESSUS_BODY=""

# ---------------------------------------------------------------- output

usage()   { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; }
die()     { echo "${C_BAD}ERROR:${C_OFF} $*" >&2; exit 1; }
info()    { echo "${C_HEAD}==>${C_OFF} $*"; }
section() { echo; echo "${C_HEAD}[$*]${C_OFF}"; }
ok()      { echo "  ${C_OK}PASS${C_OFF}  $*"; PASS=$((PASS + 1)); }
warn()    { echo "  ${C_WARN}WARN${C_OFF}  $*"; WARN=$((WARN + 1)); }
bad()     { echo "  ${C_BAD}FAIL${C_OFF}  $*"; FAIL=$((FAIL + 1)); }

summary() {
    echo
    echo "Result: ${PASS} passed, ${WARN} warnings, ${FAIL} failed"
    if [[ $FAIL -gt 0 ]]; then exit 1; fi
    exit 0
}

# ---------------------------------------------------------------- helpers

# Awk programs avoid early "exit" so pipefail never sees SIGPIPE.
dev_of_route() { awk '!f { for (i = 1; i < NF; i++) if ($i == "dev") { print $(i + 1); f = 1 } }'; }
default_iface() { ip -4 route show default 2>/dev/null | dev_of_route || true; }
route_dev()     { ip -4 route get "$1" 2>/dev/null | dev_of_route || true; }
iface_mac()     { cat "/sys/class/net/$1/address" 2>/dev/null || true; }
iface_ipv4()    { ip -4 -o addr show dev "$1" 2>/dev/null | awk '{ print $4 }' || true; }
flat()          { tr '\n' ' ' | sed 's/ *$//'; }

# IPv4 addresses on the interface that are NOT inside the lab subnet.
foreign_ipv4() { iface_ipv4 "$1" | awk -v net="${LAB_NET}." 'index($0, net) != 1' || true; }

# Real NICs have a backing device; docker0, veth*, br-*, tun* do not.
is_physical_ether() {
    [[ -e "/sys/class/net/$1/device" && "$(cat "/sys/class/net/$1/type" 2>/dev/null)" == 1 ]]
}

nessus_installed_here() { command -v systemctl >/dev/null 2>&1 && systemctl cat nessusd >/dev/null 2>&1; }

candidate_ifaces() {
    local def n
    def="$(default_iface)"
    for n in /sys/class/net/*; do
        n="${n##*/}"
        [[ "$n" == lo || "$n" == "$def" ]] && continue
        is_physical_ether "$n" || continue
        [[ -z "$(foreign_ipv4 "$n")" ]] || continue
        echo "$n"
    done
}

resolve_ip() {
    case "$ROLE" in
        ''|kali|nessus|target) ;;
        *) die "Unknown role '$ROLE' (use kali, nessus or target)" ;;
    esac
    if [[ -z "$LAB_IP" ]]; then
        case "$ROLE" in
            kali)   LAB_IP="$LAB_KALI_IP" ;;
            nessus) LAB_IP="$LAB_NESSUS_IP" ;;
            target) LAB_IP="$LAB_TARGET_IP" ;;
            *)      die "Specify --role kali|nessus|target (or --ip ADDRESS)" ;;
        esac
    elif [[ -z "$ROLE" ]]; then
        case "$LAB_IP" in
            "$LAB_KALI_IP")   ROLE=kali ;;
            "$LAB_NESSUS_IP") ROLE=nessus ;;
            "$LAB_TARGET_IP") ROLE=target ;;
            *)                ROLE=other ;;
        esac
    fi
    local last="${LAB_IP##*.}"
    [[ "${LAB_IP%.*}" == "$LAB_NET" && "$last" =~ ^[0-9]{1,3}$ ]] \
        || die "$LAB_IP is not inside ${LAB_NET}.0/${LAB_PREFIX}"
    (( 10#$last >= 2 && 10#$last <= 254 )) \
        || die "$LAB_IP: last octet must be 2-254 (.1 is the Windows host adapter)"
}

pick_iface() {
    local n candidates=()
    if [[ -n "$IFACE" ]]; then
        [[ -e "/sys/class/net/$IFACE" ]] || die "Interface '$IFACE' does not exist (see: ip -br link)"
    elif [[ -n "$MAC" ]]; then
        for n in /sys/class/net/*; do
            if [[ "$(iface_mac "${n##*/}")" == "$MAC" ]]; then IFACE="${n##*/}"; break; fi
        done
        [[ -n "$IFACE" ]] || die "No interface has MAC $MAC (see: ip -br link)"
    else
        mapfile -t candidates < <(candidate_ifaces)
        case "${#candidates[@]}" in
            0) die "No unconfigured Ethernet interface found. Add a VMnet1 (host-only) adapter in VM Settings, then run: $0 inspect" ;;
            1) IFACE="${candidates[0]}" ;;
            *) die "Several candidate interfaces: ${candidates[*]}. Re-run with --iface NAME or --mac MAC" ;;
        esac
    fi
    [[ "$IFACE" != "$(default_iface)" ]] \
        || die "$IFACE carries the default route (NAT / Internet). Refusing to modify it."
    is_physical_ether "$IFACE" \
        || die "$IFACE is not a physical Ethernet adapter (docker/bridge/virtual interfaces are not the lab network)"
    [[ -z "$(foreign_ipv4 "$IFACE")" ]] \
        || die "$IFACE already has non-lab addresses ($(foreign_ipv4 "$IFACE" | flat)). Refusing to modify it."
}

nm_manages() {
    command -v nmcli >/dev/null 2>&1 || return 1
    [[ "$(nmcli -t -f RUNNING general 2>/dev/null || true)" == running ]] || return 1
    local state
    state="$(nmcli -g GENERAL.STATE device show "$1" 2>/dev/null || true)"
    [[ -n "$state" && "$state" != *unmanaged* ]]
}

confirm() {
    if [[ $ASSUME_YES -eq 1 ]]; then return 0; fi
    local answer=""
    read -r -p "Apply this change? [y/N] " answer || true
    [[ "$answer" == [yY] ]] || die "Aborted, nothing was changed."
}

backup_state() {
    local file f
    file="/var/tmp/vapt-lab-backup-$(date +%Y%m%d-%H%M%S).txt"
    (
        umask 077
        {
            echo "# $(date -Is) $(hostname)"
            echo; ip addr
            echo; ip route
            if command -v nmcli >/dev/null 2>&1; then
                echo; nmcli device status || true
                echo; nmcli -f NAME,UUID,TYPE,DEVICE,AUTOCONNECT connection show || true
            fi
            for f in /etc/netplan/*.yaml; do
                if [[ -f "$f" ]]; then echo; echo "## $f"; cat "$f"; fi
            done
        } >"$file" 2>&1
    )
    info "Saved the current network state to $file"
}

# ---------------------------------------------------------------- apply backends

apply_nm() {
    local def def_uuid our_uuid mac uuid type bound bound_mac active name
    def="$(default_iface)"
    def_uuid=""
    if [[ -n "$def" ]]; then
        def_uuid="$(nmcli -g GENERAL.CON-UUID device show "$def" 2>/dev/null || true)"
    fi
    mac="$(iface_mac "$IFACE")"

    if nmcli -g connection.id connection show "$CON_NAME" >/dev/null 2>&1; then
        info "Updating existing NetworkManager profile '$CON_NAME'"
    else
        info "Creating NetworkManager profile '$CON_NAME' on $IFACE"
        nmcli connection add type ethernet ifname "$IFACE" con-name "$CON_NAME" \
            ipv4.method manual ipv4.addresses "$LAB_IP/$LAB_PREFIX" >/dev/null
    fi

    # Static lab address only: no gateway, no DNS, never the default route.
    # dad-timeout makes activation fail if another VM already uses the address.
    nmcli connection modify "$CON_NAME" \
        connection.interface-name "$IFACE" \
        connection.autoconnect yes \
        connection.autoconnect-priority 50 \
        ipv4.method manual \
        ipv4.addresses "$LAB_IP/$LAB_PREFIX" \
        ipv4.gateway "" \
        ipv4.dns "" \
        ipv4.ignore-auto-dns yes \
        ipv4.ignore-auto-routes yes \
        ipv4.never-default yes \
        ipv4.dad-timeout 3000
    nmcli connection modify "$CON_NAME" ipv6.method disabled 2>/dev/null \
        || nmcli connection modify "$CON_NAME" ipv6.method ignore
    our_uuid="$(nmcli -g connection.uuid connection show "$CON_NAME")"

    # Stop other Ethernet profiles from grabbing the lab interface at boot.
    # They are only set to autoconnect=no (never deleted); the NAT profile is skipped.
    while IFS=: read -r uuid type; do
        [[ "$type" == 802-3-ethernet || "$type" == ethernet ]] || continue
        [[ "$uuid" == "$our_uuid" || "$uuid" == "$def_uuid" ]] && continue
        bound="$(nmcli -g connection.interface-name connection show "$uuid" 2>/dev/null || true)"
        bound_mac="$(nmcli -g 802-3-ethernet.mac-address connection show "$uuid" 2>/dev/null || true)"
        bound_mac="${bound_mac//\\/}"
        active="$(nmcli -g GENERAL.DEVICES connection show "$uuid" 2>/dev/null || true)"
        if [[ "$bound" == "$IFACE" || "$active" == "$IFACE" || ( -n "$bound_mac" && "${bound_mac,,}" == "$mac" ) ]]; then
            name="$(nmcli -g connection.id connection show "$uuid")"
            info "Disabling autoconnect on competing profile '$name' (kept, not deleted)"
            nmcli connection modify "$uuid" connection.autoconnect no
        fi
    done < <(nmcli -g UUID,TYPE connection show)

    info "Activating '$CON_NAME' on $IFACE"
    nmcli connection up "$CON_NAME" ifname "$IFACE" >/dev/null \
        || die "Activation failed. $LAB_IP may already be in use on the lab network; see: journalctl -u NetworkManager -n 50"
}

apply_netplan() {
    command -v netplan >/dev/null 2>&1 \
        || die "Neither NetworkManager nor netplan manages $IFACE. Configure $LAB_IP/$LAB_PREFIX on $IFACE manually, without a gateway."
    if grep -ls -- "$IFACE" /etc/netplan/*.yaml 2>/dev/null | grep -vFx "$NETPLAN_FILE"; then
        warn "The netplan files above also mention $IFACE; $NETPLAN_FILE is read last and overrides them."
    fi
    info "Writing $NETPLAN_FILE"
    (
        umask 077
        cat >"$NETPLAN_FILE" <<EOF
# Managed by vapt-nexus infra/lab/linux/setup-lab-interface.sh
# VMware host-only VAPT lab interface: static address, no gateway, no DNS.
network:
  version: 2
  ethernets:
    ${IFACE}:
      dhcp4: false
      dhcp6: false
      accept-ra: false
      link-local: []
      addresses:
        - ${LAB_IP}/${LAB_PREFIX}
EOF
    )
    netplan generate
    info "Applying netplan (interfaces may bounce for a second)"
    netplan apply
}

# ---------------------------------------------------------------- verify helpers

tcp_open() { timeout 3 bash -c "exec 3<>/dev/tcp/$1/$2" >/dev/null 2>&1; }

check_ping() { # name address fail-level [hint]
    if ping -c 2 -W 2 "$2" >/dev/null 2>&1; then
        ok "ping $1 ($2)"
    else
        "$3" "no ping reply from $1 ($2) - ${4:-VM off, lab IP not set, or ICMP blocked}"
    fi
}

check_nessus_http() { # address
    local out re='"status"[[:space:]]*:[[:space:]]*"ready"'
    if ! command -v curl >/dev/null 2>&1; then
        warn "curl not installed; skipped the HTTPS check"
        return 0
    fi
    # Documented, unauthenticated Nessus endpoint; -k because the lab certificate is self-signed.
    out="$(curl -k -s --max-time 8 -w $'\n%{http_code}' "https://$1:${NESSUS_PORT}/server/status" 2>/dev/null || true)"
    NESSUS_HTTP="${out##*$'\n'}"
    NESSUS_BODY="${out%$'\n'*}"
    if [[ "$NESSUS_HTTP" == 200 ]]; then
        ok "GET https://$1:${NESSUS_PORT}/server/status -> HTTP 200"
        if [[ "$NESSUS_BODY" =~ $re ]]; then
            ok "Nessus reports status \"ready\""
        else
            warn "Nessus is up but not ready yet (plugins may still be loading): ${NESSUS_BODY:0:160}"
        fi
    else
        bad "GET https://$1:${NESSUS_PORT}/server/status -> HTTP ${NESSUS_HTTP:-no response}"
    fi
}

check_nessus_local() {
    local listen re ufw_out
    if systemctl is-active --quiet nessusd; then
        ok "nessusd service is active"
    else
        bad "nessusd is not active (sudo systemctl enable --now nessusd)"
    fi

    listen="$(ss -H -tln "sport = :$NESSUS_PORT" 2>/dev/null | awk '{ print $4 }' | flat || true)"
    re="(^| )(0\.0\.0\.0|\*|\[::\]):${NESSUS_PORT}( |$)"
    if [[ -z "$listen" ]]; then
        bad "nothing listens on TCP $NESSUS_PORT"
    elif [[ "$listen" =~ $re || " $listen " == *" $LAB_IP:$NESSUS_PORT "* ]]; then
        ok "listening on $listen"
    else
        bad "listening only on $listen - not reachable from the lab"
    fi

    if tcp_open "$LAB_IP" "$NESSUS_PORT"; then
        check_nessus_http "$LAB_IP"
    fi

    if command -v ufw >/dev/null 2>&1; then
        if [[ $EUID -ne 0 ]]; then
            warn "ufw is installed; re-run verify with sudo to inspect its rules"
        else
            ufw_out="$(ufw status 2>/dev/null || true)"
            if [[ "$ufw_out" != *"Status: active"* ]]; then
                ok "ufw is inactive"
            elif [[ "$ufw_out" == *"$NESSUS_PORT"* ]]; then
                ok "ufw has a rule for port $NESSUS_PORT"
            else
                bad "ufw is active without a rule for $NESSUS_PORT. Fix: sudo ufw allow from ${LAB_NET}.0/${LAB_PREFIX} to any port $NESSUS_PORT proto tcp"
            fi
        fi
    fi
    if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
        warn "firewalld is active; make sure $NESSUS_PORT/tcp is allowed from ${LAB_NET}.0/${LAB_PREFIX}"
    fi
}

check_nessus_remote() {
    if tcp_open "$LAB_NESSUS_IP" "$NESSUS_PORT"; then
        ok "Nessus TCP $LAB_NESSUS_IP:$NESSUS_PORT is open"
        check_nessus_http "$LAB_NESSUS_IP"
    else
        bad "Nessus TCP $LAB_NESSUS_IP:$NESSUS_PORT is not reachable"
    fi
}

# ---------------------------------------------------------------- commands

cmd_inspect() {
    local def n candidates=()
    section "Links (ip -br link)"; ip -br link
    section "Addresses (ip -br addr)"; ip -br addr
    section "Routes (ip route)"; ip route
    if command -v nmcli >/dev/null 2>&1; then
        section "NetworkManager devices"; nmcli device status || true
        section "NetworkManager profiles"; nmcli -f NAME,UUID,TYPE,DEVICE,AUTOCONNECT connection show || true
    fi

    def="$(default_iface)"
    mapfile -t candidates < <(candidate_ifaces)
    section "Analysis"
    echo "  Default-route (NAT) interface: ${def:-none}   <- never modified by this script"
    if [[ ${#candidates[@]} -eq 0 ]]; then
        echo "  Host-only candidate: none -> add a VMnet1 adapter (VM Settings > Add > Network Adapter > Custom: VMnet1)"
    fi
    for n in "${candidates[@]}"; do
        echo "  Host-only candidate: $n  MAC $(iface_mac "$n")  driver $(basename "$(readlink -f "/sys/class/net/$n/device/driver")")  state $(cat "/sys/class/net/$n/operstate")  IPv4 $(iface_ipv4 "$n" | flat)"
    done
    if nessus_installed_here; then
        echo "  Nessus: installed on this host (nessusd $(systemctl is-active nessusd 2>/dev/null || true))"
    fi
    echo "  Compare the MAC with VM Settings > Network Adapter 2 > Advanced before running apply."
}

cmd_apply() {
    [[ $EUID -eq 0 ]] || die "apply changes the network configuration; run it with sudo"
    resolve_ip
    pick_iface
    local def default_before backend
    def="$(default_iface)"
    default_before="$(ip -4 route show default)"
    if nm_manages "$IFACE"; then backend=NetworkManager; else backend=netplan; fi

    echo "Plan"
    echo "  interface    : $IFACE (MAC $(iface_mac "$IFACE"))"
    echo "  lab address  : $LAB_IP/$LAB_PREFIX - static, no gateway, no DNS, never default route"
    echo "  backend      : $backend"
    echo "  not modified : ${def:-<no default route>} (default route / NAT uplink)"
    confirm
    backup_state
    if [[ "$backend" == NetworkManager ]]; then apply_nm; else apply_netplan; fi

    sleep 3
    if [[ "$(ip -4 route show default)" != "$default_before" ]]; then
        warn "The default route changed. Before: ${default_before:-none} | Now: $(ip -4 route show default | flat)"
    fi
    echo
    cmd_verify
}

cmd_verify() {
    resolve_ip
    local lab_if def level routes defaults
    lab_if="$(ip -4 -o addr show 2>/dev/null \
        | awk -v ip="$LAB_IP" '!f { split($4, a, "/"); if (a[1] == ip) { print $2; f = 1 } }' || true)"
    def="$(default_iface)"
    echo "Verifying role '${ROLE}' (${LAB_IP}) on $(hostname)"

    section "Lab interface"
    if [[ -z "$lab_if" ]]; then
        bad "$LAB_IP is not assigned to any interface (run: sudo $0 apply --role $ROLE)"
        summary
    fi
    ok "$LAB_IP is assigned to $lab_if"
    if [[ " $(iface_ipv4 "$lab_if" | flat) " == *" $LAB_IP/$LAB_PREFIX "* ]]; then
        ok "prefix is /$LAB_PREFIX"
    else
        bad "expected $LAB_IP/$LAB_PREFIX, found: $(iface_ipv4 "$lab_if" | flat)"
    fi
    if [[ -z "$(ip -4 route show default dev "$lab_if" 2>/dev/null)" ]]; then
        ok "no default route via $lab_if"
    else
        bad "default route via $lab_if - the host-only interface must not have a gateway"
    fi
    routes="$(ip -4 route show "${LAB_NET}.0/${LAB_PREFIX}" 2>/dev/null | flat) "
    if [[ "$routes" == *"dev $lab_if "* ]]; then
        ok "${LAB_NET}.0/${LAB_PREFIX} is on-link via $lab_if"
    else
        bad "no ${LAB_NET}.0/${LAB_PREFIX} route on $lab_if"
    fi
    if [[ "$(route_dev "$LAB_HOST_IP")" == "$lab_if" ]]; then
        ok "lab traffic (to $LAB_HOST_IP) leaves via $lab_if"
    else
        bad "lab traffic routes via '$(route_dev "$LAB_HOST_IP")' instead of $lab_if"
    fi

    section "Internet (NAT uplink)"
    if [[ "$ROLE" == target ]]; then
        if [[ -z "$def" ]]; then
            ok "no default route - the target is isolated on the lab network"
        else
            warn "the target has a default route via $def; keeping it host-only (isolated) is recommended"
        fi
    else
        level=bad
        [[ "$ROLE" == kali ]] || level=warn
        if [[ -z "$def" ]]; then
            "$level" "no default route (is the NAT adapter connected?)"
        else
            defaults="$(ip -4 route show default | wc -l)"
            if [[ "$def" == "$lab_if" ]]; then
                bad "the default route uses the lab interface $lab_if"
            elif [[ $defaults -gt 1 ]]; then
                warn "$defaults default routes: $(ip -4 route show default | flat)"
            else
                ok "single default route: $(ip -4 route show default | flat)"
            fi
            if [[ "$(route_dev 8.8.8.8)" != "$lab_if" ]]; then
                ok "Internet traffic leaves via $(route_dev 8.8.8.8)"
            else
                bad "Internet traffic would leave via the lab interface $lab_if"
            fi
            if ping -c 2 -W 2 8.8.8.8 >/dev/null 2>&1; then ok "ping 8.8.8.8"; else "$level" "ping 8.8.8.8 failed"; fi
            if getent hosts google.com >/dev/null 2>&1; then ok "DNS resolves google.com"; else "$level" "DNS lookup of google.com failed"; fi
            if ping -c 2 -W 2 google.com >/dev/null 2>&1; then ok "ping google.com"; else "$level" "ping google.com failed"; fi
        fi
    fi

    section "Lab peers"
    check_ping "Windows host" "$LAB_HOST_IP" warn "Windows Firewall blocks ping on VMware adapters by default; not required"
    case "$ROLE" in
        kali)
            check_ping "Target" "$LAB_TARGET_IP" bad
            if [[ "$LAB_NESSUS_IP" != "$LAB_IP" ]]; then
                check_ping "Nessus" "$LAB_NESSUS_IP" bad
                check_nessus_remote
            fi
            ;;
        nessus)
            check_ping "Target" "$LAB_TARGET_IP" bad
            ;;
        *)
            check_ping "Nessus" "$LAB_NESSUS_IP" warn
            ;;
    esac

    if [[ "$ROLE" == nessus || "$LAB_NESSUS_IP" == "$LAB_IP" ]] || { [[ "$ROLE" == kali ]] && nessus_installed_here; }; then
        section "Nessus service (this host)"
        if nessus_installed_here; then
            check_nessus_local
        else
            bad "nessusd is not installed on this host"
        fi
    fi

    summary
}

# ---------------------------------------------------------------- main

if [[ $# -gt 0 ]]; then MODE="$1"; shift; fi
while [[ $# -gt 0 ]]; do
    case "$1" in
        --role|--ip|--iface|--mac)
            [[ $# -ge 2 ]] || die "$1 needs a value"
            case "$1" in
                --role)  ROLE="$2" ;;
                --ip)    LAB_IP="$2" ;;
                --iface) IFACE="$2" ;;
                --mac)   MAC="$(tr 'A-F-' 'a-f:' <<<"$2")" ;;
            esac
            shift 2
            ;;
        -y|--yes)  ASSUME_YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *)         die "Unknown option: $1 (see --help)" ;;
    esac
done

case "$MODE" in
    inspect)           cmd_inspect ;;
    apply)             cmd_apply ;;
    verify)            cmd_verify ;;
    ''|help|-h|--help) usage ;;
    *)                 die "Unknown mode '$MODE' (inspect | apply | verify)" ;;
esac
