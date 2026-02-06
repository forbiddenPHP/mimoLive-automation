# mimoLive Automation System

A lightweight, procedural PHP automation system for controlling mimoLive via its HTTP API. Built with a frame-based queue system for precise timing control, it provides a flat keypath-based interface to the entire mimoLive API structure, enabling sophisticated automation workflows without object-oriented overhead.

The system loads the complete mimoLive API hierarchy (documents, layers, variants, layer-sets, outputs, sources, filters) into a flat named structure accessible via keypaths like `hosts/master/documents/MyShow/layers/Comments/live-state`, making it easy to control and monitor your live production programmatically.

---

## Installation

1. **Clone this repository**
   ```bash
   git clone https://github.com/forbiddenPHP/mimoLive-automation.git
   cd mimoLive-automation
   ```

2. **Install dependencies**
   ```bash
   brew install nginx
   brew install php
   ```

   **nginx config location on macOS:**
   - Apple Silicon: `/opt/homebrew/etc/nginx/nginx.conf`
   - Intel: `/usr/local/etc/nginx/nginx.conf`

3. **Configure nginx**

   Edit the nginx config file and add the following server block:

   ```nginx
   server {
       listen 8888;
       server_name localhost;

       root /path/to/mimoLive-automation;  # Change this to your repo path
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass 127.0.0.1:9000;  # or unix:/opt/homebrew/var/run/php-fpm.sock
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }
   }
   ```

4. **Start the server**
   ```bash
   brew services start nginx
   brew services start php
   ```

5. **Test the setup**
   ```bash
   curl http://localhost:8888/?list
   ```

---

## How to call scripts from MimoLive Automation Layer?

```php
// Call a prepared Script from scripts-Folder (without .php):
httpRequest(http://localhost:8888/?f=scriptname)

// Call an inline action (must be urlencoded!):
httpRequest(http://localhost:8888/?q=setLive%28%27fullpath%27%29)
```

> ⚠️ **Important:** Use `&realtime=true` if mimoLive should wait for the script execution to complete before continuing. **This is only possible if the script does not take longer than 9 seconds!**

---

## API Reference

**Note**: For brevity, examples use `$base = 'hosts/master/documents/MyShow/';`

---

## 🎬 Layer Control

<details>
<summary><strong>setLive($namedAPI_path)</strong> - Turn a layer or variant live</summary>

```php
$base = 'hosts/master/documents/forbiddenPHP/';
setLive($base.'layers/Comments');
setLive($base.'layers/JoPhi DEMOS/variants/stop');
setLive($base.'output-destinations/TV out');
```
</details>

<details>
<summary><strong>setOff($namedAPI_path)</strong> - Turn a layer off</summary>

```php
setOff($base.'layers/Comments');
setOff($base.'layers/MEv');
setOff($base.'output-destinations/TV out');
```
</details>

<details>
<summary><strong>toggleLive($namedAPI_path)</strong> - Toggle a layer, variant, or document live state</summary>

```php
toggleLive($base.'layers/Comments');  // Toggle layer on/off
toggleLive($base.'layers/Lower3rd/variants/Red');  // Toggle variant
toggleLive($base);  // Toggle document live state
```
</details>

<details>
<summary><strong>isLive($namedAPI_path)</strong> - Check if a layer, variant, layer-set, or output-destination is live/active</summary>

```php
if (isLive($base.'layers/Comments') === true) {
    // Layer is live
}

if (isLive($base.'layer-sets/RunA') === false) {
    // Layer-set is inactive
}

if (isLive($base.'layers/NonExistent') === null) {
    // Path not found or field doesn't exist
}
```

**Returns:** `true` (live/active), `false` (off/inactive), or `null` (path not found)

*Note: For layer-sets, checks the `active` field. For all others (layers, variants, output-destinations), checks `live-state`.*
</details>

<details>
<summary><strong>recall($namedAPI_path)</strong> - Recall a layer-set</summary>

```php
recall($base.'layer-sets/RunA');
recall($base.'layer-sets/OFF');
```
</details>

---

## 🔄 Variant Cycling

<details>
<summary><strong>cycleThroughVariants($layer_path)</strong> - Cycle to next variant (wraps around to first)</summary>

```php
cycleThroughVariants($base.'layers/Lower3rd');
// Works with variant paths too (will be stripped to layer path):
cycleThroughVariants($base.'layers/Lower3rd/variants/Red');
```

*Note: Cycles through all variants continuously. After the last variant, returns to the first.*
</details>

<details>
<summary><strong>cycleThroughVariantsBackwards($layer_path)</strong> - Cycle to previous variant (wraps around to last)</summary>

```php
cycleThroughVariantsBackwards($base.'layers/Lower3rd');
```

*Note: Cycles through all variants in reverse. Before the first variant, returns to the last.*
</details>

<details>
<summary><strong>bounceThroughVariants($layer_path)</strong> - Cycle to next variant (stops at last)</summary>

```php
bounceThroughVariants($base.'layers/Lower3rd');
```

*Note: Stops at the last variant instead of wrapping around. Safe for linear progressions.*
</details>

<details>
<summary><strong>bounceThroughVariantsBackwards($layer_path)</strong> - Cycle to previous variant (stops at first)</summary>

```php
bounceThroughVariantsBackwards($base.'layers/Lower3rd');
```

*Note: Stops at the first variant instead of wrapping around.*
</details>

<details>
<summary><strong>setLiveFirstVariant($layer_path)</strong> - Jump to first variant</summary>

```php
setLiveFirstVariant($base.'layers/Lower3rd');
```
</details>

<details>
<summary><strong>setLiveLastVariant($layer_path)</strong> - Jump to last variant</summary>

```php
setLiveLastVariant($base.'layers/Lower3rd');
```
</details>

---

## 📝 Content & Property Management

<details>
<summary><strong>setValue($namedAPI_path, $updates_array)</strong> - Update properties of documents, layers, variants, sources, filters, or outputs</summary>

```php
// Document-level properties
setValue($base, ['programOutputMasterVolume' => 0.8]);

// Layer properties
setValue($base.'layers/MEa', ['volume' => 0.5]);

// Source properties
setValue($base.'sources/a1', ['gain' => 1.2]);

// Source text content (input-values)
setValue($base.'sources/Color', [
    'input-values' => [
        'tvGroup_Content__Text_TypeMultiline' => 'Hello World'
    ]
]);

// Multiple properties at once
setValue($base.'layers/MEa', ['volume' => 0.5, 'opacity' => 0.8]);
```
</details>

