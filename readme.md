# mimoLive Automation System

## What is it?

A lightweight, procedural PHP automation system for controlling mimoLive via its HTTP API. Built with a frame-based queue system for precise timing control, it provides a flat keypath-based interface to the entire mimoLive API structure, enabling sophisticated automation workflows without object-oriented overhead.

The system loads the complete mimoLive API hierarchy (documents, layers, variants, layer-sets, outputs, sources, filters) into a flat named structure accessible via keypaths like `hosts/master/documents/MyShow/layers/Comments/live-state`, making it easy to control and monitor your live production programmatically.

## Supported Commands

**Note**: For brevity, examples use `$base = 'hosts/master/documents/MyShow/';`

### Control Commands

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

### Timing Commands

- **`setSleep($seconds)`** - Process queue frame-by-frame for the specified duration
  ```php
  setSleep(2.5); // Sleep for 2.5 seconds, processing queued actions
  ```

- **`wait($seconds)`** - Pause execution without processing frames
  ```php
  wait(1.0); // Wait 1 second, no frame processing
  ```

- **`run($sleep=0)`** - Execute the automation script 
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

- **`/?list=filter`** - Returns only keypaths containing the filter string
  ```bash
  # Show all live-state values
  curl http://localhost:8888/?list=live-state | jq

  # Show all layer information
  curl http://localhost:8888/?list=layers | jq

  # Find specific layer data
  curl http://localhost:8888/?list=layers/Comments | jq
  ```

**Use cases:**
- Discover available layers, variants, outputs, and sources
- Debug current live-state values
- Find the exact keypath for a specific resource
- Monitor API state during development

## TO-DOs

