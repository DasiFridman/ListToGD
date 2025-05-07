<?php
/*
Plugin Name: ListToGD
Description: Import and map data to GeoDirectory using AI.
Version: 1.0
Author: Hadassa
*/

// אבטחה – מניעת גישה ישירה לקובץ
if (!defined('ABSPATH')) {
    exit;
}

// הגדרת קבועים
define('LTGDAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LTGDAI_PLUGIN_URL', plugin_dir_url(__FILE__));

// טעינת קבצים
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/import-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/mapping-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/ai/ai-processor.php';
require_once LTGDAI_PLUGIN_DIR . 'classes/ImportHandler.php';
require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';

// טעינת סגנונות וסקריפטים
add_action('admin_enqueue_scripts', 'ltgdai_enqueue_admin_assets');

/**
 * רישום והטענת קבצי CSS ו-JS לאזור הניהול
 */
function ltgdai_enqueue_admin_assets($hook) {
    // טעינת סגנונות וסקריפטים רק בעמודי הפלאגין שלנו
    if (strpos($hook, 'ltgdai') !== false) {
        // סגנונות
        wp_enqueue_style(
            'ltgdai-admin-styles', 
            LTGDAI_PLUGIN_URL . 'assets/css/import.css',
            array(),
            '1.0.0'
        );
        
        // סקריפטים
        wp_enqueue_script(
            'ltgdai-admin-scripts',
            LTGDAI_PLUGIN_URL . 'assets/js/import.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }
}

// הוספת תפריט לניהול
add_action('admin_menu', 'ltgdai_register_admin_pages');

/**
 * רישום עמודי הניהול של הפלאגין בתפריט הניהול
 */
function ltgdai_register_admin_pages() {
    // עמוד ראשי בתפריט
    add_menu_page(
        'AI Import to GeoDirectory',
        'ListToGD',
        'manage_options',
        'ltgdai-import',
        'ltgdai_render_import_page',
        'dashicons-upload',
        26
    );

    // תת-תפריט עבור Import (כדי שיופיע גם כקישור בתפריט)
    add_submenu_page(
        'ltgdai-import',
        'Import Page',
        'Import',
        'manage_options',
        'ltgdai-import',
        'ltgdai_render_import_page'
    );

    // תת-תפריט נוסף עבור Mapping
    add_submenu_page(
        'ltgdai-import',
        'Mapping Page',
        'Mapping',
        'manage_options',
        'ltgdai-mapping',
        'ltgdai_render_mapping_page'
    );
}