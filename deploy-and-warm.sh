#!/bin/bash
# Deploy script that purges Cloudflare cache and re-warms it
# Usage: ./deploy-and-warm.sh

set -e  # Exit on error

CF_ZONE_ID="1cf3bfaded3728fb2dd9a9cffaacba11"  # From cloudflare_purge.settings.yml
CF_API_TOKEN="cc51a733a6f417cb62cd40311aa18ae54618f"  # From cloudflare_purge.settings.yml
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
