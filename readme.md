# mimoLive Automation API

## What is this?

A PHP automation API for mimoLive that lets you control your broadcast using simple, human-readable commands instead of dealing with UUIDs and complex API calls.

**Key features:**
- **Named paths** instead of UUIDs: `hosts/master/documents/forbiddenPHP/layers/MEv`
- **Queue-based execution**: All commands run in parallel blocks with synchronized timing
- **Multi-host support**: Control master and backup instances simultaneously
- **Animation support**: Smooth audio fades with lockstep synchronization
- **Script files**: Store complex automation sequences as reusable PHP scripts

**How it works:**
1. Call API via GET request: `http://localhost:8888/?f=scriptname`
2. Script builds a queue of actions
3. API returns immediately with queued actions
4. Queue executes in background with parallel execution

## Supported Commands

All commands are **queue-compatible** and execute in parallel within their block.

### Layer Control
- **`setLive($path)`** - Activate layer/source/variant/layer-set/output-destination
- **`setOff($path)`** - Deactivate layer/source/variant/output-destination

### Audio Control
- **`setVolume($path, $volume)`** - Set volume instantly (0.0 to 1.0)
- **`setGain($path, $gain)`** - Set gain instantly (0.0 to 2.0)
- **`setAnimateVolumeTo($path, $target, $steps = null)`** - Animate volume smoothly
  - `$steps`: Animation steps (default: FPS from document, typically 30)
  - Multiple animations in one block run in lockstep with synchronized timing
- **`setAnimateGainTo($path, $target, $steps = null)`** - Animate gain smoothly
  - Same behavior as volume animation

### Signal Control
- **`triggerSignal($signalName, $path)`** - Trigger a signal by name
  - Signal names normalized: `Cut 1`, `Cut_1`, `cut-1` all work

### Queue Control
- **`setSleep($seconds)`** - Add delay and create new execution block
  - Actions before sleep execute in parallel
  - Actions after sleep wait, then execute in parallel
  - Supports fractions: `setSleep(0.5)` = 500ms

## Examples

### Basic Usage

**Execute a script file:**
```
http://localhost:8888/?f=demo
```

**Execute inline script (URL-encoded):**
```
http://localhost:8888/?q=setLive%28%27hosts%2Fmaster%2Fdocuments%2FforbiddenPHP%2Flayers%2FMEv%27%29%3B
```

### Script Examples

**Simple layer activation:**
```php
$base = 'hosts/master/documents/forbiddenPHP/';

setLive($base . 'layers/MEv');
setSleep(2);
setOff($base . 'layers/MEv');
```

**Parallel execution:**
```php
$base = 'hosts/master/documents/forbiddenPHP/';

// All 3 execute simultaneously
setLive($base . 'layers/MEv');
setLive($base . 'layers/MEa');
setVolume($base . 'layers/MEa', 0.8);

setSleep(5);

// All 3 execute simultaneously
setOff($base . 'layers/MEv');
setOff($base . 'layers/MEa');
triggerSignal('Cut 1', $base . 'layers/Video Switcher');
```

**Smooth audio fade:**
```php
$base = 'hosts/master/documents/forbiddenPHP/';

// Activate layer and fade in simultaneously
setLive($base . 'layers/MEa');
setAnimateVolumeTo($base . 'layers/MEa', 1.0);  // Fade to 1.0 over 1 second (30 steps @ 30 FPS)

setSleep(5);

// Fade out before deactivating
setAnimateVolumeTo($base . 'layers/MEa', 0.0);  // Fade to 0 over 1 second
setSleep(1);
setOff($base . 'layers/MEa');
```

**Synchronized crossfade:**
```php
$base = 'hosts/master/documents/forbiddenPHP/';

// Activate both layers
setLive($base . 'layers/MEa');
setLive($base . 'layers/MEv');

// Fade in simultaneously (lockstep)
setAnimateVolumeTo($base . 'layers/MEa', 1.0);
setAnimateVolumeTo($base . 'layers/MEv', 0.8);

setSleep(5);

// Crossfade: MEa fades out while MEv fades up (synchronized)
setAnimateVolumeTo($base . 'layers/MEa', 0.0);
setAnimateVolumeTo($base . 'layers/MEv', 1.0);
// Both animations progress together with ONE shared sleep per step
```

**Multi-host control:**
```php
$master = 'hosts/master/documents/forbiddenPHP/';
$backup = 'hosts/backup/documents/forbiddenPHP/';

// Activate on both hosts simultaneously
setLive($master . 'layers/MEv');
setLive($backup . 'layers/MEv');

setSleep(1);

// Adjust volume on both hosts
setVolume($master . 'layers/MEa', 0.8);
setVolume($backup . 'layers/MEa', 0.8);
```

**Loop with animations:**
```php
$base = 'hosts/master/documents/forbiddenPHP/';

for ($i = 0; $i < 3; $i++) {
    setSleep(2);

    // Turn off and fade out
    setOff($base . 'layers/MEv');
    setAnimateVolumeTo($base . 'layers/MEv', 0, 15);

    setSleep(2);

    // Turn on and fade in
    setLive($base . 'layers/MEv');
    setAnimateVolumeTo($base . 'layers/MEv', 1, 15);
}
```

### Path Structure

Paths follow this pattern:
```
hosts/{hostName}/documents/{documentName}/layers/{layerName}
hosts/{hostName}/documents/{documentName}/layers/{layerName}/variants/{variantName}
hosts/{hostName}/documents/{documentName}/sources/{sourceName}
hosts/{hostName}/documents/{documentName}/layer-sets/{layerSetName}
hosts/{hostName}/documents/{documentName}/output-destinations/{outputName}
```

**Best practice:** Use base path variables:
```php
$base = 'hosts/master/documents/forbiddenPHP/';
setLive($base . 'layers/MEv');
setVolume($base . 'layers/MEa', 0.5);
```

### Configuration

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

System automatically adds `http://` and port `:8989` to each host.

### Requirements

- macOS (mimoLive is macOS-only)
- PHP 8.0 or higher
- nginx with PHP-FPM (via Homebrew)
- mimoLive with WebControl enabled on port 8989 (no password)

### Execution Behavior

**Parallel execution** (normal functions):
```php
setLive($base . 'layers/MEv');      // These 3 execute
setVolume($base . 'layers/MEa', 1); // simultaneously
triggerSignal('Cut 1', $base . 'layers/Switcher');
```

**Sequential execution** (animations):
```php
setLive($base . 'layers/MEv');                      // Executes immediately
setAnimateVolumeTo($base . 'layers/MEa', 1.0, 30); // Starts simultaneously, runs 30 steps
// Block completes when animation finishes
```

**Synchronized animations** (lockstep):
```php
setAnimateVolumeTo($base . 'layers/MEa', 1.0);  // Step 1, sleep, step 2, sleep...
setAnimateVolumeTo($base . 'layers/MEv', 0.8);  // Step 1, sleep, step 2, sleep...
// Both animations progress together with ONE shared sleep (1/FPS) per step
```

## TODOs

- [ ] Implement `update()` function for batch attribute updates
- [ ] Add support for more layer attributes (opacity, position, etc.)
