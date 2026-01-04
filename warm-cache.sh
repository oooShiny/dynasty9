#!/bin/bash
# Cloudflare Cache Warmer for Dynasty Search Pages
# Usage: ./warm-cache.sh [production_url]

SITE="${1:-https://patsdynasty.com}"
USER_AGENT="Mozilla/5.0 (compatible; CacheWarmer/1.0)"

echo "Warming Cloudflare cache for: $SITE"
echo "Started: $(date)"
echo ""

# Function to warm a URL
warm_url() {
    local url="$1"
    echo -n "Warming: $url ... "
    status=$(curl -s -o /dev/null -w "%{http_code}" -A "$USER_AGENT" "$url")
    cf_status=$(curl -sI -A "$USER_AGENT" "$url" | grep -i "cf-cache-status" | tr -d '\r')
    echo "HTTP $status - $cf_status"
    sleep 0.5  # Be nice to the server
}

# Base search pages (no filters)
echo "=== Base Search Pages ==="
warm_url "$SITE/search/games"
warm_url "$SITE/search/plays"
warm_url "$SITE/podcast"

# Popular seasons
echo ""
echo "=== Popular Seasons ==="
for season in 2001 2003 2004 2014 2016 2018 2019 2020; do
    warm_url "$SITE/search/games?season%5B${season}%5D=${season}"
done

# Popular opponents (division rivals + frequent playoff opponents)
echo ""
echo "=== Popular Opponents ==="
opponents=(
    "New+York+Jets"
    "Miami+Dolphins"
    "Buffalo+Bills"
    "Pittsburgh+Steelers"
    "Indianapolis+Colts"
    "Denver+Broncos"
    "Kansas+City+Chiefs"
)

for opp in "${opponents[@]}"; do
    warm_url "$SITE/search/games?opponent%5B${opp}%5D=${opp}"
done

# Win/Loss filters
echo ""
echo "=== Result Filters ==="
warm_url "$SITE/search/games?result%5BWin%5D=Win"
warm_url "$SITE/search/games?result%5BLoss%5D=Loss"

# Home/Away
echo ""
echo "=== Home/Away ==="
warm_url "$SITE/search/games?home_away%5BHome%5D=Home"
warm_url "$SITE/search/games?home_away%5BAway%5D=Away"

# Playoff games
echo ""
echo "=== Playoff Games ==="
warm_url "$SITE/search/games?playoff_game%5B1%5D=1"

# Highlight searches (top plays)
echo ""
echo "=== Popular Highlight Searches ==="
warm_url "$SITE/search/plays?search=Brady"
warm_url "$SITE/search/plays?search=Edelman"
warm_url "$SITE/search/plays?search=Gronkowski"
warm_url "$SITE/search/plays?search=touchdown"
warm_url "$SITE/search/plays?search=interception"

echo ""
echo "Completed: $(date)"
echo "Total URLs warmed: approximately 30+"
