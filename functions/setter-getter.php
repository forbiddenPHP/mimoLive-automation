<?php
    $GLOBALS['namedAPI'] = $GLOBALS['namedAPI'] ?? [];

// Basis-Funktionen für Array-Navigation
function array_get($array, $keypath, $delim = '/') {
    // [] am Ende entfernen für get - macht keinen Sinn beim Lesen
    $keypath = rtrim($keypath, '[]');
    
    $keys = explode($delim, $keypath);
    $current = $array;
    
    foreach ($keys as $key) {
        if (!isset($current[$key])) {
            return null;
        }
        $current = $current[$key];
    }
    
    return $current;
}

function array_set(&$array, $keypath, $value, $delim = '/') {
    // Prüfen ob [] am Ende steht (append-Modus)
    $append = false;
    if (substr($keypath, -2) === '[]') {
        $append = true;
        $keypath = substr($keypath, 0, -2); // [] entfernen
    }
    
    $keys = explode($delim, $keypath);
    $current = &$array;
    
    // Zu allen Keys navigieren
    foreach ($keys as $key) {
        if (!isset($current[$key])) {
            $current[$key] = [];
        }
        $current = &$current[$key];
    }
    
    // Wert setzen oder anhängen
    if ($append) {
        $current[] = $value;  // Anhängen an das Array
    } else {
        $current = $value;    // Überschreiben
    }
}

// Memo-Funktionen basierend auf $GLOBALS['_MEMO']
function memo_get($keypath, $delim = '/') {
    return array_get($GLOBALS['namedAPI'], $keypath, $delim);
}

function memo_set($keypath, $value, $delim = '/') {    
    return array_set($GLOBALS['namedAPI'], $keypath, $value, $delim);
}
