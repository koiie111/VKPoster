#!/bin/sh
# Bounded retries for upstream PECL download failures; never omit an extension.
set -eu
extension_dir="$(php-config --extension-dir)"
for package in "$@"; do
    module="${package%%-*}"
    installed=0
    for attempt in 1 2 3; do
        if pecl install "$package" && [ -s "$extension_dir/$module.so" ]; then
            installed=1
            break
        fi
        if [ "$attempt" -lt 3 ]; then sleep "$((attempt * 2))"; fi
    done
    if [ "$installed" != 1 ]; then
        echo "Required PECL extension unavailable after bounded retries: $module" >&2
        exit 1
    fi
done