<details>
<summary><strong>setAndAdjustAnnotationText(...)</strong> - Set annotation text and automatically adjust font size to fit</summary>

```php
setAndAdjustAnnotationText($path, $text, $top, $left, $width, $height, $padding_pct=5, $maxFontSize=200, $minFontSize=8)
```

```php
$layer = $base.'layers/testlayer';

// Basic usage: Text auto-sizes to fit the box
setAndAdjustAnnotationText($layer, 'Subscribe to my channel!', 270, 690, 540, 540, 5);

// Use existing text from layer (empty string or null)
setAndAdjustAnnotationText($layer, '', 100, 50, 300, 880, 5);

// Long text in wide flat box
setAndAdjustAnnotationText($layer, 'This is a much longer text that should automatically wrap and shrink to fit!', 800, 100, 1720, 200, 5);

// Short text, large box
setAndAdjustAnnotationText($layer, 'WOW', 50, 50, 1820, 980, 10);

// Emojis with non-breaking space (macOS: option+space)
setAndAdjustAnnotationText($layer, '🎬 LIVE NOW 🎬', 850, 1400, 480, 180, 10);

// Single line, full width
setAndAdjustAnnotationText($layer, 'BREAKING NEWS: Something incredible just happened!', 980, 50, 1820, 80, 5);

// Fullscreen title
setAndAdjustAnnotationText($layer, 'THE GRAND FINALE', 0, 0, 1920, 1080, 15);
```

**Parameters:**
- `$path` (required): Layer path to annotation/text layer
- `$text` (required): Text content (use empty string `''` or `null` to keep existing text)
- `$top` (required): Top position in pixels
- `$left` (required): Left position in pixels
- `$width` (required): Box width in pixels
- `$height` (required): Box height in pixels
- `$padding_pct` (optional): Padding as percentage of box height, default `5`
- `$maxFontSize` (optional): Maximum font size in pixels, default `200`
- `$minFontSize` (optional): Minimum font size in pixels, default `8`

**How it works:**
- Uses binary search to find the largest font size where text fits in the box
- Automatically simulates word-wrapping based on available width
- Respects aspect-ratio of padding (anamorphic inset in BoinxUnits)
- Reads font from layer settings and uses macOS `mdfind` to locate font file
- Sets text, font size, position (margins), and inset in a single `setValue()` call
- Font paths are cached for performance

*Note: When emojis are part of a phrase, use non-breaking spaces (macOS: option+space) to keep them together with their text.*
</details>

<details>
<summary><strong>increment($base, $var, $val)</strong> - Increment a numeric property by a specified amount</summary>

```php
$placer = 'hosts/master/documents/MyShow/layers/Placer';

// Increment input-values (auto-detected by __ in variable name)
increment($placer, 'tvGroup_Content__Opacity', 10);      // Slider: clamped to min/max
increment($placer, 'tvGroup_Geometry__Rotation', 45);    // Wheel: wraps around at 360°

// Increment direct properties (no __ in variable name)
increment($placer, 'volume', 0.2);
increment($placer, 'gain', 0.1);
```

**Parameters:**
- `$base` (required): Base path to the resource (layer, source, output)
- `$var` (required): Variable name (use "Copy API Key" from right-click menu on setting label)
- `$val` (required): Amount to increment by

**Behavior:**
- **Input-values** (contains `__`): Automatically prefixes `/input-values/` to the path
- **Direct properties** (no `__`): Uses path as-is (e.g., `volume`, `gain`)
- **Sliders**: Values are clamped to min/max from input-descriptions
- **Wheels** (unit = °): Values wrap around (e.g., 350° + 30° = 20°)
- Only works on numeric values, returns error for non-numeric types

![Right-click on label to copy API key](assets/right-click-on-label.png)
</details>

<details>
<summary><strong>decrement($base, $var, $val)</strong> - Decrement a numeric property by a specified amount</summary>

```php
$placer = 'hosts/master/documents/MyShow/layers/Placer';

// Decrement input-values
decrement($placer, 'tvGroup_Content__Opacity', 15);      // Slider: clamped to min/max
decrement($placer, 'tvGroup_Geometry__Rotation', 90);    // Wheel: wraps around

// Decrement direct properties
decrement($placer, 'volume', 0.2);
```

*Parameters and behavior identical to `increment()`, but subtracts the value instead of adding it.*
</details>

<details>
<summary><strong>pushComment($path, $comment_data)</strong> - Push a comment to mimoLive's comment system</summary>

```php
// Full path
pushComment('hosts/master/comments/new', [
    'username' => 'Anna Schmidt',
    'comment' => 'Super Stream! 🎉',
    'userimageurl' => 'https://i.pravatar.cc/150?img=1',
    'platform' => 'youtube',
    'favorite' => true
]);

// Short path (without /comments/new)
pushComment('hosts/master', [
    'username' => 'MaxGamer92',
    'comment' => 'Grüße aus München!',
    'platform' => 'twitch'
]);
```

**Parameters:**
- `$path` (required): Host path - either `'hosts/HOSTNAME/comments/new'` or `'hosts/HOSTNAME'`
- `$comment_data` (required): Array with comment data:
  - `username` (required): Display name of the commenter
  - `comment` (required): The comment text
  - `userimageurl` (optional): URL to the user's avatar image
  - `date` (optional): ISO8601 date string, defaults to current time
  - `platform` (optional): `facebook`, `twitter`, `youtube`, or `twitch`
  - `favorite` (optional): Boolean, mark as favorite

*Note: Comments are sent via GET request with URL parameters. Multiple comments queued in the same frame are sent separately (not merged). Requires the `/api/v1/comments/new` endpoint (since mimoLive 6.16b6 (30758)).*
</details>

---

## 🔊 Volume Control

<details>
<summary><strong>setVolume($namedAPI_path, $value)</strong> - Convenient shortcut to set volume/gain across different contexts</summary>

```php
// Automatically uses the correct property based on context:
setVolume($base, 0.8);                                    // Document: programOutputMasterVolume
setVolume($base.'layers/MEa', 0.5);                       // Layer: volume
setVolume($base.'layers/JoPhi DEMOS/variants/stop', 0.3); // Variant: volume
setVolume($base.'sources/a1', 1.2);                       // Source: gain
```

