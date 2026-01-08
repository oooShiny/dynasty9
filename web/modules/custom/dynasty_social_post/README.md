# Dynasty Social Post

Automatically posts random highlight videos from your Drupal site to Bluesky social media platform.

## Features

- **Automatic Posting**: Posts random highlight videos on a scheduled basis via Drupal cron
- **Manual Posting**: Post a random highlight immediately from the admin interface
- **Smart Selection**: Tracks which highlights have been posted to avoid duplicates
- **Configurable Intervals**: Choose how often to post (hourly, daily, weekly, etc.)
- **Video Processing**: Uploads videos to Bluesky's video service and waits for processing before posting

## Requirements

- Drupal 11 (or 8/9/10)
- A Bluesky account with verified email
- Highlight content type with video files
- Drupal cron configured and running

## Installation

1. Enable the module:
   ```bash
   ddev drush en dynasty_social_post -y
   ```

2. Configure your Bluesky credentials:
   - Navigate to **Administration > Configuration > Dynasty > Bluesky Social Post**
   - Enter your Bluesky handle or email
   - Enter your Bluesky app password (recommended: create an app-specific password in Bluesky settings)
   - Enable automatic posting if desired
   - Choose your posting interval
   - Save the configuration

## Configuration

### Bluesky Credentials

You'll need:
- **Identifier**: Your Bluesky handle (e.g., `user.bsky.social`) or email address
- **App Password**: For security, create an app-specific password in Bluesky:
  1. Go to Bluesky Settings
  2. Navigate to App Passwords
  3. Create a new app password
  4. Use this password in the module configuration

### Posting Settings

- **Enable automatic posting**: Check this to enable scheduled posting via cron
- **Posting interval**: Choose how often to post (every hour, daily, weekly, etc.)

### Manual Posting

To post a random highlight immediately:
1. Navigate to **Administration > Configuration > Dynasty > Bluesky Social Post**
2. Click the **Post Now** tab
3. The system will select a random highlight and post it to Bluesky

## How It Works

1. **Selection**: The module queries for published highlight nodes that haven't been posted yet
2. **Fetch from muse.ai**: Retrieves video metadata and download URL from muse.ai API using the `field_muse_video_id`
3. **Temporary Download**: Downloads the video from muse.ai to Drupal's temporary directory
4. **Video Upload**: Uploads the video file to Bluesky's video service
5. **Processing**: Waits for Bluesky to process the video (transcode, optimize)
6. **Posting**: Creates a post with the video and highlight description
7. **Cleanup**: Removes the temporary video file from the server
8. **Tracking**: Marks the highlight as posted to avoid duplicates

When all highlights have been posted, the tracking list resets automatically.

### Video Storage

This module works with highlights that have videos hosted on muse.ai:
- Uses the `field_muse_video_id` field to identify the video
- Fetches video metadata (URL, dimensions) from muse.ai's API
- Downloads videos temporarily for upload to Bluesky
- Automatically cleans up temporary files after posting

## Cron Requirements

The automatic posting feature relies on Drupal's cron system. Make sure cron is running regularly:

```bash
# Run cron manually
ddev drush cron

# Check cron status
ddev drush core-cron
```

## Troubleshooting

### Video Not Posting

Check the logs:
```bash
ddev drush watchdog-show --type=dynasty_social_post
```

Common issues:
- **No muse.ai video ID**: The highlight must have a muse.ai video ID in the `field_muse_video_id` field
- **Video not accessible**: Ensure the video is publicly accessible on muse.ai
- **Download failed**: Check server internet connectivity and firewall settings
- **Authentication failed**: Verify your Bluesky credentials are correct
- **Email not verified**: Your Bluesky account email must be verified to post videos
- **Temporary file permission errors**: Ensure Drupal's temporary directory is writable

### Reset Posted Highlights

If you want to start posting highlights again from the beginning:
1. Go to the settings page
2. Click **Reset posted highlights list**

This clears the tracking list so all highlights can be posted again.

## API Details

The module uses the Bluesky AT Protocol (atproto) API:
- Authentication: `com.atproto.server.createSession`
- Service Auth: `com.atproto.server.getServiceAuth`
- Video Upload: `app.bsky.video.uploadVideo`
- Job Status: `app.bsky.video.getJobStatus`
- Create Post: `com.atproto.repo.createRecord`

For more information, see: https://docs.bsky.app/docs/tutorials/video

## Security Notes

- Credentials are stored in Drupal's configuration system
- Use app-specific passwords rather than your main Bluesky password
- Ensure proper access controls on the configuration page (requires "administer site configuration" permission)

## Support

For issues or feature requests, please contact the site administrator.
