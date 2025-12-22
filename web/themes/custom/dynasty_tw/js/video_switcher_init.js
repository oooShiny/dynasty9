/**
 * @file
 * Inline initialization helper for Muse.ai Video Switcher
 * 
 * This small script can be placed inline to ensure MuseVideoSwitcher
 * is available even if the main library hasn't loaded yet.
 * 
 * Copy this into your inline <script> tags if needed.
 */

window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
  players: {},
  registerPlayer: function(category, player) {
    this.players[category] = player;
    console.log('Registered Muse.ai player:', category);
  },
  switchVideo: function(category, videoId) {
    const player = this.players[category];
    if (player && typeof player.setVideo === 'function') {
      player.setVideo(videoId);
      console.log('Switched to video:', videoId);
    } else {
      console.warn('Player not found or does not support setVideo:', category);
    }
  }
};
