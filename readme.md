# mimoLive Automation API

A user-friendly PHP automation API for mimoLive that uses human-readable named paths instead of UUIDs.

## Features

- **Named Paths**: Use readable paths like `documents/forbiddenPHP/layers/Video Switcher` instead of UUID-based paths
- **Queue System**: Batch multiple API requests for efficient execution
- **Field-based Execution**: Actions within a field execute in parallel, fields execute sequentially
- **Signal Triggering**: Trigger signals using user-friendly names (e.g., `Cut 1` instead of `tvGroup_Control__Cut_1_TypeSignal`)
- **Performance Optimization**: Automatically loads only the required API data based on script analysis
- **JSON-only Output**: All responses in clean JSON format

## Requirements

- macOS (mimoLive is macOS-only)
- PHP 7.4 or higher
- nginx with PHP (via Homebrew)
- mimoLive with WebControl enabled (no password)

## Installation

1. Clone or download this repository
2. Configure nginx to serve the project directory
3. Ensure mimoLive is running with WebControl enabled on port 8989
4. Ensure no password is set for WebControl (password encryption is not currently supported)

## Usage

### Basic Request

Send a GET request with your automation script in the `q` parameter:

```
http://localhost:8888/index.php?q=YOUR_SCRIPT
```

### Example Scripts

**Note**: In all examples below, `forbiddenPHP` is the name of the mimoLive document (the `.tvshow` file). This name comes from the document's `name` attribute in the API and is used as the first part of all paths: `documents/{documentName}/...`

#### Activate a Layer
```php
setLive('documents/forbiddenPHP/layers/MEv');
```

URL-encoded:
```
http://localhost:8888/index.php?q=setLive('documents/forbiddenPHP/layers/MEv');
```

#### Deactivate a Layer
```php
setOff('documents/forbiddenPHP/layers/MEv');
```

#### Set Volume
```php
setVolume('documents/forbiddenPHP/layers/MEv', 0.5);
```

#### Set Gain
```php
setGain('documents/forbiddenPHP/layers/MEa', -6.0);
```

#### Trigger a Signal
```php
triggerSignal('Cut 1', 'documents/forbiddenPHP/layers/Video Switcher');
```

#### Activate a Layer-Set
```php
setLive('documents/forbiddenPHP/layer-sets/RunA');
```

#### Activate a Variant
```php
setLive('documents/forbiddenPHP/layers/Comments/variants/Variant 1');
```

#### Complex Script with Delays
```php
setSleep(5);
setLive('documents/forbiddenPHP/layers/MEv');
setSleep(1);
setVolume('documents/forbiddenPHP/layers/MEv', 1.0);
setSleep(1);
setVolume('documents/forbiddenPHP/layers/MEv', 0.5);
setSleep(1);
setOff('documents/forbiddenPHP/layers/MEv');
```

URL-encoded:
```
http://localhost:8888/index.php?q=setSleep(5);%20setLive('documents/forbiddenPHP/layers/MEv');%20setSleep(1);%20setVolume('documents/forbiddenPHP/layers/MEv',%201.0);%20setSleep(1);%20setVolume('documents/forbiddenPHP/layers/MEv',%200.5);%20setSleep(1);%20setOff('documents/forbiddenPHP/layers/MEv');
```

#### Parallel Execution - Multiple Layers Simultaneously
```php
// Activate 3 layers at once
setLive('documents/forbiddenPHP/layers/MEv');
setLive('documents/forbiddenPHP/layers/MEa');
setLive('documents/forbiddenPHP/layers/Comments');
setSleep(2);
// After 2 seconds, adjust volumes on all 3 layers simultaneously
setVolume('documents/forbiddenPHP/layers/MEv', 0.8);
setVolume('documents/forbiddenPHP/layers/MEa', 0.6);
setVolume('documents/forbiddenPHP/layers/Comments', 0.5);
setSleep(3);
// After 3 more seconds, deactivate all simultaneously
setOff('documents/forbiddenPHP/layers/MEv');
setOff('documents/forbiddenPHP/layers/MEa');
setOff('documents/forbiddenPHP/layers/Comments');
```

URL-encoded:
```
http://localhost:8888/index.php?q=setLive('documents/forbiddenPHP/layers/MEv');%20setLive('documents/forbiddenPHP/layers/MEa');%20setLive('documents/forbiddenPHP/layers/Comments');%20setSleep(2);%20setVolume('documents/forbiddenPHP/layers/MEv',%200.8);%20setVolume('documents/forbiddenPHP/layers/MEa',%200.6);%20setVolume('documents/forbiddenPHP/layers/Comments',%200.5);%20setSleep(3);%20setOff('documents/forbiddenPHP/layers/MEv');%20setOff('documents/forbiddenPHP/layers/MEa');%20setOff('documents/forbiddenPHP/layers/Comments');
```

