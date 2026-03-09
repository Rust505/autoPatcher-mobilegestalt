<?php
// ==================== Plist Patcher — Full Version (PHP) ====================
// Last update: March 2026
// Supports: iOS 14–18.5 (legacy pattern) + iOS 18.6–18.7.2 / 26.0–26.1 (offset in CacheData)

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
date_default_timezone_set('UTC');
header('Content-Type: text/html; charset=utf-8');

$makerDir = __DIR__ . '/Maker/';
@mkdir($makerDir, 0755, true);

$message = '';
$summary = '';
$logHtml = '';

// ==================== Build → iOS Version Mapping ====================
$IOS_BUILD_MAP = [
    '19A341'=>'15.0', '19A346'=>'15.0', '19A348'=>'15.0.1', '19A404'=>'15.0.2',
    '19B74'=>'15.1', '19B81'=>'15.1.1', '19C56'=>'15.2', '19C63'=>'15.2.1',
    '19D50'=>'15.3', '19D52'=>'15.3.1', '19E241'=>'15.4', '19E258'=>'15.4.1',
    '19F77'=>'15.5', '19G71'=>'15.6', '19G82'=>'15.6.1', '19H12'=>'15.7',
    '19H117'=>'15.7.1', '19H218'=>'15.7.2', '19H307'=>'15.7.3', '19H321'=>'15.7.4',
    '19H332'=>'15.7.5', '19H349'=>'15.7.6', '19H357'=>'15.7.7', '19H364'=>'15.7.8',
    '19H365'=>'15.7.9', '19H370'=>'15.8', '19H380'=>'15.8.1',
    '20A362'=>'16.0', '20A371'=>'16.0.1', '20A380'=>'16.0.2', '20B82'=>'16.1',
    '20B101'=>'16.1.1', '20C65'=>'16.2', '20D47'=>'16.3', '20D67'=>'16.3.1',
    '20E247'=>'16.4', '20E252'=>'16.4.1', '20F66'=>'16.5', '20F75'=>'16.5.1',
    '20G75'=>'16.6', '20G81'=>'16.6.1', '20H19'=>'16.7', '20H30'=>'16.7.1',
    '20H115'=>'16.7.2', '20H232'=>'16.7.3',
    '21A326'=>'17.0', '21A327'=>'17.0', '21A329'=>'17.0', '21A340'=>'17.0.1',
    '21A350'=>'17.0.2', '21A360'=>'17.0.3', '21B74'=>'17.1', '21B80'=>'17.1',
    '21B91'=>'17.1.1', '21B101'=>'17.1.2', '21C62'=>'17.2', '21C66'=>'17.2.1',
    '21D50'=>'17.3', '21D61'=>'17.3.1', '21E219'=>'17.4', '21E236'=>'17.4.1',
    '21E237'=>'17.4.1', '21F79'=>'17.5', '21F90'=>'17.5.1', '21G80'=>'17.6',
    '21G93'=>'17.6.1', '21H16'=>'17.7', '21H216'=>'17.7.1', '21H221'=>'17.7.2',
    '22A3351'=>'18.0', '22A3354'=>'18.0', '22A3370'=>'18.0.1', '22B83'=>'18.1',
    '22B91'=>'18.1.1', '22C152'=>'18.2', '22C161'=>'18.2.1', '22D63'=>'18.3',
    '22D64'=>'18.3', '22D72'=>'18.3.1', '22D82'=>'18.3.2', '22E240'=>'18.4',
    '22E252'=>'18.4.1', '22F76'=>'18.5',
    '22G86'=>'18.6', '22G90'=>'18.6.1', '22G100'=>'18.6.2',
    '22H20'=>'18.7', '22H31'=>'18.7.1', '22H124'=>'18.7.2',
    '23A341'=>'26.0', '23A355'=>'26.0.1', '23B85'=>'26.1'
];