*Note: This is a convenience wrapper around `setValue()` that automatically selects the correct property name (`programOutputMasterVolume`, `volume`, or `gain`) based on whether you're targeting a document, layer/variant, or source.*
</details>

<details>
<summary><strong>setAnimateVolume($namedAPI_path, $target_value, $steps=null, $fps=null)</strong> - Animate volume/gain smoothly over time</summary>

```php
// Animate to 100% volume using default settings (30 steps @ 30fps = 1 second)
setAnimateVolume($base.'layers/MEa', 1.0);
setSleep(0);  // Execute all queued animation frames

// Animate with custom step count (60 steps @ 30fps = 2 seconds)
setAnimateVolume($base.'layers/MEa', 0.0, 60);
setSleep(0);

// Animate with custom speed (30 steps @ 15fps = 2 seconds, slower)
setAnimateVolume($base.'layers/MEa', 0.5, 30, 15);
setSleep(0);

// Fast animation (30 steps @ 60fps = 0.5 seconds)
setAnimateVolume($base.'layers/MEa', 1.0, 30, 60);
setSleep(0);

// Multiple layers animated in parallel
setAnimateVolume($base.'layers/MEv', 1.0, 30, 30);
setAnimateVolume($base.'layers/MEa', 1.0, 30, 30);
setSleep(0);  // Both animate together
```

**Parameters:**
- `$target_value` (required): Target volume (0.0 - 1.0)
- `$steps` (optional): Number of steps, defaults to framerate from config.ini (e.g., 30)
- `$fps` (optional): Animation speed in frames per second, defaults to framerate from config.ini (e.g., 30)

*Note: Reads current volume from namedAPI and interpolates to target value. Skips animation if already at target. Multiple animations on different layers run in parallel. Like `setVolume()`, automatically handles document/layer/source contexts.*
</details>

---

## 🎨 Animation

<details>
<summary><strong>setAnimateValue($namedAPI_path, $updates_array, $steps=null, $fps=null)</strong> - Animate any animatable property smoothly over time</summary>

```php
// Animate opacity and rotation together (30 steps @ 30fps = 1 second)
setAnimateValue($base.'layers/Placer', [
    'input-values' => [
        'tvGroup_Content__Opacity' => 100,
        'tvGroup_Geometry__Rotation' => 360
    ]
]);
setSleep(0);

// Animate color using mimoColor() (20 steps @ 30fps ≈ 0.67 seconds)
setAnimateValue($base.'sources/Color', [
    'input-values' => [
        'tvGroup_Background__Color' => mimoColor('#b700ff')
    ]
], 20, 30);
setSleep(0);

// Mix animatable and non-animatable properties
setAnimateValue($base.'sources/Color', [
    'input-values' => [
        'tvGroup_Content__Text_TypeMultiline' => 'Hello World!',  // Set once on frame 0
        'tvGroup_Background__Color' => mimoColor('#19e42d'),      // Animated over all frames
        'tvGroup_Geometry__Rotation' => 180                       // Animated (wheel: shortest path)
    ]
], 30, 30);
setSleep(2);
```

**Parameters:**
- `$updates_array` (required): Array of properties to animate (same structure as `setValue()`)
- `$steps` (optional): Number of steps, defaults to framerate from config.ini
- `$fps` (optional): Animation speed in frames per second, defaults to framerate from config.ini

**Supported property types:**
- **number**: Linear interpolation (e.g., opacity, position, size)
- **number with degrees (°)**: Wheel animation taking shortest path (e.g., rotation 180° → 0° goes via 90° → 0°, not via 270° → 360° → 0°)
- **color**: RGBA component interpolation (use `mimoColor()` for convenient color specification)
- **string, bool, index, image**: Not animatable - set once on frame 0

*Note: Like `setValue()`, accepts nested arrays with multiple properties. Animatable properties (number, color) interpolate smoothly across frames. Non-animatable properties (string, bool, etc.) are set once on the first frame only. Multiple `setAnimateValue()` calls on different resources run in parallel.*
</details>

---

## 📹 Media Control

<details>
<summary><strong>mediaControl($action, $path, $input=null)</strong> - Control media playback on sources or layers</summary>

```php
// === SOURCES (no input needed) ===
mediaControl('play', $base.'sources/Intro-Video');
mediaControl('pause', $base.'sources/Intro-Video');
mediaControl('stop', $base.'sources/Intro-Video');
mediaControl('skiptostart', $base.'sources/Intro-Video');
mediaControl('skiptoend', $base.'sources/Intro-Video');
mediaControl('skipback', $base.'sources/Intro-Video');    // Jump back ~10 seconds
mediaControl('skipahead', $base.'sources/Intro-Video');   // Jump ahead ~10 seconds
mediaControl('reverse', $base.'sources/Intro-Video');     // Play backwards
mediaControl('rewind', $base.'sources/Intro-Video');      // Fast rewind
mediaControl('fastforward', $base.'sources/Intro-Video'); // Fast forward
mediaControl('record', $base.'sources/Camera-Recording'); // Start/stop recording
mediaControl('shuffle', $base.'sources/Music-Playlist');  // Toggle shuffle mode
mediaControl('repeat', $base.'sources/Background-Music'); // Toggle repeat mode

// === LAYERS (input required: 'A'-'K' or full key like 'tvIn_VideoSourceAImage') ===
mediaControl('play', $base.'layers/Video-Playback', 'A');
mediaControl('pause', $base.'layers/Video-Playback', 'B');
mediaControl('stop', $base.'layers/Placer', 'tvIn_VideoSourceAImage');
mediaControl('skiptostart', $base.'layers/Video-Playback', 'C');

// === VARIANTS (path is shortened to layer level, input required) ===
mediaControl('play', $base.'layers/Video-Playback/variants/Intro', 'A');
mediaControl('pause', $base.'layers/Video-Playback/variants/Outro', 'A');
```

**Parameters:**
- `$action` (required): One of `play`, `pause`, `stop`, `reverse`, `rewind`, `fastforward`, `skiptostart`, `skiptoend`, `skipback`, `skipahead`, `record`, `shuffle`, `repeat`
- `$path` (required): Path to source, layer, or variant
- `$input` (optional): Required for layers/variants - input slot identifier ('A'-'K' or full key)

*Note: For variants, the path is automatically shortened to the layer level since media control operates on layer inputs. Invalid actions are rejected with a debug warning.*
</details>

