# mimoLive Automation API

A user-friendly PHP automation API for mimoLive that uses human-readable named paths instead of UUIDs.

## Features

- **Multi-Host Support**: Control multiple mimoLive instances simultaneously (master/backup setup)
- **Named Paths**: Use readable paths like `hosts/master/documents/forbiddenPHP/layers/Video Switcher` instead of UUID-based paths
- **Path Variables**: Use base path variables for cleaner, more maintainable code
- **Queue System**: Batch multiple API requests for efficient execution
- **Field-based Execution**: Actions within a field execute in parallel, fields execute sequentially
- **Signal Triggering**: Trigger signals using user-friendly names (e.g., `Cut 1` instead of `tvGroup_Control__Cut_1_TypeSignal`)
- **Performance Optimization**: Automatically loads only the required API data based on script analysis
- **JSON-only Output**: All responses in clean JSON format

## Requirements

- macOS (mimoLive is macOS-only)
- PHP 8.0 or higher
- nginx with PHP (via Homebrew)
- mimoLive with WebControl enabled (no password)

## Installation

1. Clone or download this repository
2. Configure nginx to serve the project directory
3. Create configuration files:
   - `config/current-show.ini` - Define the current show
   - `config/hosts-{showname}.ini` - Define hosts for each show
4. Ensure mimoLive is running with WebControl enabled on port 8989
5. Ensure no password is set for WebControl (password encryption is not currently supported)

### Configuration Example

**config/current-show.ini:**
```ini
[show]
current_show=forbiddenPHP
```

**config/hosts-forbiddenPHP.ini:**
```ini
[hosts]
master=localhost
backup=macstudio-von-jophi.local
```

The system automatically adds `http://` and port `:8989` to each host.

## Usage

### Two Ways to Execute Scripts

#### 1. Inline Script (using `q` parameter)

Send a GET request with your automation script in the `q` parameter:

```
http://localhost:8888/index.php?q=YOUR_SCRIPT
```

#### 2. Script File (using `f` parameter)

Store your script in the `scripts/` directory and reference it by name:

```
http://localhost:8888/index.php?f=demo
```

This will execute `scripts/demo.php`. Benefits:
- **Reusable**: Complex scripts can be stored and executed repeatedly
- **Maintainable**: Edit scripts without URL encoding
- **Cleaner URLs**: No need for long URL-encoded strings
- **Version Control**: Scripts can be tracked in git

**Creating a script file:**
```php
// scripts/demo.php
<?php
    $base_path = 'hosts/master/documents/forbiddenPHP/';

    setLive($base_path . 'layers/MEv');
    setSleep(2);
    setOff($base_path . 'layers/MEv');
```

**Execute it:**
```
http://localhost:8888/index.php?f=demo
```

### Using from mimoLive Automation Layer

You can call the API directly from mimoLive's Automation layer using the `httpRequest()` function:

#### Method 1: Using Script Files (Recommended)
```
// Call a pre-defined script
httpRequest("http://localhost:8888/?f=demo");
```

Benefits:
- No URL encoding needed
- Clean and readable
- Scripts can be edited without changing mimoLive
- Reusable across multiple automation layers

#### Method 2: Inline Scripts
```
// Inline script (must be URL encoded)
httpRequest("http://localhost:8888/?q=setLive('hosts/master/documents/forbiddenPHP/layers/MEv');%20setSleep(3);%20setOff('hosts/master/documents/forbiddenPHP/layers/MEv');");
```

**Important**: When using inline scripts (`?q=`), the script must be **URL encoded**:
- Spaces → `%20`
- Single quotes → `%27` (or use double quotes)
- Other special characters must be encoded

### Example Scripts

**Note**: In all examples below:
- `forbiddenPHP` is the name of the mimoLive document (the `.tvshow` file)
- `master` is the host name defined in `config/hosts-forbiddenPHP.ini`
- Paths follow the pattern: `hosts/{hostName}/documents/{documentName}/...`

**Recommended**: Use base path variables for cleaner code:

```php
// Define base path variable once
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Then use it throughout your script
setLive($master_base . 'layers/MEv');
setVolume($master_base . 'layers/MEa', 0.5);
```

#### Activate a Layer
```php
setLive('hosts/master/documents/forbiddenPHP/layers/MEv');
```

Or with base path variable:
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setLive($master_base . 'layers/MEv');
```

URL-encoded:
```
http://localhost:8888/index.php?q=setLive('hosts/master/documents/forbiddenPHP/layers/MEv');
```

#### Deactivate a Layer
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setOff($master_base . 'layers/MEv');
```

#### Set Volume
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setVolume($master_base . 'layers/MEv', 0.5);
```

#### Set Gain
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setGain($master_base . 'layers/MEa', -6.0);
```

