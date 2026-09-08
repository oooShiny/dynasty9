#!/bin/bash
# Purges the Cloudflare cache. Run manually after deploying code changes
# that affect rendered HTML/CSS/JS (deploy itself is still git pull +
# drush cim/cr, done separately).
# Usage: ./deploy-and-warm.sh
#
# Requires CF_ZONE_ID and CF_API_TOKEN in the environment. Copy .env.example
# to .env (gitignored) and fill in real values, or export them yourself
# before running this script.

set -e  # Exit on error

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/.env" ]; then
  # shellcheck disable=SC1091
  source "$SCRIPT_DIR/.env"
fi

if [ -z "$CF_ZONE_ID" ] || [ -z "$CF_API_TOKEN" ]; then
  echo "Error: CF_ZONE_ID and CF_API_TOKEN must be set (in your environment or in $SCRIPT_DIR/.env)." >&2
  exit 1
fi

echo "=== Purging Cloudflare cache ==="
curl -X POST "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
  -H "Authorization: Bearer ${CF_API_TOKEN}" \
  -H "Content-Type: application/json" \
  --data '{"purge_everything":true}' \
  -s | jq -r '.success'

echo "Done."