<details>
<summary><strong>snapshot($path, $width=null, $height=null, $format=null, $filepath=null)</strong> - Capture screenshot from program output or source preview</summary>

```php
// Capture program output with defaults (dimensions/format from metadata)
snapshot($base);

// Custom dimensions and format
snapshot($base, 1920, 1080, 'png');

// Custom filepath
snapshot($base, 1920, 1080, 'png', './my-snapshots/custom.png');

// Capture source preview
snapshot($base.'sources/Camera 1');
```

*Note: Default save path is `./snapshots/` with auto-generated filename: `"ShowName 2026-01-24 12-34-56 DeviceName 1920x1080.png"`. Width/height/format are read from metadata if not specified. For documents, uses `/programOut` endpoint; for sources, uses `/preview` endpoint.*
</details>

<details>
<summary><strong>openWebBrowser($source_path)</strong> - Open the browser in a Web Browser Capture source</summary>

```php
// Open the web browser in a Web Browser source
openWebBrowser($base.'sources/Web Browser');
```

*Note: This function validates that the source is a Web Browser Capture source (`com.boinx.mimoLive.sources.webBrowserSource`) before sending the command.*
</details>

---

## ⚡ Signals

<details>
<summary><strong>trigger($signal_name, $path)</strong> - Trigger a signal on a layer, variant, source, or filter</summary>

```php
// Trigger signal on a layer
trigger('Dis 7', $base.'layers/Video Switcher');

// Trigger signal on a variant
trigger('Cut Below', $base.'layers/Video Switcher/variants/Auto');

// Trigger signal on a source
trigger('Reset', $base.'sources/MySource');

// Trigger signal on a filter
trigger('Pulse', $base.'sources/MySource/filters/MyFilter');
```

*Note: Signal names are normalized (spaces and underscores removed, case-insensitive). The function searches for matching signals in the path's input-values that end with `_TypeSignal`. For example, `'Dis 7'` matches `tvGroup_Control__Dis_7_TypeSignal`.*
</details>

---

## 💾 Data Stores

mimoLive allows storing persistent data inside the document file. These functions operate **synchronously** (not queued) - they execute immediately and return results directly.

<details>
<summary><strong>getDatastore($path, $keypath=null, $separator='/')</strong> - Read data from a datastore</summary>

```php
// Get entire store
$data = getDatastore($base.'datastores/game-state');
// → ['scores' => ['frank' => 4, 'paul' => 1], 'round' => 3]

// Get specific value via keypath
$frank_score = getDatastore($base.'datastores/game-state', 'scores/frank');
// → 4

// Get nested structure
$scores = getDatastore($base.'datastores/game-state', 'scores');
// → ['frank' => 4, 'paul' => 1]
```

*Returns `null` if store doesn't exist or keypath not found.*
</details>

<details>
<summary><strong>setDatastore($path, $data, $replace=false)</strong> - Write data to a datastore</summary>

```php
// Merge with existing data (default)
setDatastore($base.'datastores/game-state', [
    'scores' => ['anna' => 7]  // adds anna, keeps frank and paul
]);

// Replace entire store
setDatastore($base.'datastores/game-state', [
    'scores' => ['anna' => 7]  // frank and paul are gone
], replace: true);
```

*By default, deep-merges with existing data. Use `replace: true` to overwrite completely.*
</details>

<details>
<summary><strong>deleteDatastore($path)</strong> - Delete an entire datastore</summary>

```php
deleteDatastore($base.'datastores/game-state');
```

*Returns `true` on success, `false` if store didn't exist.*
</details>

---

## 🎥 Zoom Integration (BETA)

> ⚠️ **BETA**: These functions are partially implemented and need further testing. API parameters and behavior may change.

Control Zoom meetings directly from mimoLive. Requires a mimoLive Studio license.

<details>
<summary><strong>zoomJoin($host_path, $meetingid = null, $passcode = null, $options = [])</strong> - Join a Zoom meeting</summary>

```php
// Join with meeting ID and passcode
zoomJoin('hosts/master', '123456789', 'secret123');

// Join with options (display name, virtual camera)
zoomJoin('hosts/master', '123456789', 'secret123', [
    'displayname' => 'mimoLive Studio',
    'virtualcamera' => true,  // Send program output back to Zoom
    'zoomaccountname' => 'My Zoom Account'
]);

// Join a webinar (requires webinar token)
zoomJoin('hosts/master', '987654321', 'webinar123', [
    'webinartoken' => 'your-webinar-token-here'
]);

// Re-join with stored credentials (from previous call)
zoomJoin('hosts/master');
```

*Credentials are stored in the document's datastore and reused if not provided. Skips the join if already in meeting with same credentials.*

*Options: `displayname` (string), `zoomaccountname` (string), `virtualcamera` (bool), `webinartoken` (string - required for webinars)*
</details>

<details>
<summary><strong>Other Zoom Functions</strong></summary>

**zoomLeave($host_path)** - Leave the current meeting
```php
zoomLeave('hosts/master');
```

**zoomEnd($host_path)** - End the meeting (host only)
```php
zoomEnd('hosts/master');
```

**zoomParticipants($host_path)** - Get list of participants
```php
$participants = zoomParticipants('hosts/master');
// Returns array with participant data
```

**zoomMeetingAction($host_path, $command, $userid = null, $screentype = null)** - Execute meeting actions
```php
// Meeting-wide actions (no userid required)
zoomMeetingAction('hosts/master', 'muteAll');
zoomMeetingAction('hosts/master', 'lockMeeting');
zoomMeetingAction('hosts/master', 'lowerAllHands');

// Participant-specific actions (userid required)
zoomMeetingAction('hosts/master', 'muteVideo', '12345678');
zoomMeetingAction('hosts/master', 'unmuteAudio', '87654321');
```

**Available commands:**
- `requestRecordingPermission`
- `muteVideo`, `unmuteVideo` (require `$userid` parameter)
- `muteAudio`, `unmuteAudio` (require `$userid` parameter)
- `enableUnmuteBySelf`, `disableUnmuteBySelf`
- `muteAll`, `unmuteAll`
- `lockMeeting`, `unlockMeeting`
- `lowerAllHands`
- `shareFitWindowMode`
- `pauseShare`, `resumeShare`
- `joinVoip`, `leaveVoip`
- `allowParticipantsToChat`, `disallowParticipantsToChat`
- `allowParticipantsToShare`, `disallowParticipantsToShare`
- `allowParticipantsToStartVideo`, `disallowParticipantsToStartVideo`
- `allowParticipantsToShareWhiteBoard`, `disallowParticipantsToShareWhiteBoard`
- `enableAutoAllowLocalRecordingRequest`, `disableAutoAllowLocalRecordingRequest`
- `allowParticipantsToRename`, `disallowParticipantsToRename`
- `showParticipantProfilePictures`, `hideParticipantProfilePictures`

