#!/usr/bin/env bash
# Install the official WP-CLI release for the disposable CI store.
set -euo pipefail

: "${RUNNER_TEMP:?RUNNER_TEMP must point to the CI temporary directory}"
: "${GITHUB_PATH:?GITHUB_PATH must point to the Actions path file}"

wp_cli_version=2.12.0
wp_cli_sha256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
wp_cli_directory="$RUNNER_TEMP/c2c-tools"
mkdir -p "$wp_cli_directory"
wp_cli_download=$(mktemp "$wp_cli_directory/wp-cli.XXXXXX")
trap 'rm -f "$wp_cli_download"' EXIT

curl --fail --location --silent --show-error \
  --retry 5 --retry-all-errors --retry-delay 2 \
  --connect-timeout 15 --max-time 120 \
  --output "$wp_cli_download" \
  "https://github.com/wp-cli/wp-cli/releases/download/v${wp_cli_version}/wp-cli-${wp_cli_version}.phar"
if ! printf '%s  %s\n' "$wp_cli_sha256" "$wp_cli_download" | sha256sum --check --status; then
  echo '::error::WP-CLI download did not match the pinned SHA-256 checksum.' >&2
  exit 1
fi
php "$wp_cli_download" --info
chmod 0755 "$wp_cli_download"
mv "$wp_cli_download" "$wp_cli_directory/wp"
printf '%s\n' "$wp_cli_directory" >> "$GITHUB_PATH"
