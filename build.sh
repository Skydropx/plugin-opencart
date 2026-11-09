#!/bin/bash

# Skydropx OpenCart extension builder
# Produces dist/skydropx.ocmod.zip for Extensions > Installer (OpenCart 4.0.2.x / 4.1.x).

set -euo pipefail

readonly SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
readonly SOURCE_DIR="$SCRIPT_DIR/src"
readonly DIST_DIR="$SCRIPT_DIR/dist"
readonly TEMP_DIR="$SCRIPT_DIR/.tmp-build"
# OpenCart 4 derives the extension folder from the file name: it must be <code>.ocmod.zip.
readonly EXTENSION_CODE="skydropx"
readonly ARCHIVE="$DIST_DIR/$EXTENSION_CODE.ocmod.zip"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m'

readonly DEPENDENCIES=("jq" "zip")

log() {
    local type=$1
    local message=$2
    case $type in
        "error") echo -e "${RED}✗ $message${NC}" >&2 ;;
        "success") echo -e "${GREEN}✓ $message${NC}" ;;
        "info") echo -e "${BLUE}ℹ $message${NC}" ;;
        *) echo "$message" ;;
    esac
}

cleanup() {
    if [[ -d "$TEMP_DIR" ]]; then
        rm -rf "$TEMP_DIR"
    fi
}
trap cleanup EXIT

check_dependencies() {
    local missing=()
    for dep in "${DEPENDENCIES[@]}"; do
        if ! command -v "$dep" >/dev/null 2>&1; then
            missing+=("$dep")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log "error" "Missing dependencies: ${missing[*]}"
        exit 1
    fi
}

validate_source() {
    if [[ ! -f "$SOURCE_DIR/install.json" ]]; then
        log "error" "src/install.json not found"
        exit 1
    fi

    local code
    code=$(jq -r '.code // empty' "$SOURCE_DIR/install.json")

    if [[ "$code" != "$EXTENSION_CODE" ]]; then
        log "error" "install.json code is '$code', expected '$EXTENSION_CODE'"
        exit 1
    fi

    for dir in admin catalog; do
        if [[ ! -d "$SOURCE_DIR/$dir" ]]; then
            log "error" "src/$dir is missing"
            exit 1
        fi
    done
}

lint_php() {
    if ! command -v php >/dev/null 2>&1; then
        log "info" "php not found, skipping lint"
        return
    fi

    local failed=0
    while IFS= read -r -d '' file; do
        if ! php -l "$file" >/dev/null 2>&1; then
            log "error" "Syntax error in ${file#"$SCRIPT_DIR"/}"
            failed=1
        fi
    done < <(find "$SOURCE_DIR" -name '*.php' -print0)

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    log "success" "PHP lint passed"
}

build() {
    local version
    version=$(jq -r '.version' "$SOURCE_DIR/install.json")

    log "info" "Building $EXTENSION_CODE $version"

    cleanup
    mkdir -p "$TEMP_DIR" "$DIST_DIR"
    cp -R "$SOURCE_DIR"/. "$TEMP_DIR"/
    find "$TEMP_DIR" -name '.DS_Store' -delete

    rm -f "$ARCHIVE"
    # Zip the contents (install.json at the root), not the src folder itself.
    (cd "$TEMP_DIR" && zip -qrX "$ARCHIVE" install.json admin catalog)

    log "success" "Created ${ARCHIVE#"$SCRIPT_DIR"/} ($(du -h "$ARCHIVE" | cut -f1))"
}

main() {
    check_dependencies
    validate_source
    lint_php
    build
}

main "$@"
