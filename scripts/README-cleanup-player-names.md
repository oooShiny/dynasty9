# Player Name Cleanup Script

This script removes position suffixes from player names that may have been added by previous migrations.

## What It Does

Removes patterns like:
- `Tom Brady (QB)` → `Tom Brady`
- `Randy Moss (WR)` → `Randy Moss`
- `Tedy Bruschi (LB)` → `Tedy Bruschi`
- `Richard Seymour (DE-DT)` → `Richard Seymour`

## When To Use

Run this script **before** running the `dynasty_patriots_alltime_roster_import` migration if you have existing player nodes with position suffixes in their titles.

## Usage

### Local (DDEV)
```bash
ddev drush php:script scripts/cleanup-player-names.php
```

### Production
```bash
drush php:script scripts/cleanup-player-names.php
```

## Complete Workflow

1. **Clean up existing player names** (if needed):
   ```bash
   drush php:script scripts/cleanup-player-names.php
   ```

2. **Import configuration**:
   ```bash
   drush cim -y
   drush cr
   ```

3. **Run the player migration**:
   ```bash
   drush mim dynasty_patriots_alltime_roster_import --update
   ```

## What Gets Updated

The script will:
- ✅ Find all player nodes
- ✅ Check each title for position suffixes (pattern: space + parentheses with uppercase letters/hyphens/slashes)
- ✅ Remove the suffix if found
- ✅ Save the updated node
- ✅ Report results

## Safety

- The script only modifies player node titles
- It preserves all other data (fields, relationships, etc.)
- Changes are immediately saved to the database
- **Tip**: Take a database backup before running on production

## Example Output

```
Processing 1336 players...

✓ Updated: "Tom Brady (QB)" → "Tom Brady"
✓ Updated: "Randy Moss (WR)" → "Randy Moss"
✓ Updated: "Rob Gronkowski (TE)" → "Rob Gronkowski"

==========================================
Cleanup complete!
Updated: 245
Skipped: 1091
Total: 1336
==========================================
```
