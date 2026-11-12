#!/bin/bash

# OpenCart Extension Builder
# Asks for brand, version and environment, fills the plugin-source/ placeholders, renames the
# PLUGIN_SLUG files and writes builds/<plugin_slug>-v<version>-<environment>/<code>.ocmod.zip
# for Extensions > Installer (OpenCart 4.0.2.x / 4.1.x). The extension code is per brand:
# plugin_slug with "-" -> "_" (skydropx, solo_envios), so solo-envios gives
# builds/solo-envios-v1.0.0-production/solo_envios.ocmod.zip.
# Answers can be piped: printf '1\n1.0.0\n2\nn\n' | ./build.sh

set -euo pipefail

readonly SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
readonly SOURCE_DIR="$SCRIPT_DIR/plugin-source"
readonly BRANDING_DIR="$SCRIPT_DIR/branding"
readonly BUILD_DIR="$SCRIPT_DIR/builds"
readonly TEMP_DIR="$SCRIPT_DIR/.tmp-build"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly PURPLE='\033[0;35m'
readonly NC='\033[0m'

readonly DEPENDENCIES=("jq" "rsync" "zip")
readonly SUPPORTED_ENVIRONMENTS=("local" "sandbox" "production")

readonly SLUG_PATTERN='^[a-z0-9]+(-[a-z0-9]+)*$'
readonly URL_PATTERN='^https?://'

# === UTILITY FUNCTIONS ===