**namedAPI Data:**

When `zoom` is detected in your script, participant data is loaded into the namedAPI:
```php
// Check participant count (0 = no meeting)
$count = namedAPI_get('hosts/master/zoom/participants-count');

// Get participant details by name
$is_host = namedAPI_get('hosts/master/zoom/participants/Anika Patel/isHost');
$is_talking = namedAPI_get('hosts/master/zoom/participants/Anika Patel/isTalking');
```

*Available participant fields: id, isHost, isCoHost, isVideoOn, isAudioOn, isTalking, isRaisingHand, userRole*
</details>

---

## ⏱️ Timing & Flow Control

<details>
<summary><strong>setSleep($seconds, $reloadNamedAPI=true)</strong> - Execute all queued frames and optionally wait additional time</summary>

```php
setSleep(0);      // Execute all queued frames, no additional wait
setSleep(2.5);    // Execute all queued frames, then wait 2.5 seconds
setSleep(1, false); // Execute, wait 1 second, don't reload namedAPI
```

*Note: This is the primary execution function. It processes all frames currently in the queue (executing actions in parallel for each frame, with 1/framerate second sleep between frames). After all queued frames are processed, it waits for the specified `$seconds` duration. By default (`$reloadNamedAPI=true`), the namedAPI is rebuilt after execution, making updated values available for subsequent commands. Set to `false` to skip the rebuild if you don't need updated values. Framerate is read from `config.ini` (typically 25 or 30 FPS).*
</details>

<details>
<summary><strong>butOnlyIf($path, $operator, $value1, $value2=null)</strong> - Conditionally execute or skip the queued actions</summary>

```php
// Only turn off layers if ducking is disabled
setOff($base.'layers/Comments');
setOff($base.'layers/MEv');
setOff($base.'layers/MEa');
butOnlyIf($base.'layers/MEa/attributes/tvGroup_Ducking__Enabled', '==', false);
```

**What is a post condition?**

A post condition (`butOnlyIf`) evaluates the current state of the namedAPI **after** actions have been queued but **before** they are executed. If the condition evaluates to `false`, the entire queue is cleared and those actions are skipped.

**Supported Operators:**
- `==` - Equal to
- `!=` - Not equal to
- `<` - Less than
- `>` - Greater than
- `<=` - Less than or equal to
- `>=` - Greater than or equal to
- `<>` - Between (inclusive): `$value1 <= $current <= $value2`
- `!<>` - Not between

**How it works:**
1. Actions are added to the queue via `setLive()`, `setOff()`, or `recall()`
2. `butOnlyIf()` checks the condition against the current namedAPI state
3. If condition is `true`: Queue is processed normally
4. If condition is `false`: Queue is cleared, actions are skipped
5. Frame counter increments and execution continues

**Example:**
```php
$base = 'hosts/master/documents/MyShow/';

label_and_braces_not_necessary_just_to_see_it_better:
{
  // Queue the Comments layer to go live
  setLive($base.'layers/Comments');
  setLive($base.'layers/Lower3rd');

  // Only execute if YouTube stream is actually live, then wait 5 seconds
  butOnlyIf($base.'outputs/YouTube/live-state', '==', 'live', andSleep: 5);
}

// Turn off graphics (new queue, always executes)
setOff($base.'layers/Comments');
setOff($base.'layers/Lower3rd');
```

*Important: PHP's type juggling is used for value comparisons (`==`), allowing flexible matching between strings, numbers, and booleans. The string `"live"` will match the string `"live"`, `1` will match `true`, etc.*
</details>

---

## 🛠️ Helper Functions

<details>
<summary><strong>getID($path)</strong> - Get the ID of any resource (device, layer, source, etc.)</summary>

```php
// Returns the ID from namedAPI path, or none-source ID as fallback
$source_id = getID($base.'sources/Color');

// Use inline in setValue() arrays - this is the power of getID()!
setValue($base.'layers/MyLayer', [
    'source' => getID($base.'sources/Color'),  // Inline usage!
    'volume' => 0.5
]);

// Works with any resource type
$device_id = getID('hosts/master/devices/MyCamera');
$layer_id = getID($base.'layers/Comments');
$variant_id = getID($base.'layers/Lower3rd/variants/Red');
```

*Returns: The resource ID string, or `'2124830483-com.mimolive.source.nonesource'` if path not found*
</details>

<details>
<summary><strong>getSubType($path, $awaited=null)</strong> - Get the subtype/composition-id of any resource</summary>

```php
// Get layer type
$type = getSubType($base.'layers/Lower3rd');
// Returns: "2100989048-com.boinx.LowerThird"

// Get source type
$type = getSubType($base.'sources/Camera');
// Returns: "1836019824-com.boinx.VideoSource"

// Get filter type
$type = getSubType($base.'sources/Camera/filters/Blur');
// Returns: "2103787808-com.boinx.GaussianBlur"

// Check if resource is specific type (returns boolean)
if (getSubType($base.'layers/Lower3rd', '2100989048-com.boinx.LowerThird')) {
    echo "This is a Lower Third layer!";
}

// Works with variants (fallback to layer type if variant has no type)
$type = getSubType($base.'layers/Lower3rd/variants/Red');

// Layer-sets return "layer-set" string
$type = getSubType($base.'layer-sets/Graphics');
// Returns: "layer-set"

// Returns null if resource not found or has no type
$type = getSubType($base.'layers/NonExistent');
// Returns: null
```

*Returns: Type string (e.g., composition-id, source-type), "layer-set" for layer-sets, or `null` if not found. If `$awaited` is provided, returns `true` (matches), `false` (doesn't match), or `null` (resource doesn't exist).*
</details>

<details>
<summary><strong>isSubType($path, $awaited)</strong> - Check if a resource matches a specific subtype</summary>