This example demonstrates the power of the queue system:
- **Field 1**: All 3 `setLive()` calls execute in parallel (simultaneously)
- **Sleep**: 2 seconds delay
- **Field 2**: All 3 `setVolume()` calls execute in parallel
- **Sleep**: 3 seconds delay
- **Field 3**: All 3 `setOff()` calls execute in parallel

## API Functions

### Layer/Source Control

- `setLive($path)` - Activate a layer, source, variant, layer-set, or output-destination
- `setOff($path)` - Deactivate a layer, source, variant, or output-destination

### Audio Control

- `setVolume($path, $volume)` - Set volume (0.0 to 1.0)
- `setGain($path, $gain)` - Set gain in dB (e.g., -6.0)

### Signal Control

- `triggerSignal($signalName, $path)` - Trigger a signal by name
  - Signal names are normalized (spaces, hyphens, underscores removed, lowercase)
  - Example: `Cut 1`, `Cut_1`, `cut-1` all map to `cut1`

### Queue Control

- `setSleep($seconds)` - Add a delay and create a new execution field
  - Actions before `setSleep()` execute in parallel
  - Actions after `setSleep()` wait for the delay, then execute in parallel

## Path Structure

Paths follow this pattern:
```
documents/{documentName}/layers/{layerName}
documents/{documentName}/layers/{layerName}/variants/{variantName}
documents/{documentName}/sources/{sourceName}
documents/{documentName}/sources/{sourceName}/filters/{filterName}
documents/{documentName}/layer-sets/{layerSetName}
documents/{documentName}/output-destinations/{outputName}
```

## Response Format

All responses are in JSON format:

```json
{
  "success": true,
  "changes": [
    {
      "path": "documents/forbiddenPHP/layers/MEv",
      "action": "setLive",
      "field": 0
    }
  ],
  "count": 1
}
```

## Error Responses

Connection errors:
```json
{
  "error": "Please open mimoLive and/or activate WebControl!"
}
```

Password protection error:
```json
{
  "error": "Password encryption is currently not supported. Please goto mimoLive and get rid of your password for WebControl."
}
```

## Performance Optimization

The API automatically analyzes your script to determine what data needs to be loaded:

- If your script only uses layers, it won't load sources, layer-sets, etc.
- If your script uses variants, it loads both layers and variants
- If your script uses signals, it extracts signal information
- This significantly reduces API calls and memory usage

## File Structure

```
mimoLive-automation/
├── index.php                           # Main entry point
├── functions/
│   ├── setter-getter.php              # Layer/source control functions
│   ├── multiCurlRequest.php           # Parallel cURL execution
│   ├── namedAPI.php                   # Named API builder with conditional loading
│   ├── queue.php                      # Queue management and execution
│   └── analyzeScriptNeeds.php         # Script analysis for optimization
└── tests/                              # Test files (run from tests/ directory)
    ├── test-namedAPI.php              # Test namedAPI structure
    ├── test-namedAPI-update.php       # Test namedAPI updates
    ├── test-signals.php               # Test signal triggering
    ├── test-optimization.php          # Test performance optimization
    └── ...
```

## Running Tests

Tests must be run from the `tests/` directory:

```bash
cd tests
php test-namedAPI.php
php test-namedAPI-update.php
php test-signals.php
php test-optimization.php
```

## How It Works

1. **Script Analysis**: The `q` parameter is analyzed to determine what API data is needed
2. **Conditional Loading**: Only required parts of the mimoLive API are fetched
3. **Named API Building**: UUIDs are mapped to human-readable names
4. **Script Execution**: Your script is executed via `eval()`
5. **Queue Execution**: All queued actions are executed in parallel within their fields
6. **State Updates**: The namedAPI is updated with new states from API responses
7. **JSON Response**: Results are returned as JSON

## Security Note

This API is designed for local use only. The `eval()` function is used for script execution, which is safe in a localhost environment but would be a security risk if exposed to the internet.

## Open Issues / TODO

### High Priority
- [ ] Implement `memo_get()` function that references `$GLOBALS['namedAPI']` (currently using `array_get()`)
- [ ] Implement `update()` function for batch attribute updates
- [ ] Add `.gitignore` entry for tests directory

### Medium Priority
- [ ] Add error handling for malformed scripts
- [ ] Add validation for volume/gain ranges
- [ ] Improve error messages with suggested fixes
- [ ] Add support for more layer attributes (opacity, position, etc.)

### Low Priority
- [ ] Add caching mechanism for namedAPI between requests
- [ ] Add dry-run mode to preview actions without executing
- [ ] Add logging system for debugging
- [ ] Create web-based GUI for script building

### Future Considerations
- [ ] Password encryption support for WebControl
- [ ] Support for multiple mimoLive instances
- [ ] REST API wrapper with proper HTTP methods (GET, POST, PATCH, DELETE)
- [ ] WebSocket support for real-time updates
