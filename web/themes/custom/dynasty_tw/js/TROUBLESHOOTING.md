# Video Switcher Troubleshooting Guide

## Error: "MuseVideoSwitcher is not defined"

### Cause
The library file loads after your inline script runs.

### Solution
Add the inline initialization at the top of your script:

```javascript
window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
  players: {},
  registerPlayer: function(category, player) {
    this.players[category] = player;
  },
  switchVideo: function(category, videoId) {
    const player = this.players[category];
    if (player && typeof player.setVideo === 'function') {
      player.setVideo(videoId);
    }
  }
};
```

## Videos Not Switching

### Check 1: Is the player registered?
Open browser console and type:
```javascript
console.log(MuseVideoSwitcher.players);
```

You should see your player(s) listed. If empty, the registration didn't work.

### Check 2: Are the category names matching?
The `data-video-category` attribute must exactly match the name used in `registerPlayer()`:

```javascript
// These must match exactly:
MuseVideoSwitcher.registerPlayer('longest-plays', longestPlays);
// and
<a data-video-category="longest-plays">...</a>
```

### Check 3: Does the player support setVideo()?
Try manually in console:
```javascript
const player = MuseVideoSwitcher.players['longest-plays'];
console.log(typeof player.setVideo); // Should be "function"
player.setVideo('UaeHcHT'); // Should switch video
```

If `setVideo` is undefined, check Muse.ai documentation for the correct method name.

### Check 4: Are click handlers attached?
Check if clicking logs to console:
```javascript
// Add this temporarily to debug
document.querySelectorAll('[data-video-category]').forEach(el => {
  el.addEventListener('click', () => console.log('Clicked!'));
});
```

## Active Class Not Applying

### Check 1: CSS Loaded
Make sure the library is attached:
```twig
{{ attach_library('dynasty_tw/video_switcher') }}
```

### Check 2: CSS Specificity
Your CSS might be overriding the active styles. Try adding `!important`:
```css
[data-video-category].active {
  background-color: #c60c30 !important;
}
```

### Check 3: Inspect Element
Right-click the link and "Inspect". Check if the `active` class is being added/removed when you click.

## Muse.ai Player Not Loading

### Check 1: Script Tag
Make sure the Muse.ai script is loaded:
```html
<script src="https://muse.ai/static/js/embed-player.min.js"></script>
```

### Check 2: Container Exists
The container must exist before MusePlayer() is called:
```javascript
console.log(document.querySelector('#longest-plays')); // Should not be null
```

### Check 3: Timing
Wrap in DOMContentLoaded if needed:
```javascript
document.addEventListener('DOMContentLoaded', function() {
  const player = MusePlayer({ ... });
});
```

## Multiple Players Interfering

### Solution
Make sure each player has:
1. Unique container ID
2. Unique category name
3. Separate registration

```javascript
// Player 1
const player1 = MusePlayer({ container: '#player-1', video: 'abc' });
MuseVideoSwitcher.registerPlayer('category-1', player1);

// Player 2
const player2 = MusePlayer({ container: '#player-2', video: 'xyz' });
MuseVideoSwitcher.registerPlayer('category-2', player2);
```

## Drupal AJAX Issues

The video switcher uses Drupal behaviors with `once()`, so it should handle AJAX automatically. If issues occur:

### Check
Make sure the library is attached to AJAX responses:
```php
$response->addCommand(new InvokeCommand(NULL, 'attachBehaviors'));
```

## Still Having Issues?

### Debug Checklist
1. ✅ Clear Drupal cache: `ddev drush cr`
2. ✅ Check browser console for errors
3. ✅ Verify library is attached to page (check page source)
4. ✅ Ensure video IDs are correct Muse.ai IDs
5. ✅ Test in browser console manually:
   ```javascript
   MuseVideoSwitcher.switchVideo('your-category', 'videoId');
   ```

### Get Help
Check the complete working example in:
- `js/INLINE_EXAMPLE.html` - Ready to paste example
- `js/video_switcher_example.html` - Standalone test page
- `templates/examples/video-switcher-example.html.twig` - Twig integration

### Enable Debug Mode
Add this to see what's happening:
```javascript
window.MuseVideoSwitcher.debug = true;

// Then modify switchVideo to log:
switchVideo: function(category, videoId) {
  if (this.debug) console.log('Switching:', category, videoId, this.players);
  // ... rest of code
}
```