```php
// More readable type checking (semantic wrapper for getSubType)
if (isSubType($base.'layers/Lower3rd', '2100989048-com.boinx.LowerThird')) {
    echo "This is a Lower Third layer!";
}

// Check source type
if (isSubType($base.'sources/Camera', 'com.boinx.mimoLive.sources.deviceVideoSource')) {
    echo "This is a device video source!";
}

// Check layer-set
if (isSubType($base.'layer-sets/Graphics', 'layer-set')) {
    echo "This is a layer-set!";
}

// Handle non-existent resources
$result = isSubType($base.'layers/NonExistent', 'some-type');
// Returns: null (resource doesn't exist)
```

*Returns: `true` (matches), `false` (doesn't match), or `null` (resource doesn't exist)*
</details>

<details>
<summary><strong>mimoColor($color_string)</strong> - Convert color strings to mimoLive color format</summary>

```php
// Hex format (1-8 characters)
mimoColor('#F')          // → White (#FFFFFFFF)
mimoColor('#FA')         // → White, but a bit of transparency (#FFFFFFAA)
mimoColor('#F73')        // → RGB shorthand (#FF7733FF)
mimoColor('#F73A')       // → RGBA shorthand (#FF7733AA)
mimoColor('#FF5733')     // → Full RGB (#FF5733FF)
mimoColor('#FF5733AA')   // → Full RGBA (#FF5733AA)

// RGB/RGBA format (0-255)
mimoColor('255,128,64')      // → RGB
mimoColor('255,128,64,200')  // → RGBA

// Percentage format
mimoColor('100%,50%,25%')       // → RGB
mimoColor('100%,50%,25%,80%')   // → RGBA

// Use in setValue with color properties
setValue($base.'sources/Color', [
    'input-values' => [
        'tvGroup_Background__Color' => mimoColor('#FF0000')
    ]
]);
```

*Returns: `['red' => float, 'green' => float, 'blue' => float, 'alpha' => float]` with values 0-1*
</details>

<details>
<summary><strong>mimoPosition($prefix, $width, $height, $top, $left, $namedAPI_path)</strong> - Calculate position/dimensions in mimoLive units</summary>

```php
// Pixel values
setValue($base.'layers/MEv/variants/dyn', [
    'input-values' => [
        ...mimoPosition('tvGroup_Geometry__Window', 800, 600, 100, 200, $base)
    ]
]);

// Percentage values
...mimoPosition('tvGroup_Geometry__Window', '50%', '40%', '10%', '25%', $base)
```

*Returns: Array with `_Left_TypeBoinxX`, `_Top_TypeBoinxY`, `_Right_TypeBoinxX`, `_Bottom_TypeBoinxY` keys*
</details>

<details>
<summary><strong>mimoCrop($prefix, $top, $bottom, $left, $right, $namedAPI_path=null)</strong> - Calculate crop values in percentages</summary>

```php
// Percentage values (no path needed)
...mimoCrop('tvGroup_Geometry__Crop', '10%', '10%', '5%', '5%')

// Pixel values (uses source resolution from path)
...mimoCrop('tvGroup_Geometry__Crop', 50, 50, 100, 100, $base.'sources/Camera')
```

*Returns: Array with `_Top`, `_Bottom`, `_Left`, `_Right` keys (percentage values)*
</details>

---

## 🚀 Advanced Features

### Auto Grid Layout

<details>
<summary><strong>setAutoGrid(...)</strong> - Automatically arrange video placers in intelligent layouts for video conferences</summary>

```php
setAutoGrid($document_path, $gap, $color_default, $color_highlight, $top=0, $left=0, $bottom=0, $right=0, $threshold=-65.0, $audioTracking=true, $audioTrackingAutoSwitching=false)
```

**Overview:**

Creates balanced layouts for multiple video sources with automatic aspect-ratio preservation:
- **Presenter Mode**: Main presenter with smaller participants on sides
- **Groups Mode**: Multiple equal groups arranged side-by-side or stacked
- **Exclusive Mode**: One fullscreen, others hidden
- **Automatic Expansion**: Visible layers expand when others are hidden

**Basic Usage:**

```php
$base = 'hosts/master/documents/MyShow';

// Simple grid with 2% gap, 30px margins (speaker highlight enabled)
setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 30, 30, 30, 30);

// Fullscreen without borders/rounding (gap=0)
setAutoGrid($base, 0, '#FFFFFFFF', '#FF00FFFF', 0, 0, 0, 0);

// Space at bottom for lower third (300px)
setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 30, 30, 300, 30);

// Space at top for title/logo (300px)
setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 300, 30, 30, 30);

// With auto-switching: audio-only participants auto-show when speaking
setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 30, 30, 30, 30, -65.0, true, true);

// Without speaker highlight (always default color)
setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 30, 30, 30, 30, -65.0, false, false);
```

**Layer Naming Convention:**

The system requires specific layer naming:

1. **Video Placer Layers:**
   ```
   av_pos_1_group_1    // Position 1, Group 1
   av_pos_2_group_1    // Position 2, Group 1
   av_pos_1_group_2    // Position 1, Group 2
   av_presenter        // Presenter (optional)
   ```

2. **Audio-Only Layers (optional):**
   ```
   a_pos_1_group_1     // Audio for position 1, group 1
   a_presenter         // Audio for presenter
   ```
   When an `a_*` layer exists for a position, the corresponding `av_*` layer always gets `volume: 0` and the `a_*` layer receives the audio volume. This also enables speaker detection (highlight color).

3. **Control Script Layers (with variants):**
   ```
   s_av_pos_1_group_1  // Controls av_pos_1_group_1
   s_av_pos_2_group_1  // Controls av_pos_2_group_1
   s_av_presenter      // Controls av_presenter
   ```

**Required Variants:**

Each control layer (`s_av_*`) MUST have these 5 variants:

| Variant | Meaning | Video | Audio |
|---------|---------|-------|-------|
| `video-and-audio` | Normally visible | ✓ Yes | ✓ Yes |
| `video-no-audio` | Video without sound | ✓ Yes | ✗ No |
| `audio-only` | Audio only, no video | ✗ No | ✓ Yes |
| `off` | Completely off | ✗ No | ✗ No |
| `exclusive` | Fullscreen trigger | ✓ Fullscreen | ✓ Yes |

Additional status: `exclude` when control layer itself is off (live-state)

**Modes:**

*Presenter Mode* (active when `s_av_presenter` exists AND is visible):
- Presenter centered (sized to fit available space, aspect-ratio preserved)
- Other positions alternating left/right (zig-zag)
- Position number determines vertical order (1=top, 2=below, etc.)
- Side tiles are square, sized based on available height
- If only presenter + 1 position visible: Position hidden, presenter takes full space