log() {
    local type=$1
    local message=$2
    case $type in
        "error") echo -e "${RED}✗ $message${NC}" >&2 ;;
        "warning") echo -e "${YELLOW}⚠ $message${NC}" >&2 ;;
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
    local dep
    for dep in "${DEPENDENCIES[@]}"; do
        if ! command -v "$dep" >/dev/null 2>&1; then
            missing+=("$dep")
        fi
    done

    if [[ ${#missing[@]} -ne 0 ]]; then
        log "error" "Missing dependencies: ${missing[*]}"
        return 1
    fi

    if ! command -v php >/dev/null 2>&1; then
        log "warning" "php not found, PHP lint will be skipped"
    fi
}

# === CONFIGURATION MANAGEMENT ===

# Prints a string value of config.json by dotted key (e.g. urls.sandbox.app).
config_value() {
    local config_file=$1
    local key=$2
    jq -r --arg key "$key" 'getpath($key | split("."))' "$config_file"
}

# Rejects a config.json that would produce a broken package. Runs before anything is copied.
validate_config() {
    local config_file=$1
    local environment=$2
    local config_label="${config_file#"$SCRIPT_DIR"/}"
    local failed=0
    local key status value

    if ! jq -e 'type == "object"' "$config_file" >/dev/null 2>&1; then
        log "error" "$config_label is not a valid JSON object"
        exit 1
    fi

    # jq -r prints "null" for a missing key, so types are checked inside jq.
    for key in plugin_name plugin_slug plugin_description plugin_author plugin_author_uri \
               "urls.$environment.ecommerce" "urls.$environment.app"; do
        status=$(jq -r --arg key "$key" '
            (try getpath($key | split(".")) catch null) as $value
            | if ($value | type) != "string" then "is missing or is not a string"
              elif $value == "" then "is empty"
              elif ($value | test("[\r\n]")) then "contains a line break"
              else "ok" end' "$config_file")
        if [[ "$status" != "ok" ]]; then
            log "error" "$key $status in $config_label"
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    value=$(config_value "$config_file" plugin_slug)
    if [[ ! "$value" =~ $SLUG_PATTERN ]]; then
        log "error" "plugin_slug must be lowercase letters and digits separated by single hyphens in $config_label"
        failed=1
    fi

    # plugin_name goes inside single-quoted PHP strings and is used as a sprintf format.
    value=$(config_value "$config_file" plugin_name)
    if [[ "$value" == *"'"* || "$value" == *"\\"* || "$value" == *"%"* ]]; then
        log "error" "plugin_name must not contain a single quote, a backslash or a percent sign in $config_label"
        failed=1
    fi

    for key in "urls.$environment.ecommerce" "urls.$environment.app"; do
        value=$(config_value "$config_file" "$key")
        if [[ "$key" == "urls.local.ecommerce" && "$value" == "NGROK_URL" ]]; then
            continue
        fi
        if [[ ! "$value" =~ $URL_PATTERN ]]; then
            log "error" "$key must start with http:// or https:// in $config_label"
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    log "success" "Configuration validated: $config_label"
}

# Placeholder -> value map (the plugin-source/ placeholder contract, nothing else).
# PLUGIN_SLUG is the OpenCart extension code (no hyphens allowed): plugin_slug with "-" -> "_".
# PLUGIN_NAMESPACE is what OpenCart derives from the code for namespaces and class names:
# str_replace('_', '', ucwords(code, '_')), e.g. solo_envios -> SoloEnvios.
create_replacement_map() {
    local config_file="$1"
    local environment="$2"
    local version="$3"
    local ngrok_url="${4:-}"

    jq -c \
        --arg environment "$environment" \
        --arg version "$version" \
        --arg ngrok_url "$ngrok_url" \
        '(.plugin_slug | gsub("-"; "_")) as $code
        | {
            "PLUGIN_NAME": .plugin_name,
            "PLUGIN_SLUG": $code,
            "PLUGIN_NAMESPACE": ($code | split("_")
                | map((.[0:1] | ascii_upcase) + .[1:]) | join("")),
            "PLUGIN_DESCRIPTION": .plugin_description,
            "PLUGIN_AUTHOR": .plugin_author,
            "PLUGIN_AUTHOR_URI": .plugin_author_uri,
            "PLUGIN_VERSION": $version,
            "PLUGIN_ENVIRONMENT": $environment,
            "ECOMMERCE_URL": (.urls[$environment].ecommerce
                | if . == "NGROK_URL" then $ngrok_url else . end),
            "APP_URL": .urls[$environment].app
        }' "$config_file"
}

# === TEMPLATE PROCESSING ===

apply_replacements() {
    local replacement_map="$1"
    # Inside .tmp-build (not zipped) so the EXIT trap removes it on failure.
    local sed_script="$TEMP_DIR/.replacements.sed"

    # Longest key first so a key never matches inside a longer one. Keys are escaped as
    # regex; values escape \, & and the # delimiter.
    jq -r '
        to_entries
        | sort_by(-(.key | length), .key)
        | .[]
        | "s#" + (.key | gsub("(?<c>[\\[\\]\\\\.*^$#])"; "\\\(.c)"))
          + "#" + (.value | gsub("(?<c>[\\\\&#])"; "\\\(.c)")) + "#g"
    ' <<<"$replacement_map" > "$sed_script"

    local file
    while IFS= read -r -d '' file; do
        sed -i.bak -f "$sed_script" "$file"
        rm -f "${file}.bak"
    done < <(find "$TEMP_DIR" -type f \( -name '*.php' -o -name '*.twig' \) -print0)

    rm -f "$sed_script"
}

# Replaces PLUGIN_SLUG in file and directory names (after the content replacements).
# -depth lists a directory's contents before the directory itself, so renaming a directory
# never breaks a path that is still to come. Only the basename is rewritten.
rename_template_files() {
    local replacement_map="$1"
    local plugin_slug path base

    plugin_slug=$(jq -r '.PLUGIN_SLUG' <<<"$replacement_map")

    log "info" "Renaming template files..."

    while IFS= read -r -d '' path; do
        base="${path##*/}"
        mv "$path" "${path%/*}/${base//PLUGIN_SLUG/$plugin_slug}"
    done < <(find "$TEMP_DIR" -depth -name '*PLUGIN_SLUG*' -print0)
}

# install.json is rewritten with jq so quotes or accents in the values keep it valid JSON.
update_install_json() {
    local replacement_map="$1"
    local install_json="$TEMP_DIR/install.json"

    if [[ ! -f "$install_json" ]]; then
        log "error" "plugin-source/install.json not found"
        exit 1
    fi

    jq --argjson map "$replacement_map" '
        .name = $map.PLUGIN_NAME
        | .code = $map.PLUGIN_SLUG
        | .description = $map.PLUGIN_DESCRIPTION
        | .version = $map.PLUGIN_VERSION
        | .author = $map.PLUGIN_AUTHOR
        | .link = $map.PLUGIN_AUTHOR_URI
    ' "$install_json" > "$install_json.tmp"
    mv "$install_json.tmp" "$install_json"
}

# === FILE OPERATIONS ===

# White-label guard, run on plugin-source/ before anything is copied. The only skydropx
# allowed is the eco contract: the X-Skydropx-* / HTTP_X_SKYDROPX_* headers and the
# CONTRACT_CODE constant. Everything else must be a placeholder.
validate_source() {
    local failed=0
    local required offenders

    for required in install.json admin catalog; do
        if [[ ! -e "$SOURCE_DIR/$required" ]]; then
            log "error" "plugin-source/$required not found"
            failed=1
        fi
    done

    # grep -I skips binary files. Both greps exit 1 when nothing matches, which is the good case.
    offenders=$(cd "$SOURCE_DIR" && grep -rniI skydropx . \
        | grep -viE "X-Skydropx-|HTTP_X_SKYDROPX_|CONTRACT_CODE = 'skydropx'" || true)
    if [[ -n "$offenders" ]]; then
        log "error" "plugin-source must not mention skydropx outside the eco contract (file:line:text):"
        echo "$offenders" >&2
        failed=1
    fi

    offenders=$(cd "$SOURCE_DIR" && find . -iname '*skydropx*' -print)
    if [[ -n "$offenders" ]]; then
        log "error" "plugin-source must not mention skydropx in file names (use PLUGIN_SLUG):"
        echo "$offenders" >&2
        failed=1
    fi

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    log "success" "Source validated: no skydropx outside the eco contract"
}

copy_source_files() {
    log "info" "Copying source files..."

    rsync -a \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='tests' \
        --exclude='*.md' \
        --exclude='.DS_Store' \
        "$SOURCE_DIR/" "$TEMP_DIR/"
}

copy_brand_assets() {
    local brand="$1"
    local logo="$BRANDING_DIR/$brand/assets/images/logo.png"

    if [[ ! -f "$logo" ]]; then
        return 0
    fi

    log "info" "Copying brand assets..."

    # The admin module shows it from extension/<PLUGIN_SLUG>/admin/view/image/logo.png.
    mkdir -p "$TEMP_DIR/admin/view/image"
    cp "$logo" "$TEMP_DIR/admin/view/image/logo.png"
}

# === MAIN PROCESSING ===

process_brand() {
    local brand="$1"
    local replacement_map="$2"

    log "info" "Processing brand: $brand"

    log "info" "Processing template files..."
    apply_replacements "$replacement_map"
    update_install_json "$replacement_map"
    rename_template_files "$replacement_map"

    copy_brand_assets "$brand"
}

# Checks the processed tree, which is what gets installed. Any failure stops before zipping.
validate_build() {
    local replacement_map="$1"
    local failed=0
    local version plugin_slug pattern leftovers code built_version file output dir

    log "info" "Validating build..."

    version=$(jq -r '.PLUGIN_VERSION' <<<"$replacement_map")
    plugin_slug=$(jq -r '.PLUGIN_SLUG' <<<"$replacement_map")
    pattern=$(jq -r 'keys_unsorted | join("|")' <<<"$replacement_map")

    # grep -I skips binary files such as the logo.
    leftovers=$(cd "$TEMP_DIR" && grep -rIonE -- "$pattern" . || true)
    if [[ -n "$leftovers" ]]; then
        log "error" "Unreplaced placeholders (file:line:placeholder):"
        echo "$leftovers" >&2
        failed=1
    fi

    # File and directory names too: OpenCart loads files by name.
    leftovers=$(cd "$TEMP_DIR" && find . -print | grep -E -- "$pattern" || true)
    if [[ -n "$leftovers" ]]; then
        log "error" "Unreplaced placeholders in file names:"
        echo "$leftovers" >&2
        failed=1
    fi

    if command -v php >/dev/null 2>&1; then
        local lint_failed=0
        while IFS= read -r -d '' file; do
            if ! output=$(php -l "$file" 2>&1); then
                log "error" "PHP syntax error in ${file#"$TEMP_DIR"/}"
                echo "$output" >&2
                lint_failed=1
            fi
        done < <(find "$TEMP_DIR" -type f -name '*.php' -print0)

        if [[ $lint_failed -ne 0 ]]; then
            failed=1
        else
            log "success" "PHP lint passed"
        fi
    else
        log "warning" "php not found, skipping lint"
    fi

    if ! jq empty "$TEMP_DIR/install.json" >/dev/null 2>&1; then
        log "error" "install.json is missing or is not valid JSON"
        failed=1
    else
        code=$(jq -r '.code' "$TEMP_DIR/install.json")
        if [[ "$code" != "$plugin_slug" ]]; then
            log "error" "install.json code is '$code', expected '$plugin_slug'"
            failed=1
        fi

        built_version=$(jq -r '.version' "$TEMP_DIR/install.json")
        if [[ "$built_version" != "$version" ]]; then
            log "error" "install.json version is '$built_version', expected '$version'"
            failed=1
        fi
    fi

    for dir in admin catalog; do
        if [[ ! -d "$TEMP_DIR/$dir" ]]; then
            log "error" "$dir/ is missing from the build"
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        log "error" "Build validation failed, no package created"
        exit 1
    fi

    log "success" "Build validated"
}

create_package() {
    local replacement_map="$1"
    local config_file="$2"
    local plugin_name plugin_slug extension_code version environment ecommerce_url
    plugin_name=$(jq -r '.PLUGIN_NAME' <<<"$replacement_map")
    # The folder keeps the config's hyphenated slug; the zip is named after the extension code.
    plugin_slug=$(config_value "$config_file" plugin_slug)
    extension_code=$(jq -r '.PLUGIN_SLUG' <<<"$replacement_map")
    version=$(jq -r '.PLUGIN_VERSION' <<<"$replacement_map")
    environment=$(jq -r '.PLUGIN_ENVIRONMENT' <<<"$replacement_map")
    ecommerce_url=$(jq -r '.ECOMMERCE_URL' <<<"$replacement_map")

    # OpenCart 4 derives the extension folder from the file name, so it must be <code>.ocmod.zip;
    # version and environment go in the folder.
    local output_dir="$BUILD_DIR/${plugin_slug}-v${version}-${environment}"
    local archive="$output_dir/$extension_code.ocmod.zip"

    log "info" "Creating package: ${archive#"$SCRIPT_DIR"/}"

    mkdir -p "$output_dir"
    # zip updates an existing archive in place, so start from scratch.
    rm -f "$archive"
    # Zip the contents (install.json at the root), not the .tmp-build folder itself.
    if ! (cd "$TEMP_DIR" && zip -qrX "$archive" install.json admin catalog); then
        rm -f "$archive"
        log "error" "Could not create $archive"
        exit 1
    fi

    echo
    log "success" "Build completed successfully!"
    echo
    echo "  Brand: $plugin_name"
    echo "  Version: $version"
    echo "  Environment: $environment"
    echo "  Ecommerce URL: $ecommerce_url"
    echo "  Output: $archive"
    echo "  Size: $(du -h "$archive" | cut -f1)"
    echo
    log "warning" "Do not rename $extension_code.ocmod.zip: OpenCart installs the extension under the zip's file name"
    echo
}

# === USER INTERFACE ===
# Each prompt aborts on EOF (C3): command substitution does not inherit set -e, so the
# status of read is checked explicitly. A last line without a newline still counts as input.

select_brand() {
    local -a brands=("$@")
    local selection i

    if [[ ${#brands[@]} -eq 0 ]]; then
        log "error" "No brands available"
        return 1
    fi

    log "info" "Available brands:" >&2
    for i in "${!brands[@]}"; do
        echo "  $((i+1)). ${brands[i]}" >&2
    done
    echo >&2

    while true; do
        if ! read -r -p "Select brand (1-${#brands[@]}): " selection && [[ -z "$selection" ]]; then
            log "error" "No input left while selecting the brand"
            return 1
        fi
        if [[ "$selection" =~ ^[0-9]+$ ]] &&
           [[ "$selection" -ge 1 ]] &&
           [[ "$selection" -le ${#brands[@]} ]]; then
            echo "${brands[$((selection-1))]}"
            return 0
        fi
        log "error" "Invalid selection. Please enter a number between 1 and ${#brands[@]}"
    done
}

select_environment() {
    local -a environments=("${SUPPORTED_ENVIRONMENTS[@]}")
    local selection i

    log "info" "Available environments:" >&2
    for i in "${!environments[@]}"; do
        echo "  $((i+1)). ${environments[i]}" >&2
    done
    echo >&2

    while true; do
        if ! read -r -p "Select environment (1-${#environments[@]}): " selection && [[ -z "$selection" ]]; then
            log "error" "No input left while selecting the environment"
            return 1
        fi
        if [[ "$selection" =~ ^[0-9]+$ ]] &&
           [[ "$selection" -ge 1 ]] &&
           [[ "$selection" -le ${#environments[@]} ]]; then
            echo "${environments[$((selection-1))]}"
            return 0
        fi
        log "error" "Invalid selection. Please enter a number between 1 and ${#environments[@]}"
    done
}

get_version() {
    local version
    while true; do
        if ! read -r -p "Enter version (e.g. 1.2.3): " version && [[ -z "$version" ]]; then
            log "error" "No input left while reading the version"
            return 1
        fi
        version="${version#v}"
        if [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "$version"
            return 0
        fi
        log "error" "Invalid version format. Use semantic versioning (e.g. 1.2.3)"
    done
}

get_ngrok_url() {
    local ngrok_url
    while true; do
        if ! read -r -p "Enter Ngrok URL (e.g. https://swine-subtle-largely.ngrok-free.app): " ngrok_url && [[ -z "$ngrok_url" ]]; then
            log "error" "No input left while reading the Ngrok URL"
            return 1
        fi
        if [[ "$ngrok_url" =~ ^https://[a-zA-Z0-9-]+\.(ngrok\.io|ngrok(-free)?\.(app|dev))/?$ ]]; then
            echo "${ngrok_url%/}"
            return 0
        fi
        log "error" "Invalid Ngrok URL format. Use format: https://example.ngrok-free.dev, https://example.ngrok-free.app or https://example.ngrok.io"
    done
}

# === MAIN FUNCTION ===

main() {
    echo -e "${PURPLE}"
    echo "╔════════════════════════════════════════════════╗"
    echo "║           OpenCart Extension Builder           ║"
    echo "║                                                ║"
    echo "╚════════════════════════════════════════════════╝"
    echo -e "${NC}"

    if ! check_dependencies; then
        exit 1
    fi

    if [[ ! -d "$SOURCE_DIR" || ! -d "$BRANDING_DIR" ]]; then
        log "error" "Required directories not found (plugin-source/, branding/)"
        exit 1
    fi

    mkdir -p "$BUILD_DIR"

    # Brands are the branding/ folders with a config.json, in glob order.
    local brands=()
    local dir
    for dir in "$BRANDING_DIR"/*; do
        if [[ -d "$dir" && -f "$dir/config.json" ]]; then
            brands+=("$(basename "$dir")")
        fi
    done

    if [[ ${#brands[@]} -eq 0 ]]; then
        log "error" "No brands found in $BRANDING_DIR"
        exit 1
    fi

    # User selections
    local selected_brand
    selected_brand=$(select_brand "${brands[@]}") || exit 1
    log "success" "Brand selected: $selected_brand"
    echo

    local config_file="$BRANDING_DIR/$selected_brand/config.json"
    if [[ ! -f "$config_file" ]]; then
        log "error" "Configuration file not found: $config_file"
        exit 1
    fi

    local version
    version=$(get_version) || exit 1
    echo

    local environment
    environment=$(select_environment) || exit 1
    echo

    validate_config "$config_file" "$environment"
    validate_source
    echo

    local ngrok_url=""
    if [[ "$environment" == "local" ]]; then
        ngrok_url=$(get_ngrok_url) || exit 1
        echo
    fi

    log "success" "Building for environment: $environment"

    local replacement_map
    replacement_map=$(create_replacement_map "$config_file" "$environment" "$version" "$ngrok_url")

    # Build process
    log "info" "Preparing build..."
    cleanup
    mkdir -p "$TEMP_DIR"

    copy_source_files
    process_brand "$selected_brand" "$replacement_map"
    validate_build "$replacement_map"
    create_package "$replacement_map" "$config_file"

    # Ask to open builds directory. EOF here means "n": the package is already built.
    local open_builds=""
    while true; do
        if ! read -r -p "Open builds directory? (y/n): " open_builds && [[ -z "$open_builds" ]]; then
            open_builds="n"
            echo
            break
        fi
        if [[ "$open_builds" =~ ^[YyNn]$ ]]; then
            break
        fi
        log "error" "Answer with 'y' (yes) or 'n' (no)"
    done

    if [[ "$open_builds" =~ ^[Yy]$ ]]; then
        case "$OSTYPE" in
            darwin*) open "$BUILD_DIR" ;;
            linux*) xdg-open "$BUILD_DIR" 2>/dev/null || log "info" "Open manually: $BUILD_DIR" ;;
            *) log "info" "Open manually: $BUILD_DIR" ;;
        esac
    fi
}

main "$@"
