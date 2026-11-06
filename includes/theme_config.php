<?php
/**
 * Theme Configuration System
 * Supports: Default, Purple, Gold themes
 */

if (!isset($_SESSION)) {
    session_start();
}

// Get theme from session or default to 'default'
$current_theme = $_SESSION['theme'] ?? 'default';

// Theme configurations
$themes = [
    'default' => [
        'name' => 'Default',
        'primary' => '#8a11d4',
        'accent' => '#FFD700',
        'royal-purple' => '#6A1B9A',
        'clinic-gold' => '#FFD700',
        'sidebar-bg' => '#6A1B9A',
        'sidebar-text' => '#ffffff',
    ],
    'purple' => [
        'name' => 'Purple',
        'primary' => '#8a11d4',
        'accent' => '#a855f7',
        'royal-purple' => '#6A1B9A',
        'clinic-gold' => '#c084fc',
        'sidebar-bg' => '#6A1B9A',
        'sidebar-text' => '#ffffff',
    ],
    'gold' => [
        'name' => 'Gold',
        'primary' => '#FFD700',
        'accent' => '#fbbf24',
        'royal-purple' => '#d97706',
        'clinic-gold' => '#FFD700',
        'sidebar-bg' => '#d97706',
        'sidebar-text' => '#ffffff',
    ],
];

// Get current theme config
$theme_config = $themes[$current_theme] ?? $themes['default'];

/**
 * Generate theme CSS variables
 */
function getThemeCSS($theme_config) {
    return "
        :root {
            --theme-primary: {$theme_config['primary']};
            --theme-accent: {$theme_config['accent']};
            --theme-royal-purple: {$theme_config['royal-purple']};
            --theme-clinic-gold: {$theme_config['clinic-gold']};
            --theme-sidebar-bg: {$theme_config['sidebar-bg']};
            --theme-sidebar-text: {$theme_config['sidebar-text']};
        }
    ";
}

/**
 * Save theme preference
 */
function saveTheme($theme) {
    $_SESSION['theme'] = $theme;
}

/**
 * Handle theme change request
 */
if (isset($_POST['change_theme']) && isset($_POST['theme'])) {
    $new_theme = $_POST['theme'];
    if (array_key_exists($new_theme, $themes)) {
        saveTheme($new_theme);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(array_diff_key($_GET, ['theme' => ''])));
        exit;
    }
}
?>

