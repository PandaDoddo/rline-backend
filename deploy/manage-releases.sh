#!/bin/sh
set -eu

release_id() {
    case "$1" in ''|*[!a-zA-Z0-9_-]*) echo 'Invalid release id' >&2; exit 1;; esac
    test "${#1}" -le 80
}
stand_id() {
    case "$1" in main|testing|demo) ;; *) echo 'Unknown stand' >&2; exit 1;; esac
}
mkdir -p /srv/releases /srv/stands
case "${1:-status}" in
    publish)
        release_id "${2:-}"
        release=$2
        test -f /incoming/index.html
        # The Vite base must match the immutable release location.
        grep -q "/releases/$release/" /incoming/index.html
        # mkdir is also an exclusive publication lock; existing releases are never replaced.
        mkdir "/srv/releases/$release"
        trap 'rm -rf "/srv/releases/$release"' EXIT HUP INT TERM
        cp -R /incoming/. "/srv/releases/$release/"
        printf '{"release":"%s","built_at":"%s"}\n' "$release" "$(date -u +%FT%TZ)" > "/srv/releases/$release/release.json"
        trap - EXIT HUP INT TERM
        echo "Published $release"
        ;;
    promote)
        stand_id "${2:-}"
        release_id "${3:-}"
        test -f "/srv/releases/$3/index.html"
        # Rename in the same filesystem atomically replaces the link, without a missing-root window.
        temporary="/srv/stands/.${2}.$$"
        trap 'rm -f "$temporary"' EXIT HUP INT TERM
        ln -s "../releases/$3" "$temporary"
        mv -Tf "$temporary" "/srv/stands/$2"
        trap - EXIT HUP INT TERM
        echo "$2 -> $3"
        ;;
    status)
        for stand in main testing demo; do
            printf '%s -> %s\n' "$stand" "$(readlink "/srv/stands/$stand" || echo unpublished)"
        done
        ;;
    *) echo 'Usage: publish RELEASE | promote STAND RELEASE | status' >&2; exit 1;;
esac
