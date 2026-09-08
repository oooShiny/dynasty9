#!/bin/bash
# Deploy script that purges Cloudflare cache and re-warms it
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

SITE_URL="https://patsdynasty.com"

echo "=== Dynasty Deploy & Cache Warm ==="
echo ""

# Step 1: Deploy code changes (if any)
echo "Step 1: Checking for code deployment..."
# Add your deploy commands here if needed
# git pull, composer install, drush updb, drush cim, etc.

# Step 2: Purge Cloudflare cache
echo ""
echo "Step 2: Purging Cloudflare cache..."
curl -X POST "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
  -H "Authorization: Bearer ${CF_API_TOKEN}" \
  -H "Content-Type: application/json" \
  --data '{"purge_everything":true}' \
  -s | jq -r '.success'

echo "Waiting 5 seconds for purge to complete..."
sleep 5

# Step 3: Warm important caches
echo ""
echo "Step 3: Warming Cloudflare cache..."
bash warm-cache.sh "$SITE_URL"

echo ""
echo "=== Deploy Complete ==="
