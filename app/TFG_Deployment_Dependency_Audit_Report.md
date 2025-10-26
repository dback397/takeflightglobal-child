# TFG Deployment Dependency Audit – Pre-Migration Report

## Executive Summary

This audit analyzed the TakeFlightGlobal WordPress codebase to identify migration dependencies and required changes before moving theme code to MU-plugins. The analysis revealed **17 critical issues** that must be addressed before deployment, including theme-specific path dependencies, hook conflicts, and missing production constants.

## Critical Issues Requiring Immediate Attention

### 1. Theme-Specific Path Dependencies (HIGH PRIORITY)
- **Core/ThemeSetup.php**: Uses `get_template_directory_uri()` and `get_stylesheet_uri()` for CSS enqueuing
- **Core/Assets.php**: Depends on `get_stylesheet_directory_uri()` and `get_stylesheet_directory()` for all asset paths
- **Impact**: All frontend assets will fail to load in MU-plugin context
- **Solution**: Implement dynamic path resolution using `TFG_PLUGIN_PATH` constant

### 2. Hook Dependencies (HIGH PRIORITY)
- **Core/ThemeSetup.php**: Uses `wp_enqueue_scripts` and `wp_footer` hooks
- **Core/Assets.php**: Uses `wp_enqueue_scripts` and `admin_enqueue_scripts` hooks
- **Impact**: Theme-specific hooks will not fire in MU-plugin context
- **Solution**: Replace with `muplugins_loaded` or `plugins_loaded` hooks

### 3. Bootstrap Conflicts (MEDIUM PRIORITY)
- **Features/Membership/Membership.php**: Contains direct `Membership::init()` call (line 169)
- **Impact**: Will cause double initialization when moved to MU-plugin
- **Solution**: Remove direct bootstrap call, let App.php handle initialization

### 4. Critical Bug Fix Required (URGENT)
- **Core/Utils.php**: `info()` method calls itself recursively (line 83)
- **Impact**: Will cause PHP fatal errors
- **Solution**: Fix recursive call immediately

## Configuration Issues

### wp-config.php Updates Required
1. **Missing Production Constants**:
   - `WP_ENVIRONMENT_TYPE` (production/staging/development)
   - `DISALLOW_FILE_EDIT` (security)
   - `FORCE_SSL_ADMIN` (security)

2. **Development Constants**:
   - `WP_DEBUG` should be environment-conditional
   - `WP_DEBUG_DISPLAY` should be false in production

3. **TFG Constants**:
   - Move `TFG_VERIFICATION_API_TOKEN` from functions.php to wp-config.php
   - Move reCAPTCHA keys to wp-config.php

### functions.php Cleanup Required
1. **Path Constants**: `TFG_THEME_PATH` will point to wrong location
2. **Autoloader Path**: Hardcoded theme vendor path needs updating
3. **Duplicate Filters**: ACF and mail filters may conflict with MU-plugin

## MU-Plugin Dependencies

### Existing MU-Plugin Issues
1. **mu-plugins/_autoload.php**: Hardcoded theme path (line 19)
2. **mu-plugins/tfg-magic-core.php**: Depends on legacy class aliases
3. **mu-plugins/tfg-subscriber-confirm-bootstrap.php**: Searches theme directories

### Legacy Class Aliases
- **30+ class_alias declarations** found across all files
- May be used by existing MU-plugins
- Requires audit of external dependencies before removal

## Recommended Migration Strategy

### Phase 1: Critical Fixes (Before Migration)
1. Fix recursive call in `Core/Utils.php`
2. Fix method name mismatch in `Core/RestAPI.php`
3. Add production constants to wp-config.php
4. Remove direct bootstrap call from `Membership.php`

### Phase 2: Path Resolution (During Migration)
1. Implement `TFG_PLUGIN_PATH` constant
2. Update all theme-specific path references
3. Create asset path resolver utility
4. Update autoloader paths

### Phase 3: Hook Migration (During Migration)
1. Replace theme-specific hooks with MU-plugin compatible hooks
2. Update asset enqueuing logic
3. Test all frontend functionality

### Phase 4: Cleanup (After Migration)
1. Remove legacy class aliases (after dependency audit)
2. Clean up functions.php duplicates
3. Update MU-plugin autoloader paths

## Risk Assessment

- **HIGH RISK**: Theme-specific path dependencies will break all frontend assets
- **MEDIUM RISK**: Hook conflicts may cause functionality loss
- **LOW RISK**: Legacy class aliases can be removed gradually

## Next Steps

1. **Immediate**: Fix critical bugs (recursive call, method mismatch)
2. **Pre-Migration**: Update wp-config.php with production constants
3. **Migration**: Implement path resolution and hook updates
4. **Post-Migration**: Test all functionality and clean up legacy code

This audit provides a comprehensive roadmap for successful MU-plugin migration while maintaining system stability and functionality.
