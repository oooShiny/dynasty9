# Muse.ai Video Switcher

A flexible JavaScript utility for switching videos in Muse.ai players by clicking on links or buttons.

## Features

- ✅ Support for multiple players on the same page
- ✅ Active state styling for currently playing video
- ✅ Works with any clickable element (links, buttons, etc.)
- ✅ Can be loaded on specific pages only
- ✅ Drupal-integrated with behaviors pattern
- ✅ Also works standalone without Drupal

## Installation

### For Drupal (Recommended)

The library is already defined in `dynasty_tw.libraries.yml` as `video_switcher`.

#### Option 1: Attach to specific node types via template

In your page template (e.g., `page--node--[type].html.twig`):

```twig
{{ attach_library('dynasty_tw/video_switcher') }}
```

#### Option 2: Attach programmatically in a preprocess function

In `dynasty_tw.theme`:

```php
function dynasty_tw_preprocess_page(&$variables) {
  // Only load on specific node types
  $node = \Drupal::routeMatch()->getParameter('node');
  if ($node && $node->bundle() == 'highlight') {
    $variables['#attached']['library'][] = 'dynasty_tw/video_switcher';
  }
}
```

#### Option 3: Attach via a custom block or view

When creating a custom block or view that uses video switching:

```php
$build['#attached']['library'][] = 'dynasty_tw/video_switcher';
```

### Standalone (No Drupal)

Include the standalone version in your HTML:

```html
<script src="/themes/custom/dynasty_tw/js/video_switcher.js"></script>
```

## Usage

### Important: When Using Inline in Drupal Content

When adding the video switcher directly into Drupal content (WYSIWYG, custom blocks, etc.), you need to include a small inline initialization because the library file loads after your content. Add this at the top of your script:

```javascript
// Initialize MuseVideoSwitcher inline (prevents "not defined" errors)
window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
  players: {},
  registerPlayer: function(category, player) {
    this.players[category] = player;
    console.log('Registered player:', category);
  },
  switchVideo: function(category, videoId) {
    const player = this.players[category];
    if (player && typeof player.setVideo === 'function') {
      player.setVideo(videoId);
    }
  }
};
```

The main library will still attach the click handlers when it loads, but this ensures your player registration works immediately.

### Step 1: Create the video player container

```html
<div id="longest-plays" class="aspect-video h-full"></div>
```

### Step 2: Initialize the Muse.ai player

```html
<script src="https://muse.ai/static/js/embed-player.min.js"></script>
<script>
  // Ensure MuseVideoSwitcher is available (inline initialization)
  window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
    players: {},
    registerPlayer: function(category, player) {
      this.players[category] = player;
      console.log('Registered player:', category);
    },
    switchVideo: function(category, videoId) {
      const player = this.players[category];
      if (player && typeof player.setVideo === 'function') {
        player.setVideo(videoId);
      }
    }
  };

  // Initialize the player
  const longestPlays = MusePlayer({
    container: '#longest-plays',
    video: 'smZ3M7x',
    sizing: 'fit',
    search: false,
    links: false,
    logo: false,
    title: false
  });

  // Register the player with the video switcher
  MuseVideoSwitcher.registerPlayer('longest-plays', longestPlays);
</script>
```

### Step 3: Create clickable elements with data attributes

```html
<a data-video-id="smZ3M7x" data-video-category="longest-plays" class="active">
  Video 1 - Currently Playing
</a>

<a data-video-id="UaeHcHT" data-video-category="longest-plays">
  Video 2
</a>

<a data-video-id="abc123X" data-video-category="longest-plays">
  Video 3
</a>
```

## Data Attributes

- `data-video-id` (required): The Muse.ai video ID
- `data-video-category` (required): The category/player name (must match the name used in `registerPlayer()`)

## Active State Styling

Add CSS to style the active link:

```css
[data-video-category].active {
  background-color: #c60c30;
  font-weight: bold;
  color: white;
}
```

Or use Tailwind/DaisyUI classes:

```html
<a 
  data-video-id="UaeHcHT" 
  data-video-category="longest-plays"
  class="btn btn-primary [&.active]:btn-secondary [&.active]:font-bold"
>
  Video Title
</a>
```

## Multiple Players Example

You can have multiple players on the same page:

```html
<!-- Player 1: Longest Plays -->
<div id="longest-plays"></div>
<script>
  const longestPlays = MusePlayer({
    container: '#longest-plays',
    video: 'smZ3M7x'
  });
  MuseVideoSwitcher.registerPlayer('longest-plays', longestPlays);
</script>

<a data-video-id="smZ3M7x" data-video-category="longest-plays">Play 1</a>
<a data-video-id="UaeHcHT" data-video-category="longest-plays">Play 2</a>

<!-- Player 2: Best Touchdowns -->
<div id="best-touchdowns"></div>
<script>
  const bestTouchdowns = MusePlayer({
    container: '#best-touchdowns',
    video: 'xyz789A'
  });
  MuseVideoSwitcher.registerPlayer('best-touchdowns', bestTouchdowns);
</script>

<a data-video-id="xyz789A" data-video-category="best-touchdowns">TD 1</a>
<a data-video-id="def456B" data-video-category="best-touchdowns">TD 2</a>
```

## Using with Buttons or Other Elements

The switcher works with any clickable element:

```html
<!-- With buttons -->
<button data-video-id="UaeHcHT" data-video-category="longest-plays">
  Load Video
</button>

<!-- With divs -->
<div data-video-id="UaeHcHT" data-video-category="longest-plays" style="cursor: pointer;">
  <img src="thumbnail.jpg" alt="Video thumbnail">
  <p>Click to play</p>
</div>

<!-- With list items -->
<ul>
  <li data-video-id="smZ3M7x" data-video-category="longest-plays">Video 1</li>
  <li data-video-id="UaeHcHT" data-video-category="longest-plays">Video 2</li>
</ul>
```

## API Reference

### MuseVideoSwitcher.registerPlayer(category, player)

Register a Muse.ai player instance.

**Parameters:**
- `category` (string): The category name (matches `data-video-category`)
- `player` (object): The MusePlayer instance

**Example:**
```javascript
const myPlayer = MusePlayer({ container: '#player', video: 'abc123' });
MuseVideoSwitcher.registerPlayer('my-category', myPlayer);
```

### MuseVideoSwitcher.switchVideo(category, videoId)

Manually switch to a different video.

**Parameters:**
- `category` (string): The category/player name
- `videoId` (string): The Muse.ai video ID

**Example:**
```javascript
MuseVideoSwitcher.switchVideo('longest-plays', 'newVideoId123');
```

## Troubleshooting

### Videos not switching

1. Make sure the player is registered:
   ```javascript
   console.log(MuseVideoSwitcher.players);
   ```

2. Check that the category names match exactly
3. Verify the video IDs are correct
4. Check browser console for errors

### Active state not updating

Make sure you have CSS targeting the `.active` class:

```css
[data-video-category].active {
  /* your styles */
}
```

### Multiple instances on AJAX-loaded content

The Drupal behavior will automatically handle AJAX-loaded content. Just ensure the library is attached to the AJAX response.

## Browser Support

Works in all modern browsers that support:
- ES6 (const, arrow functions)
- addEventListener
- querySelector/querySelectorAll

## License

Part of the Dynasty theme for the New England Patriots Dynasty project.