// ==================== Offset patches for iOS 18.6+ / 26.x ====================
$OFFSET_PATCHES = [
    '18.6'   => ['offset' => 0x15C0, 'value' => 0x01],
    '18.6.0' => ['offset' => 0x15C0, 'value' => 0x01],
    '18.6.1' => ['offset' => 0x15C0, 'value' => 0x01],
    '18.6.2' => ['offset' => 0x15C0, 'value' => 0x01],
    '18.7'   => ['offset' => 0x15C0, 'value' => 0x01],
    '18.7.0' => ['offset' => 0x15C0, 'value' => 0x01],
    '18.7.1' => ['offset' => 0x15C0, 'value' => 0x01],
    '18.7.2' => ['offset' => 0x15C0, 'value' => 0x01],
    '26'     => ['offset' => 0x16CB, 'value' => 0x01],
    '26.0'   => ['offset' => 0x16CB, 'value' => 0x01],
    '26.0.1' => ['offset' => 0x16CB, 'value' => 0x01],
    '26.1'   => ['offset' => 0x16CB, 'value' => 0x01],
];

// ==================== Helpers ====================
function normalize_version($version) {
    if (!$version) return '';
    $v = strtolower(trim($version));
    $v = preg_replace('/\.0+(?=\.|$)/', '', $v);
    $v = rtrim($v, '.');
    return $v;
}

function is_offset_supported_version($version) {
    global $OFFSET_PATCHES;
    if (!$version) return [false, null];
    $v = normalize_version($version);
    foreach ($OFFSET_PATCHES as $key => $patch) {
        $k = normalize_version($key);
        if ($v === $k || strpos($v, $k . '.') === 0) return [true, $key];
    }
    return [false, null];
}

function isIos17UpTo18_5(string $version): bool {
    $v = strtolower(trim($version));
    // iOS 17 (any) or iOS 18.0–18.5
    return preg_match('/^17(\.\d+)*$|^18\.[0-5](\.|$)/', $v) === 1;
}

function extract_device_info($content, $filename) {
    global $IOS_BUILD_MAP;
    $info = ['device_model'=>null, 'ios_version'=>null, 'build_version'=>null, 'device_type'=>'iPhone'];

    foreach ($IOS_BUILD_MAP as $build => $ver) {
        if (strpos($content, $build) !== false) {
            $info['build_version'] = $build;
            $info['ios_version'] = $ver;
            break;
        }
    }

    if (preg_match('/(iPhone|iPad|iPod)\d{1,2},\d{1,2}/', $content, $m)) {
        $info['device_model'] = $m[0];
        $info['device_type'] = (stripos($m[0], 'ipad') !== false) ? 'iPad' : 'iPhone';
    }

    if (!$info['ios_version'] && preg_match('/\b(1[4-9]|2[0-6])\.\d+(?:\.\d+)?\b/', $content, $v)) {
        $info['ios_version'] = $v[0];
    }

    if (!$info['device_model'] && preg_match('/(iPhone|iPad|iPod)(\d+,\d+)/i', $filename, $m)) {
        $info['device_model'] = $m[1] . $m[2];
    }

    return $info;
}

// ==================== Legacy pattern patching (iOS ≤ 18.5) ====================
function patch_legacy($content, $device_type, $is_iOS18) {
    $d0_seq = "\xD0\x07\x00\x00\x00\x00\x00\x00";
    $minOffset = 0x700;

    $pattern = ($device_type === 'iPhone')
        ? "\x01\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00"
        : "\x01\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00";

    $patched = false;
    $matchCount = 0;
    $pos = 0;
    $result = $content;

    while (($pos = strpos($result, $d0_seq, $pos)) !== false) {
        if ($pos < $minOffset) {
            $pos++;
            continue;
        }
        $before = substr($result, 0, $pos);
        $patPos = strrpos($before, $pattern);
        if ($patPos !== false) {
            $matchCount++;
            $patchLength = $is_iOS18 ? 8 : 16;
            $result = substr_replace($result, str_repeat("\x00", $patchLength), $patPos, $patchLength);
            $patched = true;
            if ($is_iOS18) break;
        }
        $pos++;
    }

    return [$patched, $matchCount, $result];
}