#### Trigger a Signal
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
triggerSignal('Cut 1', $master_base . 'layers/Video Switcher');
```

#### Activate a Layer-Set
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setLive($master_base . 'layer-sets/RunA');
```

#### Activate a Variant
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
setLive($master_base . 'layers/Comments/variants/Variant 1');
```

#### Complex Script with Delays
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';

setSleep(5);
setLive($master_base . 'layers/MEv');
setSleep(1);
setVolume($master_base . 'layers/MEv', 1.0);
setSleep(1);
setVolume($master_base . 'layers/MEv', 0.5);
setSleep(1);
setOff($master_base . 'layers/MEv');
```

#### Animated Volume Transitions
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Activate layer and fade in audio simultaneously
setLive($master_base . 'layers/MEa');
setAnimateVolumeTo($master_base . 'layers/MEa', 1.0);  // Fade from current volume to 1.0 over 1 second (30 steps @ 30 FPS)

setSleep(5);

// Fade out before deactivating
setAnimateVolumeTo($master_base . 'layers/MEa', 0.0);  // Fade to 0 over 1 second
setSleep(1);
setOff($master_base . 'layers/MEa');
```

#### Multiple Synchronized Animations
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Activate both layers and fade them in simultaneously (synchronized timing)
setLive($master_base . 'layers/MEa');
setLive($master_base . 'layers/MEv');
setAnimateVolumeTo($master_base . 'layers/MEa', 1.0);  // Both animations
setAnimateVolumeTo($master_base . 'layers/MEv', 0.8);  // run in lockstep

setSleep(5);

// Crossfade: MEa fades out while MEv fades up (synchronized)
setAnimateVolumeTo($master_base . 'layers/MEa', 0.0);
setAnimateVolumeTo($master_base . 'layers/MEv', 1.0);
```

This demonstrates:
- **Multiple animations in one block execute in lockstep**: Both audio fades happen simultaneously with synchronized frame timing
- **Sleep timing is shared**: Only one sleep (1/FPS) per animation step for all animations in the block
- **Smooth crossfades**: Perfect for transitioning between audio sources

#### Parallel Execution - Multiple Layers Simultaneously
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Activate 3 layers at once
setLive($master_base . 'layers/MEv');
setLive($master_base . 'layers/MEa');
setLive($master_base . 'layers/Comments');
setSleep(2);
// After 2 seconds, adjust volumes on all 3 layers simultaneously
setVolume($master_base . 'layers/MEv', 0.8);
setVolume($master_base . 'layers/MEa', 0.6);
setVolume($master_base . 'layers/Comments', 0.5);
setSleep(3);
// After 3 more seconds, deactivate all simultaneously
setOff($master_base . 'layers/MEv');
setOff($master_base . 'layers/MEa');
setOff($master_base . 'layers/Comments');
```

This example demonstrates the power of the queue system:
- **Field 1**: All 3 `setLive()` calls execute in parallel (simultaneously)
- **Sleep**: 2 seconds delay
- **Field 2**: All 3 `setVolume()` calls execute in parallel
- **Sleep**: 3 seconds delay
- **Field 3**: All 3 `setOff()` calls execute in parallel

#### Multi-Host Control - Master and Backup
```php
// Define base paths for each host
$master_base = 'hosts/master/documents/forbiddenPHP/';
$backup_base = 'hosts/backup/documents/forbiddenPHP/';

// Activate layers on both hosts simultaneously
setLive($master_base . 'layers/MEv');
setLive($backup_base . 'layers/MEv');

setSleep(1);

// Adjust volumes on both hosts
setVolume($master_base . 'layers/MEa', 0.8);
setVolume($backup_base . 'layers/MEa', 0.8);
```

This demonstrates:
- **Field 1**: Both `setLive()` calls execute in parallel across different hosts
- **Sleep**: 1 second delay
- **Field 2**: Both `setVolume()` calls execute in parallel across different hosts

## API Functions

### Layer/Source Control

- `setLive($path)` - Activate a layer, source, variant, layer-set, or output-destination
- `setOff($path)` - Deactivate a layer, source, variant, or output-destination

### Audio Control

- `setVolume($path, $volume)` - Set volume (0.0 to 1.0)
- `setGain($path, $gain)` - Set gain (0.0 to 2.0)
- `setAnimateVolumeTo($path, $target_value, $steps = null)` - Animate volume from current to target value
  - `$steps`: Number of animation steps (default: FPS from document, typically 30)
  - Animations run in parallel with other actions but execute internally sequential
  - Multiple animations in the same block are synchronized with shared timing
- `setAnimateGainTo($path, $target_value, $steps = null)` - Animate gain from current to target value
  - `$steps`: Number of animation steps (default: FPS from document, typically 30)
  - Same parallel/sequential behavior as volume animation

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
hosts/{hostName}/documents/{documentName}/layers/{layerName}
hosts/{hostName}/documents/{documentName}/layers/{layerName}/variants/{variantName}
hosts/{hostName}/documents/{documentName}/sources/{sourceName}
hosts/{hostName}/documents/{documentName}/sources/{sourceName}/filters/{filterName}
hosts/{hostName}/documents/{documentName}/layer-sets/{layerSetName}
hosts/{hostName}/documents/{documentName}/output-destinations/{outputName}
```

