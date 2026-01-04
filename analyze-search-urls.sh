#!/bin/bash
# Analyze server logs to find most popular search URLs
# This helps identify which URLs are worth warming

LOG_FILE="${1:-/var/log/nginx/access.log}"

echo "Analyzing search URLs from: $LOG_FILE"
echo ""

echo "=== Top 20 Game Search URLs ==="
grep "GET /search/games" "$LOG_FILE" | \
  awk '{print $7}' | \
  sort | uniq -c | \
  sort -rn | \
  head -20

echo ""
echo "=== Top 20 Play/Highlight Search URLs ==="
grep "GET /search/plays" "$LOG_FILE" | \
  awk '{print $7}' | \
  sort | uniq -c | \
  sort -rn | \
  head -20

echo ""
echo "=== Top 10 Podcast Search URLs ==="
grep "GET /podcast" "$LOG_FILE" | \
  awk '{print $7}' | \
  sort | uniq -c | \
  sort -rn | \
  head -10

echo ""
echo "Usage: Update warm-cache.sh with the top URLs from above"