// ==================== Offset patching — full version from Python original ====================
function patch_offset($content, $version_key) {
    global $OFFSET_PATCHES;
    $patch = $OFFSET_PATCHES[$version_key];
    $target_offset = $patch['offset'];
    $target_value  = $patch['value'];

    // 1. Try to parse as binary plist (bplist00)
    if (str_starts_with($content, 'bplist00')) {
        $len = strlen($content);
        if ($len < 40) return [false, "File too small for binary plist", $content];

        $trailer = substr($content, -32);
        $offset_size = ord($trailer[6]);
        $ref_size    = ord($trailer[7]);
        $num_objs    = read_be_uint($trailer, 8,  8);
        $top_obj     = read_be_uint($trailer, 16, 8);
        $off_table   = read_be_uint($trailer, 24, 8);

        if ($off_table >= $len || $offset_size < 1 || $ref_size < 1 || $num_objs < 1 || $top_obj >= $num_objs) {
            return [false, "Invalid binary plist trailer", $content];
        }

        // Read offset table
        $offsets = [];
        $pos = $off_table;
        for ($i = 0; $i < $num_objs; $i++) {
            if ($pos + $offset_size > $len) return [false, "Offset table overflow", $content];
            $offsets[] = read_be_uint($content, $pos, $offset_size);
            $pos += $offset_size;
        }

        $dict_off = $offsets[$top_obj];
        if ($dict_off >= $len) return [false, "Top dictionary offset overflow", $content];

        $marker = ord($content[$dict_off]);
        if (($marker & 0xF0) != 0xD0) return [false, "Top object is not a dictionary", $content];

        $num_pairs = $marker & 0x0F;
        $dict_pos = $dict_off + 1;

        if ($num_pairs == 0x0F) {
            if ($dict_pos >= $len) return [false, "Count overflow", $content];
            $count_marker = ord($content[$dict_pos]);
            if (($count_marker & 0xF0) != 0x10) return [false, "Count is not integer", $content];
            $count_size = 1 << ($count_marker & 0x0F);
            if ($dict_pos + 1 + $count_size > $len) return [false, "Count size overflow", $content];
            $num_pairs = read_be_uint($content, $dict_pos + 1, $count_size);
            $dict_pos += 1 + $count_size;
        }

        if ($num_pairs == 0) return [false, "Dictionary has no pairs", $content];

        $key_refs = [];
        for ($i = 0; $i < $num_pairs; $i++) {
            if ($dict_pos + $ref_size > $len) return [false, "Key refs overflow", $content];
            $key_refs[] = read_be_uint($content, $dict_pos, $ref_size);
            $dict_pos += $ref_size;
        }

        $val_refs = [];
        for ($i = 0; $i < $num_pairs; $i++) {
            if ($dict_pos + $ref_size > $len) return [false, "Value refs overflow", $content];
            $val_refs[] = read_be_uint($content, $dict_pos, $ref_size);
            $dict_pos += $ref_size;
        }

        for ($i = 0; $i < $num_pairs; $i++) {
            $key_ref = $key_refs[$i];
            if ($key_ref >= $num_objs) continue;
            $key_off = $offsets[$key_ref];
            if ($key_off >= $len) continue;

            $key_marker = ord($content[$key_off]);
            $key_type   = $key_marker & 0xF0;
            if ($key_type != 0x50 && $key_type != 0x60) continue; // ASCII or UTF-16

            $key_len = $key_marker & 0x0F;
            $key_pos = $key_off + 1;

            if ($key_len == 0x0F) {
                if ($key_pos >= $len) continue;
                $len_marker = ord($content[$key_pos]);
                if (($len_marker & 0xF0) != 0x10) continue;
                $len_size = 1 << ($len_marker & 0x0F);
                if ($key_pos + 1 + $len_size > $len) continue;
                $key_len = read_be_uint($content, $key_pos + 1, $len_size);
                $key_pos += 1 + $len_size;
            }

            $char_size = ($key_type == 0x60) ? 2 : 1;
            if ($key_pos + $key_len * $char_size > $len) continue;

            $key_str = substr($content, $key_pos, $key_len * $char_size);
            if ($key_type == 0x60) $key_str = mb_convert_encoding($key_str, 'UTF-8', 'UTF-16BE');

            if ($key_str !== 'CacheData') continue;

            // Found CacheData key → now get value
            $val_ref = $val_refs[$i];
            if ($val_ref >= $num_objs) continue;
            $val_off = $offsets[$val_ref];
            if ($val_off >= $len) continue;

            $val_marker = ord($content[$val_off]);
            if (($val_marker & 0xF0) != 0x40) continue; // must be data

            $data_len = $val_marker & 0x0F;
            $data_pos = $val_off + 1;

            if ($data_len == 0x0F) {
                if ($data_pos >= $len) continue;
                $len_marker = ord($content[$data_pos]);
                if (($len_marker & 0xF0) != 0x10) continue;
                $len_size = 1 << ($len_marker & 0x0F);
                if ($data_pos + 1 + $len_size > $len) continue;
                $data_len = read_be_uint($content, $data_pos + 1, $len_size);
                $data_pos += 1 + $len_size;
            }

            if ($data_pos + $data_len > $len) continue;
            if ($target_offset >= $data_len) return [false, "Offset 0x" . dechex($target_offset) . " >= CacheData length 0x" . dechex($data_len), $content];

            $patch_pos = $data_pos + $target_offset;
            $old_byte  = ord($content[$patch_pos]);

            if ($old_byte === $target_value) {
                return [true, "Already patched at 0x" . dechex($patch_pos) . " (0x" . dechex($old_byte) . ")", $content];
            }

            $new_content = $content;
            $new_content[$patch_pos] = chr($target_value);

            return [true, "Binary CacheData patched at 0x" . dechex($patch_pos) . " (0x" . dechex($old_byte) . " → 0x" . dechex($target_value) . ")", $new_content];
        }

        return [false, "CacheData key not found in binary plist", $content];
    }

    // 2. XML plist fallback
    $pattern = '/<key>\s*CacheData\s*<\/key>\s*<data>\s*([A-Za-z0-9+\/=\s]+?)\s*<\/data>/s';
    if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        return [false, "No CacheData found in XML plist", $content];
    }

    $b64_content = $matches[1][0];
    $clean_b64   = preg_replace('/\s+/', '', $b64_content);
    $decoded     = base64_decode($clean_b64, true);

    if ($decoded === false) {
        return [false, "Base64 decode failed for CacheData", $content];
    }

    $data_len = strlen($decoded);
    if ($target_offset >= $data_len) {
        return [false, "Offset 0x" . dechex($target_offset) . " > CacheData length 0x" . dechex($data_len), $content];
    }

    $old_byte = ord($decoded[$target_offset]);
    if ($old_byte === $target_value) {
        return [true, "Already patched at offset 0x" . dechex($target_offset), $content];
    }

    $decoded[$target_offset] = chr($target_value);
    $reencoded = base64_encode($decoded);
    $formatted = wordwrap($reencoded, 76, "\n\t", true);

    $new_data = "<key>CacheData</key>\n<data>\n\t" . $formatted . "\n</data>";
    $new_content = substr_replace($content, $new_data, $matches[0][1], strlen($matches[0][0]));

    return [true, "XML CacheData patched at offset 0x" . dechex($target_offset) . " (0x" . dechex($old_byte) . " → 0x" . dechex($target_value) . ")", $new_content];
}

