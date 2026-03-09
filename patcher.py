#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Plist Patcher — Python Version
Supports offset-based patching for iOS 18.6+, 18.7+, 26.x in CacheData section
Last update: March 2026
"""

import os
import re
import sys
import base64
import plistlib
import argparse
from pathlib import Path
from typing import Dict, Optional, Tuple, Any

# ==================== Build → Version Mapping ====================
IOS_BUILD_MAP: Dict[str, str] = {
    '19A341': '15.0', '19A346': '15.0', '19A348': '15.0.1', '19A404': '15.0.2',
    '19B74': '15.1', '19B81': '15.1.1', '19C56': '15.2', '19C63': '15.2.1',
    '19D50': '15.3', '19D52': '15.3.1', '19E241': '15.4', '19E258': '15.4.1',
    '19F77': '15.5', '19G71': '15.6', '19G82': '15.6.1', '19H12': '15.7',
    '19H117': '15.7.1', '19H218': '15.7.2', '19H307': '15.7.3', '19H321': '15.7.4',
    '19H332': '15.7.5', '19H349': '15.7.6', '19H357': '15.7.7', '19H364': '15.7.8',
    '19H365': '15.7.9', '19H370': '15.8', '19H380': '15.8.1',
    '20A362': '16.0', '20A371': '16.0.1', '20A380': '16.0.2', '20B82': '16.1',
    '20B101': '16.1.1', '20C65': '16.2', '20D47': '16.3', '20D67': '16.3.1',
    '20E247': '16.4', '20E252': '16.4.1', '20F66': '16.5', '20F75': '16.5.1',
    '20G75': '16.6', '20G81': '16.6.1', '20H19': '16.7', '20H30': '16.7.1',
    '20H115': '16.7.2', '20H232': '16.7.3',
    '21A326': '17.0', '21A327': '17.0', '21A329': '17.0', '21A340': '17.0.1',
    '21A350': '17.0.2', '21A360': '17.0.3', '21B74': '17.1', '21B80': '17.1',
    '21B91': '17.1.1', '21B101': '17.1.2', '21C62': '17.2', '21C66': '17.2.1',
    '21D50': '17.3', '21D61': '17.3.1', '21E219': '17.4', '21E236': '17.4.1',
    '21E237': '17.4.1', '21F79': '17.5', '21F90': '17.5.1', '21G80': '17.6',
    '21G93': '17.6.1', '21H16': '17.7', '21H216': '17.7.1', '21H221': '17.7.2',
    '22A3351': '18.0', '22A3354': '18.0', '22A3370': '18.0.1', '22B83': '18.1',
    '22B91': '18.1.1', '22C152': '18.2', '22C161': '18.2.1', '22D63': '18.3',
    '22D64': '18.3', '22D72': '18.3.1', '22D82': '18.3.2', '22E240': '18.4',
    '22E252': '18.4.1', '22F76': '18.5', '22G86': '18.6', '22G90': '18.6.1',
    '22G100': '18.6.2', '22H20': '18.7', '22H31': '18.7.1', '22H124': '18.7.2',
    '23A341': '26.0', '23A355': '26.0.1', '23B85': '26.1'
}

# Offset patches for newer iOS versions — patching inside <key>CacheData</key><data>...</data>
OFFSET_PATCHES: Dict[str, Dict[str, int]] = {
    '18.6': {'offset': 0x15C0, 'value': 0x01},
    '18.6.0': {'offset': 0x15C0, 'value': 0x01},
    '18.6.1': {'offset': 0x15C0, 'value': 0x01},
    '18.6.2': {'offset': 0x15C0, 'value': 0x01},
    '18.7': {'offset': 0x15C0, 'value': 0x01},
    '18.7.0': {'offset': 0x15C0, 'value': 0x01},
    '18.7.1': {'offset': 0x15C0, 'value': 0x01},
    '18.7.2': {'offset': 0x15C0, 'value': 0x01},
    '26': {'offset': 0x16CB, 'value': 0x01},
    '26.0': {'offset': 0x16CB, 'value': 0x01},
    '26.0.1': {'offset': 0x16CB, 'value': 0x01},
    '26.1': {'offset': 0x16CB, 'value': 0x01},
}

# Legacy pattern-based patches for iOS 15–18.5
LEGACY_PATTERNS = {
    'iPhone': bytes([
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00
    ]),
    'iPad': bytes([
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00
    ])
}
D0_SEQ = bytes([0xD0, 0x07, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00])
MIN_OFFSET = 0x700


def normalize_version(version: Optional[str]) -> str:
    """Normalize iOS version string for comparison."""
    if not version:
        return ''
    v = version.strip().lower()
    v = re.sub(r'\.0+(?=\.|$)', '', v)
    v = v.rstrip('.')  # ✅ ИСПРАВЛЕНО: убираем trailing точки
    return v


def is_supported_offset_version(version: Optional[str]) -> Tuple[bool, Optional[str]]:
    """Check if version matches offset-patchable versions. Returns (is_match, matched_key)."""
    if not version:
        return False, None
    v = normalize_version(version)
    for key in OFFSET_PATCHES:
        if v == normalize_version(key) or v.startswith(normalize_version(key) + '.'):
            return True, key
    return False, None


def extract_device_info(content: bytes, filename: str) -> Dict[str, Any]:
    """Extract device model, iOS version, and build from plist content."""
    info = {
        'device_model': None,
        'ios_version': None,
        'build_version': None,
        'device_type': 'iPhone',
        'found_builds': []
    }

    # Extract device model
    model_match = re.search(r'(iPhone|iPad)\d{1,2},\d{1,2}', content.decode('latin-1', errors='ignore'))
    if model_match:
        info['device_model'] = model_match.group(0)
        if 'ipad' in info['device_model'].lower():
            info['device_type'] = 'iPad'

    # Extract build → version
    for build, ver in IOS_BUILD_MAP.items():
        if build.encode() in content:
            info['build_version'] = build
            info['ios_version'] = ver
            break

    # Fallback: extract version from regex
    if not info['ios_version']:
        version_match = re.search(
            r'\b(1[5-9]|2[0-9])\.(0|[1-9]|1[0-9])(?:\.(0|[1-9]|1[0-9]))?(?:[ab]\d)?\b',
            content.decode('latin-1', errors='ignore')
        )
        if version_match:
            info['ios_version'] = version_match.group(0)

    # Fallback model from filename
    if not info['device_model']:
        fname_match = re.search(r'(iPhone|iPad|iPod)(\d+,\d+)', filename, re.I)
        if fname_match:
            info['device_model'] = fname_match.group(1) + fname_match.group(2)

    return info


def patch_cache_data(data: bytearray, offset: int, value: int) -> bool:
    """Patch specific offset inside CacheData binary blob."""
    if offset >= len(data):
        return False
    if data[offset] == value:
        return True  # Already patched
    data[offset] = value
    return True


def patch_plist_offset(content: bytes, version_key: str) -> Tuple[bool, str, bytes]:
    """
    Patch plist using offset method inside <key>CacheData</key><data>...</data>.
    Returns (success, message, patched_content).
    """
    patch_info = OFFSET_PATCHES.get(version_key)
    if not patch_info:
        return False, f"No offset patch defined for {version_key}", content

    offset = patch_info['offset']
    value = patch_info['value']

    # Try XML plist first
    try:
        plist_data = plistlib.loads(content)
        if 'CacheData' in plist_data and isinstance(plist_data['CacheData'], bytes):
            cache = bytearray(plist_data['CacheData'])
            if patch_cache_data(cache, offset, value):
                plist_data['CacheData'] = bytes(cache)
                return True, f"✓ Patched offset 0x{offset:X} → 0x{value:02X} in CacheData", plistlib.dumps(plist_data)
            else:
                return False, f"✗ Offset 0x{offset:X} out of bounds (CacheData size: {len(plist_data['CacheData'])})", content
    except Exception:
        pass

    # Fallback: raw XML parsing for <key>CacheData</key><data>...</data>
    try:
        # Match <key>CacheData</key> followed by <data>...</data>
        pattern = rb'(<key>CacheData</key>\s*<data>\s*)([A-Za-z0-9+/=\s]*)(\s*</data>)'
        match = re.search(pattern, content, re.DOTALL)
        if match:
            prefix, b64_data, suffix = match.groups()
            # Decode base64, removing whitespace
            clean_b64 = re.sub(rb'\s+', b'', b64_data)
            decoded = bytearray(base64.b64decode(clean_b64))
            
            if offset >= len(decoded):
                return False, f"✗ Offset 0x{offset:X} out of bounds (CacheData decoded size: {len(decoded)})", content
            
            if patch_cache_data(decoded, offset, value):
                # Re-encode with line breaks every 76 chars (standard base64)
                reencoded = base64.b64encode(bytes(decoded))
                formatted = b'\n\t' + re.sub(r'(.{1,76})', r'\1\n\t', reencoded.decode()).strip() + '\n\t'
                new_content = content[:match.start()] + prefix + formatted.encode() + suffix + content[match.end():]
                return True, f"✓ Raw XML: patched offset 0x{offset:X} → 0x{value:02X} in CacheData", new_content
    except Exception as e:
        pass

    return False, f"✗ Could not locate or patch CacheData section", content


def patch_plist_legacy(content: bytes, device_type: str, ios_version: str) -> Tuple[bool, str, bytes]:
    """Legacy pattern-based patching for iOS 15–18.5."""
    is_ios18 = bool(re.match(r'^18(\.[0-5])?(\.|$)', ios_version or ''))
    pattern = LEGACY_PATTERNS.get(device_type, LEGACY_PATTERNS['iPhone'])
    
    patched = False
    match_count = 0
    pos = 0
    result = bytearray(content)

    while (pos := result.find(D0_SEQ, pos)) != -1:
        if pos < MIN_OFFSET:
            pos += 1
            continue
        pat_pos = result.rfind(pattern, 0, pos)
        if pat_pos != -1:
            match_count += 1
            patch_len = 8 if is_ios18 else 16
            result[pat_pos:pat_pos + patch_len] = b'\x00' * patch_len
            patched = True
            if is_ios18:
                break
        pos += 1

    if patched:
        return True, f"✓ Legacy patch applied ({match_count} matches, {1 if is_ios18 else match_count} patched)", bytes(result)
    return False, "✗ No legacy pattern matches found", content


def process_plist(file_path: str, output_dir: Optional[str] = None, force_offset: bool = False) -> Dict[str, Any]:
    """Main processing function."""
    result = {
        'success': False,
        'message': '',
        'output_path': None,
        'device_info': {},
        'logs': []
    }

    try:
        with open(file_path, 'rb') as f:
            content = f.read()
    except Exception as e:
        result['message'] = f"❌ Failed to read file: {e}"
        return result

    filename = os.path.basename(file_path)
    info = extract_device_info(content, filename)
    result['device_info'] = info
    log = result['logs']
    
    log.append(f"=== Processing: {filename} ===")
    log.append(f"Device: {info['device_type']} | Model: {info['device_model'] or 'unknown'}")
    log.append(f"Detected iOS: {info['ios_version'] or 'NOT DETECTED'}")

    # Check for offset-patchable versions first
    is_offset_version, matched_key = is_supported_offset_version(info['ios_version'])
    
    # ✅ ИСПРАВЛЕНО: было "and not force_offset == False"
    if is_offset_version:
        log.append(f"→ Using OFFSET patching for {matched_key}")
        success, msg, patched_content = patch_plist_offset(content, matched_key)
        result['success'] = success
        result['message'] = msg
        log.append(msg)
        
        if success and output_dir:
            model_folder = (info['device_model'] or 'Unknown-Model').replace(',', '-')
            version_folder = info['ios_version'] or 'Unknown'
            save_dir = Path(output_dir) / model_folder / version_folder
            save_dir.mkdir(parents=True, exist_ok=True)
            save_path = save_dir / 'com.apple.MobileGestalt.plist'
            
            if save_path.exists():
                result['message'] = f"⚠️ File already exists: {save_path}"
            else:
                try:
                    with open(save_path, 'wb') as f:
                        f.write(patched_content)
                    result['output_path'] = str(save_path)
                    result['message'] = f"🎉 Successfully patched & saved!"
                except Exception as e:
                    result['message'] = f"❌ Failed to save: {e}"
        elif success:
            result['output_path'] = 'stdout'
            
    elif not is_offset_version and info['ios_version']:
        # Try legacy patching for older versions
        log.append(f"→ Using LEGACY pattern patching for iOS {info['ios_version']}")
        success, msg, patched_content = patch_plist_legacy(content, info['device_type'], info['ios_version'])
        result['success'] = success
        result['message'] = msg
        log.append(msg)
        
        if success and output_dir:
            # ✅ Формируем путь как в PHP: Maker/{Model}/{Version}/
            model_folder = (info['device_model'] or 'Unknown-Model').replace(',', '-')
            version_folder = info['ios_version'] or f'Unknown-{matched_key}'
            save_dir = Path(output_dir) / model_folder / version_folder
            save_dir.mkdir(parents=True, exist_ok=True)
            save_path = save_dir / 'com.apple.MobileGestalt.plist'
            
            # ✅ Проверка: если файл уже есть — не перезаписываем (как в PHP)
            if save_path.exists():
                result['message'] = f"⚠️ File already exists: {save_path}"
                log.append(result['message'])
            else:
                try:
                    with open(save_path, 'wb') as f:
                        f.write(patched_content)
                    result['output_path'] = str(save_path)
                    result['message'] = f"🎉 Successfully patched & saved!"
                    log.append(result['message'])
                except Exception as e:
                    result['message'] = f"❌ Patch applied but failed to save: {e}"
                    log.append(result['message'])
    else:
        result['message'] = f"❌ No patching method available for iOS {info['ios_version'] or 'unknown'}"
        log.append(result['message'])

    return result


def main():
    """Simple CLI: python patcher.py <input.plist>"""
    
    args = sys.argv[1:]
    
    if len(args) < 1:
        print("Usage: python patcher.py <input.plist>", file=sys.stderr)
        print("Example: python patcher.py '/path/to/com.apple.MobileGestalt.plist'", file=sys.stderr)
        sys.exit(1)
    
    input_path = os.path.expanduser(os.path.expandvars(args[0]))
    
    # ✅ АВТО-ПАПКА: Maker/ рядом со скриптом (как в PHP-версии)
    script_dir = os.path.dirname(os.path.abspath(__file__))
    output_dir = os.path.join(script_dir, 'Maker')
    os.makedirs(output_dir, exist_ok=True)
    
    if not os.path.isfile(input_path):
        print(f"❌ File not found: {input_path}", file=sys.stderr)
        sys.exit(1)
    
    # Process plist — теперь output_dir ВСЕГДА передается
    result = process_plist(input_path, output_dir)
    
    # Output results
    for line in result['logs']:
        print(line)
    
    status_icon = '✅' if result['success'] else '❌'
    print(f"\n{status_icon} {result['message']}")
    
    if result['output_path']:
        print(f"📁 Output: {result['output_path']}")
    
    sys.exit(0 if result['success'] else 1)

if __name__ == '__main__':
    main()