*Groups Mode* (active when NO presenter is visible):
- Groups arranged horizontally (16:9) or vertically (9:16)
- Each group gets equal space
- Within each group: Grid layout based on number of visible layers
- If only 1 group has visible layers: Takes full working area
- If only 1 layer in group: Takes full group size

*Exclusive Mode* (active when ONE control layer is on `exclusive` variant):
- Exclusive layer takes full working area (fullscreen)
- All other layers shrink to size 0 at center
- Exclusive layer gets `volume: 1.0`
- Other layers keep their audio (NOT muted)
- If 2+ exclusives simultaneously: Session-based transition logic triggers

**Expansion:**

When layers are hidden (status: `exclude`, `off`, `audio-only`):
- Visible layers expand to optimally use available space
- Hidden layers shrink to center of their original relative position

**Speaker Detection (via Audio Layers):**

When `a_*` layers exist, the system automatically highlights the active speaker:
- Border color switches from `$color_default` to `$color_highlight` when audio level exceeds `$threshold`
- Audio level is read from the `a_*` layer's `output-values/tvOut_VideoSourceAAudioLevel`
- Detection triggers when: `audio_level != 0 AND audio_level > $threshold`
- **Important**: If no `a_*` layer exists for a position, no highlight is applied (only default color)

**Parameters:**
- `$document_path` (required): Document path (e.g., `'hosts/master/documents/MyShow'`)
- `$gap` (required): Space between placers - percentage (e.g., `'2%'`) or pixels (e.g., `20`)
  - `gap = 0`: Fullscreen mode → No borders, no rounding
  - `gap > 0`: Grid mode → Borders (double standard thickness), rounded corners
- `$color_default` (required): Border color for normal placers (hex, e.g., `'#FFFFFFFF'`)
- `$color_highlight` (required): Border color for active speaker (hex, e.g., `'#FF00FFFF'`)
- `$top` (optional): Top margin - pixels or percentage (e.g., `30` or `'10%'`), default `0`
- `$left` (optional): Left margin - pixels or percentage, default `0`
- `$bottom` (optional): Bottom margin - pixels or percentage, default `0`
- `$right` (optional): Right margin - pixels or percentage, default `0`
- `$threshold` (optional): Audio level threshold for speaker detection in dB, default `-65.0`
- `$audioTracking` (optional): Enable speaker highlight color, default `true`
  - `true`: Border switches to `$color_highlight` when speaker is active
  - `false`: Always uses `$color_default` (no speaker detection)
- `$audioTrackingAutoSwitching` (optional): Auto-activate video when audio-only speaker talks, default `false`
  - When `true`: Layers with status `audio-only` automatically switch to `video-and-audio` when speaking
  - Only switches ON (never automatically switches OFF)
  - Presenter (`s_av_presenter`) is excluded from auto-switching
  - Disabled when any layer is in `exclusive` mode

**Examples:**

Standard conference (Presenter + 6 participants):
```php
// Setup: s_av_presenter + s_av_pos_1..6_group_1
setAutoGrid($base, '2%', '#FFFFFF', '#FF00FF', 30, 30, 30, 30);
```

Two groups (Team A vs Team B):
```php
// Setup: s_av_pos_1..4_group_1 + s_av_pos_1..4_group_2
setAutoGrid($base, '1.5%', '#FFFFFF', '#00FFFF', 50, 50, 50, 50);
```

Seamless fullscreen:
```php
// No gap = no borders, no rounding
setAutoGrid($base, 0, '#FFFFFF', '#FF00FF', 0, 0, 0, 0);
```

Auto-switching conference (audio-only participants auto-show when speaking):
```php
// Setup: s_av_pos_1..6_group_1 with some on audio-only
// When someone on audio-only speaks, they auto-switch to video-and-audio
setAutoGrid($base, '2%', '#FFFFFF', '#FF00FF', 30, 30, 30, 30, -65.0, true, true);
```

**mimoLive Control Surface Compatibility:**

`setAutoGrid()` is fully compatible with mimoLive's Control Surfaces feature. You can create intuitive button layouts to switch between variants for each position:

![mimoLive Control Surface Example](assets/mimoLive-control-surface.png)

The example document `autoGridTest.tvShow` demonstrates this setup with a complete Control Surface configuration.
</details>

<details>
<summary><strong>setPIPWindowLayerAppearance(...)</strong> - Set all appearance properties for a PIP Window (Video Placer) layer in one call</summary>

```php
setPIPWindowLayerAppearance($layer, $w, $h, $y, $x, $doc_path, $border_color, $border_width, $corner_radius, $volume)
```

Combines position, border styling and volume into a single setValue() call. Useful for custom video layouts outside of setAutoGrid().

**Parameters:**
- `$layer`: Full namedAPI path to the video layer (e.g., `$base.'/layers/av_pos_1_group_1'`)
- `$w`, `$h`: Width and height in pixels
- `$y`, `$x`: Top and left position in pixels
- `$doc_path`: Document path (for coordinate conversion)
- `$border_color`: Border color as hex string (e.g., `'#FFFFFFFF'`)
- `$border_width`: Border width (use 0 for no border)
- `$corner_radius`: Corner radius (use 0 for sharp corners)
- `$volume`: Audio volume (0.0 to 1.0)

```php
$base = 'hosts/master/documents/MyShow';
$layer = $base.'/layers/av_pos_1_group_1';

// Position layer at 100,50 with size 800x450, white border, full volume
setPIPWindowLayerAppearance($layer, 800, 450, 50, 100, $base, '#FFFFFFFF', 2, 10, 1.0);

// Shrink layer to center point (size 0), no border, muted
setPIPWindowLayerAppearance($layer, 0, 0, 540, 960, $base, '#FFFFFFFF', 0, 0, 0.0);
```
</details>

<details>
<summary><strong>wait($seconds)</strong> - Pause execution without processing frames (internal use)</summary>

```php
wait(1.0); // Wait 1 second, no frame processing
```

*Note: Use `setSleep()` for timed sequences.*
</details>

---

## 🐛 Debugging & Development

Use Firefox to call `?list`, `translate`, `?{any}&test=true`, or `?{any}&realtime=true`. It can render json.

### Execution Modes

