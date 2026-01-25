# mimoLive Automation System

## What is it?

A lightweight, procedural PHP automation system for controlling mimoLive via its HTTP API. Built with a frame-based queue system for precise timing control, it provides a flat keypath-based interface to the entire mimoLive API structure, enabling sophisticated automation workflows without object-oriented overhead.

The system loads the complete mimoLive API hierarchy (documents, layers, variants, layer-sets, outputs, sources, filters) into a flat named structure accessible via keypaths like `hosts/master/documents/MyShow/layers/Comments/live-state`, making it easy to control and monitor your live production programmatically.

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

## How to call scripts from MimoLive Automation Layer?
  ```
   // Call a prepared Script from scripts-Folder (without .php):
   httpRequest(http://localhost:8888/?f=scriptname)

   // Call an inline action (must be urlencoded!):
   httpRequest(http://localhost:8888/?q=setLive%28%27fullpath%27%29)
  ```

## Supported Commands

**Note**: For brevity, examples use `$base = 'hosts/master/documents/MyShow/';`

### Primary Commands

These are the officially supported commands for controlling mimoLive:

#### Control Commands

- **`setLive($namedAPI_path)`** - Turn a layer or variant live
  ```php
  $base = 'hosts/master/documents/forbiddenPHP/';
  setLive($base.'layers/Comments');
  setLive($base.'layers/JoPhi DEMOS/variants/stop');
  setLive($base.'output-destinations/TV out');
  ```

- **`setOff($namedAPI_path)`** - Turn a layer off
  ```php
  setOff($base.'layers/Comments');
  setOff($base.'layers/MEv');
  setOff($base.'output-destinations/TV out');
  ```

- **`toggleLive($namedAPI_path)`** - Toggle a layer, variant, or document live state
  ```php
  toggleLive($base.'layers/Comments');  // Toggle layer on/off
  toggleLive($base.'layers/Lower3rd/variants/Red');  // Toggle variant
  toggleLive($base);  // Toggle document live state
  ```
  *Note: After toggleLive executes, the entire namedAPI is rebuilt to reflect the new state.*

- **`recall($namedAPI_path)`** - Recall a layer-set
  ```php
  recall($base.'layer-sets/RunA');
  recall($base.'layer-sets/OFF');
  ```
  *Note: After recall executes, the entire namedAPI is rebuilt to reflect the new state.*

#### Variant Cycling Commands

- **`cycleThroughVariants($layer_path)`** - Cycle to next variant (wraps around to first)
  ```php
  cycleThroughVariants($base.'layers/Lower3rd');
  // Works with variant paths too (will be stripped to layer path):
  cycleThroughVariants($base.'layers/Lower3rd/variants/Red');
  ```
  *Note: Cycles through all variants continuously. After the last variant, returns to the first.*

- **`cycleThroughVariantsBackwards($layer_path)`** - Cycle to previous variant (wraps around to last)
  ```php
  cycleThroughVariantsBackwards($base.'layers/Lower3rd');
  ```
  *Note: Cycles through all variants in reverse. Before the first variant, returns to the last.*

- **`bounceThroughVariants($layer_path)`** - Cycle to next variant (stops at last)
  ```php
  bounceThroughVariants($base.'layers/Lower3rd');
  ```
  *Note: Stops at the last variant instead of wrapping around. Safe for linear progressions.*

- **`bounceThroughVariantsBackwards($layer_path)`** - Cycle to previous variant (stops at first)
  ```php
  bounceThroughVariantsBackwards($base.'layers/Lower3rd');
  ```
  *Note: Stops at the first variant instead of wrapping around.*

- **`setLiveFirstVariant($layer_path)`** - Jump to first variant
  ```php
  setLiveFirstVariant($base.'layers/Lower3rd');
  ```

- **`setLiveLastVariant($layer_path)`** - Jump to last variant
  ```php
  setLiveLastVariant($base.'layers/Lower3rd');
  ```

#### Signal Triggering

