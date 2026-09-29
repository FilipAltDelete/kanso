#!/bin/sh
# Renders PROXY_TRUSTED_PROXIES into the Nginx configuration at every start
# (main.conf includes the result). These are the peers this proxy believes
# about the client's address and scheme, such as a TLS terminator in front of
# it. Leave it empty when this proxy is the first thing a browser reaches.
# ADR-0018.
#
#   PROXY_TRUSTED_PROXIES=10.0.0.5,192.168.10.0/24   addresses or CIDR ranges
#   PROXY_TRUSTED_PROXIES=PRIVATE_SUBNETS            every private range
#
# Run by the stock image's /docker-entrypoint.sh; a bad entry stops the start.
set -euf

out=/tmp/kanso-trusted-proxies.conf
nets=
for entry in $(printf '%s' "${PROXY_TRUSTED_PROXIES:-}" | tr ',' ' '); do
    case "$entry" in
        PRIVATE_SUBNETS) nets="$nets 127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 ::1/128 fc00::/7" ;;
        *[!0-9A-Fa-f.:/]*)
            echo "$0: PROXY_TRUSTED_PROXIES: '$entry' is not an address or a CIDR range" >&2
            exit 1 ;;
        *) nets="$nets $entry" ;;
    esac
done

{
    echo "# From PROXY_TRUSTED_PROXIES=\"${PROXY_TRUSTED_PROXIES:-}\" by $0."
    echo 'geo $realip_remote_addr $kanso_trusted_peer {'
    echo '    default 0;'
    for net in $nets; do echo "    $net 1;"; done
    echo '}'
    for net in $nets; do echo "set_real_ip_from $net;"; done
} > "$out"