<details>
<summary><strong>?f=scriptname</strong> - Background execution (default)</summary>

- Script executes immediately and returns success message
- No waiting, no debug output
- Use for production automation calls from mimoLive
</details>

<details>
<summary><strong>?f=scriptname&test=true</strong> - Test mode with debug output</summary>

- Waits for script completion
- Shows detailed debug information (API calls, calculations, etc.)
- Returns complete execution log as JSON
- Use for development and debugging
</details>

<details>
<summary><strong>?f=scriptname&realtime=true</strong> - Realtime execution without debug output</summary>

- Waits for script completion
- No debug output (clean execution)
- Returns final result as JSON
- **Perfect for loops** (scripts < 10 seconds that run repeatedly)
- **Ideal for `setAutoGrid()`** - mimoLive can loop the call and get updated status in realtime

Example in mimoLive Automation Layer (While Live script):
```php
httpRequest("http://localhost:8888/?f=update-grid&realtime=true");
```
This allows mimoLive to continuously update the grid layout as participant states change.
</details>

### List API Keypaths

The `?list` endpoint provides introspection into the current namedAPI state, making it easy to discover available keypaths and their values.

<details>
<summary><strong>/?list</strong> - Returns all keypaths with their current values as a flat JSON structure</summary>

```zsh
curl http://localhost:8888/?list | jq
```
</details>

<details>
<summary><strong>/?list=filter</strong> - Returns only keypaths containing the filter string (case-insensitive)</summary>

```zsh
# Show all live-state values
curl http://localhost:8888/?list=live-state | jq

# Show all layer information
curl http://localhost:8888/?list=layers | jq

# Find specific layer data
curl http://localhost:8888/?list=layers/Comments | jq

# Filter by multiple terms (semicolon-separated, all must match)
curl http://localhost:8888/?list=layers;live-state | jq
curl http://localhost:8888/?list=hosts;master;layers | jq
```

**Use cases:**
- Discover all the available pathes (for copy and paste?)
- Find the exact keypath for a specific resource
- Monitor API state during development
</details>

### Count and Analyze API Values

The `?count` endpoint aggregates and counts values across the API, grouped by resource type. Perfect for analyzing what's available in your system.

<details>
<summary><strong>/?count=filter</strong> - Counts occurrences of values matching the filter, grouped by pattern</summary>

```zsh
# Analyze all types in the system
curl http://localhost:8888/?count=master;/type | jq

# Count live-states across resources
curl http://localhost:8888/?count=master;live-state | jq

# Analyze source types available
curl http://localhost:8888/?count=sources;/source-type | jq

# Check device types
curl http://localhost:8888/?count=devices;/device-type | jq
```

**Example output:**
```json
{
  "Layers": {
    "type: number": 151,
    "type: index": 52,
    "type: color": 42,
    "type: string": 31
  },
  "Sources": {
    "source-type: com.boinx.mimoLive.sources.deviceVideoSource": 3,
    "source-type: com.boinx.boinxtv.source.placeholder": 1,
    "source-type: com.boinx.mimoLive.sources.zoomparticipant": 1
  },
  "Webcontrol": {
    "type: setLive": 2,
    "type: toggleLive": 2,
    "type: setOff": 1
  }
}
```

**Grouping patterns:**

Values are automatically grouped by their location in the API hierarchy:
- `Zoom` - Zoom participant data
- `Webcontrol` - Remote control canvases
- `Filters` - Source filters
- `Variants` - Layer variants
- `Layers` - Layer resources
- `Sources` - Source resources
- `Output-Destinations` - Hardware outputs
- `Outputs` - Streaming/recording outputs
- `Layer-Sets` - Layer sets
- `Devices` - Video/audio devices
- `Documents` - Document-level data
- `Hosts` - Host-level data

**Use cases:**
- **Feature detection** - Check if specific source types exist before using them
- **API discovery** - See what types of values are available
- **System inventory** - Count how many layers, sources, outputs you have
- **Type analysis** - Understand the data structure and available fields
</details>

### Translate mimoLive API URLs to Keypaths

The `?translate` endpoint converts mimoLive API URLs into namedAPI keypaths, useful when working with the mimoLive HTTP API directly.

<details>
<summary><strong>/?translate=/api/v1/documents/{doc_id}/...</strong> - Converts API URL to keypath</summary>

```bash
# Translate a layer URL
curl "http://localhost:8888/?translate=/api/v1/documents/2124830483/layers/AC981F10-56A1-4206-A441-CEB13ED240A4" | jq

# Translate a variant URL
curl "http://localhost:8888/?translate=/api/v1/documents/2124830483/layers/AC981F10-56A1-4206-A441-CEB13ED240A4/variants/6F2105C4-6AFB-4300-B6C6-0D65F00BCD75" | jq
```

**Example output:**
```json
{
  "path": "hosts/master/documents/forbiddenPHP/layers/RunAndStop/variants/stop",
  "code": 200
}
```

**Use cases:**
- Convert API URLs from mimoLive's Copy API Endpoint feature to script-friendly keypaths
- Debug API responses by translating returned resource URLs
- Quickly find the keypath when you know the UUID from API logs
</details>

---

## 📚 Notes

### Using Standard PHP Control Structures

You can use regular PHP control flow (`if`, `switch`, even own functions etc.) to check values before queueing actions or set a block:

```php
// Check namedAPI state at script start
if (namedAPI_get($base.'layers/Comments/live-state') == 'live') {
    setOff($base.'layers/Comments');
}

// Check results after executing a block
setValue($base.'layers/Lower3rd', ['opacity' => 0.5]);
setSleep(1);

if (namedAPI_get($base.'layers/Lower3rd/opacity') == 0.5) {
    setLive($base.'layers/Lower3rd');
}

// Create interactive blocks with switch
$current_variant = namedAPI_get($base.'layers/Lower3rd/live-variant-name');
switch ($current_variant) {
    case 'Red':
        setLive($base.'layers/Lower3rd/variants/Blue');
        setSleep(5);
        break;
    case 'Blue':
        setLive($base.'layers/Lower3rd/variants/Green');
        setSleep(3);
        break;
    default:
        setLive($base.'layers/Lower3rd/variants/Red');
        setSleep(2);
}
```

See `setSleep()` documentation for details on when the namedAPI is reloaded.

---

*Note: If you debug your script, you can use `run();`. It executes the block(s) above and exits afterwards. Following blocks are ignored.*