- **`trigger($signal_name, $path)`** - Trigger a signal on a layer, variant, source, or filter
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

#### Screenshot/Snapshot

- **`snapshot($path, $width=null, $height=null, $format=null, $filepath=null)`** - Capture screenshot from program output or source preview
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

#### Web Browser Control

- **`openWebBrowser($source_path)`** - Open the browser in a Web Browser Capture source
  ```php
  // Open the web browser in a Web Browser source
  openWebBrowser($base.'sources/Web Browser');
  ```
  *Note: This function validates that the source is a Web Browser Capture source (`com.boinx.mimoLive.sources.webBrowserSource`) before sending the command.*

#### Property Updates

- **`setValue($namedAPI_path, $updates_array)`** - Update properties of documents, layers, variants, sources, filters, or outputs
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
  *Note: Changes are queued in the current frame and execute in parallel with other actions. The namedAPI is updated after successful execution.*

- **`setVolume($namedAPI_path, $value)`** - Convenient shortcut to set volume/gain across different contexts
  ```php
  // Automatically uses the correct property based on context:
  setVolume($base, 0.8);                                    // Document: programOutputMasterVolume
  setVolume($base.'layers/MEa', 0.5);                       // Layer: volume
  setVolume($base.'layers/JoPhi DEMOS/variants/stop', 0.3); // Variant: volume
  setVolume($base.'sources/a1', 1.2);                       // Source: gain
  ```
  *Note: This is a convenience wrapper around `setValue()` that automatically selects the correct property name (`programOutputMasterVolume`, `volume`, or `gain`) based on whether you're targeting a document, layer/variant, or source.*

#### Timing Commands

- **`setSleep($seconds)`** - Process all queued frames for the specified duration
  ```php
  setSleep(2.5); // Process frames for 2.5 seconds (executes all frames, sleeps between them)
  ```
  *Note: For each frame in the duration, queued actions are executed first, then the system sleeps for 1/framerate seconds before advancing to the next frame. The final frame executes without a trailing sleep. Framerate is automatically detected from the mimoLive document metadata (typically 25 or 30 FPS).*

#### Conditional Execution

- **`butOnlyIf($path, $operator, $value1, $value2=null)`** - Conditionally execute or skip the queued actions
  ```php
  // Only turn off layers if ducking is disabled
  setOff($base.'layers/Comments');
  setOff($base.'layers/MEv');
  setOff($base.'layers/MEa');
  butOnlyIf($base.'layers/MEa/attributes/tvGroup_Ducking__Enabled', '==', false);
  ```

### Helper Functions

These functions simplify common tasks:

- **`getID($path)`** - Get the ID of any resource (device, layer, source, etc.)
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