**Best Practice**: Use base path variables to keep your code DRY:
```php
$master_base = 'hosts/master/documents/forbiddenPHP/';
$backup_base = 'hosts/backup/documents/forbiddenPHP/';

// Then simply append the resource path
setLive($master_base . 'layers/MEv');
setLive($backup_base . 'layers/MEv');
```

Benefits:
- **Cleaner code**: Repeated host/document part defined once
- **Easy host switching**: Change variable instead of every path
- **Fewer typos**: Less repetitive typing
- **Better maintainability**: Document rename only affects one line

## Response Format

All responses are in JSON format:

```json
{
  "success": true,
  "changes": [
    {
      "path": "hosts/master/documents/forbiddenPHP/layers/MEv",
      "action": "setLive",
      "result": { ... }
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
├── config/
│   ├── current-show.ini               # Active show configuration
│   └── hosts-{showname}.ini           # Host definitions per show
├── scripts/                            # Script files (executed via ?f=scriptname)
│   ├── demo.php                       # Demo script: blink layer 10 times
│   └── test-simple.php                # Simple test: turn layer on and off
├── functions/
│   ├── setter-getter.php              # Array navigation helpers
│   ├── multiCurlRequest.php           # Parallel cURL execution
│   ├── namedAPI.php                   # Named API builder with multi-host support
│   ├── queue.php                      # Queue management and execution
│   └── analyzeScriptNeeds.php         # Script analysis for optimization
└── tests/                              # Test files (run from tests/ directory)
    ├── test-namedAPI.php              # Test namedAPI structure
    ├── test-namedAPI-update.php       # Test namedAPI updates
    ├── test-signals.php               # Test signal triggering
    ├── test-optimization.php          # Test performance optimization
    ├── test-multi-host.php            # Test multi-host support
    └── ...
```

## Running Tests

Tests must be run from the `tests/` directory:

```bash
cd tests
php test-namedAPI.php          # Test namedAPI structure with multi-host
php test-multi-host.php        # Test multi-host configuration
php test-namedAPI-update.php   # Test namedAPI state updates
php test-signals.php           # Test signal triggering
php test-optimization.php      # Test script analysis (all tests pass)
php test-queue.php             # Test queue system
php test-fields.php            # Test field execution with delays
php test-execute.php           # Test actual queue execution
php test-gain.php              # Test source gain control
```

## How It Works

1. **Configuration Loading**: Current show and hosts are loaded from config files
2. **Multi-Host Discovery**: All configured hosts are queried simultaneously
3. **Script Analysis**: The `q` parameter is analyzed to determine what API data is needed
4. **Conditional Loading**: Only required parts of the mimoLive API are fetched per host
5. **Named API Building**: UUIDs are mapped to human-readable names with host context
6. **Script Execution**: Your script is executed via `eval()`
7. **Queue Execution**: All queued actions are executed in parallel within their fields, across all hosts
8. **State Updates**: The namedAPI is updated with new states from API responses
9. **JSON Response**: Results are returned as JSON

## Security Note

This API is designed for local use only. The `eval()` function is used for script execution, which is safe in a localhost environment but would be a security risk if exposed to the internet.

## Open Issues / TODO

### High Priority
- [x] Multi-host support for master/backup setups
- [x] Path variable support for cleaner code
- [x] Configuration system for hosts
- [ ] Implement `update()` function for batch attribute updates

### Medium Priority
- [ ] Add error handling for malformed scripts
- [ ] Add validation for volume/gain ranges
- [ ] Improve error messages with suggested fixes
- [ ] Add support for more layer attributes (opacity, position, etc.)
- [ ] Automatic failover from master to backup on connection failure

### Low Priority
- [ ] Add caching mechanism for namedAPI between requests
- [ ] Add dry-run mode to preview actions without executing
- [ ] Add logging system for debugging
- [ ] Create web-based GUI for script building
- [ ] Add host health monitoring dashboard

### Future Considerations
- [ ] Password encryption support for WebControl
- [ ] Dynamic host discovery (mDNS/Bonjour)
- [ ] REST API wrapper with proper HTTP methods (GET, POST, PATCH, DELETE)
- [ ] WebSocket support for real-time updates
- [ ] Load balancing across multiple hosts
