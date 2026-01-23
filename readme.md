# mimoLive Automation System

## What is it?

A lightweight, procedural PHP automation system for controlling mimoLive via its HTTP API. Built with a frame-based queue system for precise timing control, it provides a flat keypath-based interface to the entire mimoLive API structure, enabling sophisticated automation workflows without object-oriented overhead.

The system loads the complete mimoLive API hierarchy (documents, layers, variants, layer-sets, outputs, sources, filters) into a flat named structure accessible via keypaths like `hosts/master/documents/MyShow/layers/Comments/live-state`, making it easy to control and monitor your live production programmatically.

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
  $base = 'hosts/master/documents/MyShow/';
  setLive($base.'layers/Comments');
  setLive($base.'layers/Title/variants/blue');
  ```

- **`setOff($namedAPI_path)`** - Turn a layer off
  ```php
  setOff($base.'layers/Comments');
  ```

- **`recall($namedAPI_path)`** - Recall a layer-set
  ```php
  recall($base.'layer-sets/Intro');
  ```
  *Note: After recall executes, the entire namedAPI is rebuilt to reflect the new state.*

- **`setValue($namedAPI_path, $updates_array)`** - Update properties of documents, layers, variants, sources, filters, or outputs
  ```php
  // Document-level properties
  setValue($base, ['programOutputMasterVolume' => 0.8]);

  // Layer properties
  setValue($base.'layers/Audio', ['volume' => 0.5]);

  // Variant properties
  setValue($base.'layers/Title/variants/blue', ['volume' => 0.3]);

  // Source properties
  setValue($base.'sources/Camera1', ['gain' => 1.2]);

  // Source filter properties
  setValue($base.'sources/Camera1/filters/ColorCorrection', [
      'input-values' => [
          'tvGroup_Content__Brightness' => 0.5
      ]
  ]);

  // Set multiple properties at once
  setValue($base.'layers/Audio', ['volume' => 0.5, 'opacity' => 0.8]);

  // Layer input-values
  setValue($base.'layers/Title', [
      'input-values' => [
          'tvGroup_Content__Title' => 'New Title',
          'tvGroup_Content__Subtitle' => 'Subtitle'
      ]
  ]);

  // Mix properties and input-values
  setValue($base.'layers/Audio', [
      'volume' => 0.5,
      'input-values' => ['tvGroup_Control__Mute' => false]
  ]);
  ```
  *Note: Changes are queued in the current frame and execute in parallel with other actions. The namedAPI is updated after successful execution.*

- **`setVolume($namedAPI_path, $value)`** - Convenient shortcut to set volume/gain across different contexts
  ```php
  // Automatically uses the correct property based on context:
  setVolume($base, 0.8);                           // Document: programOutputMasterVolume
  setVolume($base.'layers/Audio', 0.5);            // Layer: volume
  setVolume($base.'layers/Audio/variants/v1', 0.3); // Variant: volume
  setVolume($base.'sources/Camera1', 1.2);         // Source: gain
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
  setLive($base.'layers/Comments');
  butOnlyIf($base.'outputs/YouTube/live-state', '==', 'live');
  ```

### Helper Functions

These functions are available but are typically used internally or for advanced use cases:

- **`wait($seconds)`** - Pause execution without processing frames
  ```php
  wait(1.0); // Wait 1 second, no frame processing
  ```
  *Note: Use `setSleep()` for timed sequences. `wait()` is for simple delays without frame processing.*

- **`run($sleep=0)`** - Execute the automation script (called automatically, rarely needed in user scripts)
  ```php
  run(); // Process immediately
  run(5); // Process with 5 second initial sleep
  ```

### Post Condition

- **`butOnlyIf($path, $operator, $value1, $value2=null)`** - Conditionally execute or skip the queued actions
  ```php
  setLive($base.'layers/Comments');
  butOnlyIf($base.'outputs/YouTube/live-state', '==', 'live');
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

// Only execute if YouTube stream is actually live
butOnlyIf($base.'outputs/YouTube/live-state', '==', 'live');

// Wait 5 seconds
setSleep(5);

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