- **`mimoColor($color_string)`** - Convert color strings to mimoLive color format
  ```php
  // Hex format (1-8 characters)
  mimoColor('#F')          // → Gray (#FFFFFFAA)
  mimoColor('#FF')         // → Gray with alpha (#FFFFFFAA)
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

- **`mimoPosition($prefix, $width, $height, $top, $left, $namedAPI_path)`** - Calculate position/dimensions in mimoLive units
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

- **`mimoCrop($prefix, $top, $bottom, $left, $right, $namedAPI_path=null)`** - Calculate crop values in percentages
  ```php
  // Percentage values (no path needed)
  ...mimoCrop('tvGroup_Geometry__Crop', '10%', '10%', '5%', '5%')

  // Pixel values (uses source resolution from path)
  ...mimoCrop('tvGroup_Geometry__Crop', 50, 50, 100, 100, $base.'sources/Camera')
  ```
  *Returns: Array with `_Top`, `_Bottom`, `_Left`, `_Right` keys (percentage values)*

### Advanced/Internal Functions

These functions are available but are typically used internally:

- **`wait($seconds)`** - Pause execution without processing frames (internal use)
  ```php
  wait(1.0); // Wait 1 second, no frame processing
  ```
  *Note: Use `setSleep()` for timed sequences.*

- **`setSleep($seconds, $reloadNamedAPI=true)`** - Execute queue and wait (block boundary)
  ```php
  setSleep(2);  // Execute, wait 2s, reload namedAPI
  setSleep(1, false);  // Execute, wait 1s, no reload
  ```

*Note: `run()` is called automatically at script end and terminates execution. Rarely needed in user scripts.*

### Post Condition

- **`butOnlyIf($path, $operator, $value1, $value2=null, $andSleep=0)`** - Conditionally execute queued actions
  ```php
  setLive($base.'layers/Comments');
  butOnlyIf($base.'layers/MEa/volume', '==', 0);

  // With sleep after execution
  setOff($base.'layers/MEa');
  butOnlyIf($base.'layers/MEa/live-state', '==', 'live', andSleep: 2);
  ```

## What is a post condition?

A post condition (`butOnlyIf`) evaluates the current state of the namedAPI **after** actions have been queued but **before** they are executed. If the condition evaluates to `false`, the entire queue is cleared and those actions are skipped.

### Supported Operators

- `==` - Equal to
- `!=` - Not equal to
- `<` - Less than
- `>` - Greater than
- `<=` - Less than or equal to
- `>=` - Greater than or equal to
- `<>` - Between (inclusive): `$value1 <= $current <= $value2`
- `!<>` - Not between

### How it works

1. Actions are added to the queue via `setLive()`, `setOff()`, or `recall()`
2. `butOnlyIf()` checks the condition against the current namedAPI state
3. If condition is `true`: Queue is processed normally
4. If condition is `false`: Queue is cleared, actions are skipped
5. Frame counter increments and execution continues

### Example

```php
$base = 'hosts/master/documents/MyShow/';

// Queue the Comments layer to go live
setLive($base.'layers/Comments');
setLive($base.'layers/Lower3rd');

// Only execute if YouTube stream is actually live, then wait 5 seconds
butOnlyIf($base.'outputs/YouTube/live-state', '==', 'live', andSleep: 5);

// Turn off graphics (new queue, always executes)
setOff($base.'layers/Comments');
setOff($base.'layers/Lower3rd');
```

**Important**: PHP's type juggling is used for value comparisons (`==`), allowing flexible matching between strings, numbers, and booleans. The string `"live"` will match the string `"live"`, `1` will match `true`, etc.

## Debug Helpers for your scripts

### List API Keypaths

The `?list` endpoint provides introspection into the current namedAPI state, making it easy to discover available keypaths and their values.

- **`/?list`** - Returns all keypaths with their current values as a flat JSON structure
  ```bash
  curl http://localhost:8888/?list | jq
  ```

- **`/?list=filter`** - Returns only keypaths containing the filter string (case-insensitive)
  ```bash
  # Show all live-state values
  curl http://localhost:8888/?list=live-state | jq

  # Show all layer information
  curl http://localhost:8888/?list=layers | jq

  # Find specific layer data
  curl http://localhost:8888/?list=layers/Comments | jq
  ```

**Use cases:**
- Discover all the available pathes (for copy and paste?)
- Find the exact keypath for a specific resource
- Monitor API state during development

### Translate mimoLive API URLs to Keypaths

The `?translate` endpoint converts mimoLive API URLs into namedAPI keypaths, useful when working with the mimoLive HTTP API directly.

- **`/?translate=/api/v1/documents/{doc_id}/...`** - Converts API URL to keypath
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

## Notes

### setValue() and setVolume()

The `setValue()` function is a universal property updater that works across the entire mimoLive API hierarchy:
- **Documents**: Set properties like `programOutputMasterVolume`
- **Layers & Variants**: Set `volume`, `opacity`, `input-values`, etc.
- **Sources**: Set `gain` and other source properties
- **Filters**: Set filter `input-values` and parameters
- **Outputs**: Set output-destination properties

The `setVolume()` function is a convenience wrapper that automatically chooses the correct volume property based on context, eliminating the need to remember whether to use `programOutputMasterVolume`, `volume`, or `gain`.


