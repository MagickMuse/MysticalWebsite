#!/usr/bin/env bash
# Shared checks for the Hostinger upload jobs. Sourced by the workflows.
set -euo pipefail

need() {
  local name="$1"
  if [ -z "${!name:-}" ]; then
    echo "::error::The GitHub secret $name is missing. Add it under Settings → Secrets and variables → Actions (see manuscript/TEST-SITE.md)."
    exit 1
  fi
}
for s in FTP_HOST FTP_USER LFTP_PASSWORD FTP_DIR FTP_DATA_DIR MANUSCRIPT_CONFIG; do need "$s"; done

# Paths are FTP paths exactly as FileZilla shows them. Keep them to safe characters.
for p in "$FTP_DIR" "$FTP_DATA_DIR"; do
  if ! [[ "$p" =~ ^/?[A-Za-z0-9._/-]+$ ]]; then
    echo "::error::FTP folder paths may only contain letters, numbers, . _ - and /. Check the FTP folder secrets (FTP_TEST_DIR/FTP_LIVE_DIR and their DATA_DIR)."
    exit 1
  fi
done
case "/$FTP_DATA_DIR/" in
  */public_html/*|*/htdocs/*|*/www/*)
    echo "::error::The data folder secret is inside public_html, where the paid pages could be downloaded. Use a folder next to public_html instead."
    exit 1 ;;
esac

# FileZilla-style login. Certificate checking can be relaxed with the repository variable FTP_VERIFY_CERT=no
# (the connection stays encrypted; same as clicking "trust this certificate" in FileZilla).
VERIFY="${FTP_VERIFY_CERT:-yes}"
lftp_run() {
  lftp -u "$FTP_USER" --env-password "$FTP_HOST" <<LFTP
set ftp:ssl-allow yes
set ssl:verify-certificate $VERIFY
set net:max-retries 4
set net:reconnect-interval-base 5
set net:timeout 40
set cmd:fail-exit yes
$1
bye
LFTP
}

# Writes the private settings from the secret, plus where the data folder is relative to manuscript/.
write_settings() {
  local target="$1"
  printf '%s\n' "$MANUSCRIPT_CONFIG" > "$target"
  if ! php -l "$target" > /dev/null 2>&1; then
    echo "::error::The settings secret (MANUSCRIPT_CONFIG_TEST or _LIVE) isn't a valid settings file (a quote or comma is probably missing). Compare it with the file you were given."
    exit 1
  fi
}