function read_be_uint($str, $pos, $size) {
    $val = 0;
    for ($i = 0; $i < $size; $i++) {
        $val = ($val << 8) | ord($str[$pos + $i]);
    }
    return $val;
}

// ==================== Main processing ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['plistfile']['tmp_name'])) {
    $file = $_FILES['plistfile'];
    $content = @file_get_contents($file['tmp_name']);
    $origName = basename($file['name']);

    if ($content === false || strlen($content) < 512) {
        $message = '❌ Failed to read file or file is too small.';
    } else {
        $info = extract_device_info($content, $origName);

        $log = ["=== PATCH START ===", "Device: {$info['device_type']}", "Model: " . ($info['device_model'] ?? 'unknown')];
        if ($info['ios_version']) $log[] = "iOS: {$info['ios_version']} (Build: " . ($info['build_version'] ?? 'unknown') . ")";
        else $log[] = "iOS: NOT DETECTED";

        [$is_offset, $matched_key] = is_offset_supported_version($info['ios_version']);
        $is_legacy_18 = isIos17UpTo18_5($info['ios_version']);

        if ($is_offset) {
            $log[] = "→ Using OFFSET method for iOS {$matched_key}";
            [$success, $msg, $patched_content] = patch_offset($content, $matched_key);
            if ($success) {
                $log[] = $msg;
            } else {
                $log[] = $msg;
                $message = '❌ ' . $msg;
            }
        } else {
            $log[] = "→ Using LEGACY pattern method" . ($is_legacy_18 ? " (iOS 18.0–18.5 mode)" : "");
            [$success, $count, $patched_content] = patch_legacy($content, $info['device_type'], $is_legacy_18);
            if ($success) {
                $log[] = "Patched $count matches" . ($is_legacy_18 ? " (limited to first)" : "");
            } else {
                $log[] = "No matches found";
                $message = '❌ No patchable structure found.';
            }
        }

        if (isset($patched_content) && $success) {
            $modelFolder = $info['device_model'] ? str_replace(',', '-', $info['device_model']) : 'Unknown-Model';
            $versionFolder = $info['ios_version'] ?? 'Unknown-' . date('Ymd-His');
            $saveDir = $makerDir . $modelFolder . '/' . $versionFolder . '/';
            @mkdir($saveDir, 0755, true);
            $savePath = $saveDir . 'com.apple.MobileGestalt.plist';

            if (file_exists($savePath)) {
                $message = '⚠️ File already exists — not overwritten.';
                $summary = "<div class='space-y-2 text-amber-300'>File exists: $modelFolder/$versionFolder</div>";
            } elseif (file_put_contents($savePath, $patched_content) !== false) {
                $message = '🎉 Patched and saved successfully!';
                $summary = "<div class='space-y-2'><div>Device: {$info['device_type']}</div><div>Model: " . ($info['device_model'] ?? 'unknown') . "</div><div>iOS: " . ($info['ios_version'] ?? 'not detected') . "</div><div>Saved to: $modelFolder/$versionFolder/</div></div>";
            } else {
                $message = '❌ Save failed (check permissions).';
            }
        }

        $logHtml = '<pre class="bg-zinc-950 border border-zinc-700 text-emerald-300 p-5 rounded-2xl font-mono text-sm overflow-auto max-h-96">' .
                   htmlspecialchars(implode("\n", $log)) . '</pre>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plist Patcher • iOS 14–18.7 / 26.x</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #0a0a0a, #1a1a2e); font-family: system-ui, sans-serif; }
        .glass { background: rgba(255,255,255,0.05); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.1); }
        .neon-cyan { text-shadow: 0 0 20px #22d3ee; }
        .drop-zone { transition: all 0.3s ease; }
        .drop-zone.dragover { background: rgba(34, 211, 238, 0.1); border-color: #22d3ee; transform: scale(1.02); }
    </style>
</head>
<body class="min-h-screen text-white">
    <div class="max-w-2xl mx-auto pt-12 pb-24 px-6">
        <div class="text-center mb-10">
            <div class="inline-flex items-center gap-3 mb-4">
                <div class="w-14 h-14 bg-gradient-to-br from-cyan-400 to-purple-600 rounded-2xl flex items-center justify-center text-3xl shadow-lg shadow-cyan-500/50">📱</div>
                <div>
                    <h1 class="text-5xl font-bold tracking-tighter neon-cyan">Plist Patcher</h1>
                    <p class="text-cyan-400 text-lg -mt-1">MobileGestalt • iOS 14–18.7 / 26.x</p>
                </div>
            </div>
            <p class="text-zinc-400 max-w-md mx-auto">Patch com.apple.MobileGestalt.plist</p>
        </div>

        <div class="glass rounded-3xl p-8 shadow-2xl">
            <?php if ($message): ?>
                <div class="<?= strpos($message, '🎉') !== false ? 'bg-emerald-900/70 border-emerald-500 text-emerald-300' : (strpos($message, '⚠️') !== false ? 'bg-amber-900/70 border-amber-500 text-amber-300' : 'bg-red-900/70 border-red-500 text-red-300') ?> border-2 px-6 py-4 rounded-2xl flex items-start gap-4 mb-8">
                    <i class="text-3xl <?= strpos($message, '🎉') !== false ? 'fa-solid fa-circle-check' : (strpos($message, '⚠️') !== false ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-xmark') ?> mt-1"></i>
                    <div><?= nl2br($message) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($summary): ?>
                <div class="bg-zinc-900/60 border border-zinc-700 p-6 rounded-2xl mb-8"><?= $summary ?></div>
            <?php endif; ?>

            <?php if ($logHtml): echo $logHtml; endif; ?>

            <form method="post" enctype="multipart/form-data" id="uploadForm">
                <div id="dropZone" class="drop-zone border-2 border-dashed border-zinc-600 hover:border-cyan-400 rounded-3xl p-12 text-center cursor-pointer transition-all">
                    <i class="fa-solid fa-cloud-arrow-up text-6xl text-cyan-400 mb-6"></i>
                    <p class="text-xl font-medium mb-2">Drop your MobileGestalt.plist here</p>
                    <p class="text-zinc-400">or</p>
                    <label class="mt-4 inline-block bg-white text-black font-semibold px-8 py-3 rounded-2xl cursor-pointer hover:bg-cyan-400 hover:text-black transition">
                        Browse File
                        <input type="file" name="plistfile" id="fileInput" accept=".plist" required class="hidden">
                    </label>
                </div>

                <div id="filePreview" class="mt-4 text-center text-cyan-300 font-medium hidden">
                    Selected: <span id="fileNameDisplay"></span>
                </div>

                <button type="submit" class="mt-8 w-full bg-gradient-to-r from-cyan-400 to-purple-600 hover:from-cyan-300 hover:to-purple-500 transition-all text-black font-bold text-xl py-5 rounded-3xl flex items-center justify-center gap-3 shadow-lg shadow-cyan-500/50">
                    <i class="fa-solid fa-bolt"></i> PATCH NOW
                </button>
            </form>
        </div>

        <div class="mt-8 bg-cyan-900/30 border border-cyan-500/50 text-cyan-200 p-6 rounded-3xl text-center">
            <strong class="text-cyan-400">SUPPORTED:</strong><br>
            iOS 14–18.5 (pattern) • iOS 18.6–18.7.2 / 26.0–26.1 (offset in CacheData)
        </div>

        <div class="text-center text-zinc-500 text-xs mt-12">Plist Patcher © 2026</div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        const fileNameDisplay = document.getElementById('fileNameDisplay');

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) { fileNameDisplay.textContent = fileInput.files[0].name; filePreview.classList.remove('hidden'); }
        });
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', e => {
            e.preventDefault(); dropZone.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
            if (fileInput.files.length > 0) { fileNameDisplay.textContent = fileInput.files[0].name; filePreview.classList.remove('hidden'); }
        });
        dropZone.addEventListener('click', () => fileInput.click());
    </script>
</body>
</html>
