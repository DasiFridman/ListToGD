<?php
/*
Plugin Name: ListToGD
Description: Import and map data to GeoDirectory using AI.
Version: 1.0
Author: Hadassa
*/

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('LTGDAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LTGDAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LTGDAI_VERSION', '1.0');

// Load required files
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/import-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/mapping-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once LTGDAI_PLUGIN_DIR . 'classes/DataImporter.php';
require_once LTGDAI_PLUGIN_DIR . 'classes/DynamicFieldHandler.php'; 

add_action('admin_enqueue_scripts', 'ltgdai_enqueue_admin_assets');

/**
 * טעינת קבצי CSS ו-JS לעמודי הניהול
 */
function ltgdai_enqueue_admin_assets($hook) {
    if (strpos($hook, 'ltgdai') !== false) {
        wp_enqueue_style(
            'ltgdai-admin-styles', 
            LTGDAI_PLUGIN_URL . 'assets/css/import.css',
            array(),
            LTGDAI_VERSION
        );
        
        if (strpos($hook, 'ltgdai-mapping') !== false) {
            wp_enqueue_style(
                'ltgdai-mapping-styles', 
                LTGDAI_PLUGIN_URL . 'assets/css/mapping.css',
                array(),
                LTGDAI_VERSION
            );
            
            wp_enqueue_script(
                'ltgdai-mapping-scripts',
                LTGDAI_PLUGIN_URL . 'assets/js/mapping.js',
                array('jquery'),
                LTGDAI_VERSION,
                true
            );
            
            wp_localize_script('ltgdai-mapping-scripts', 'ltgdai_ajax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ltgdai_ajax_nonce')
            ));
        } else {
            wp_enqueue_script(
                'ltgdai-admin-scripts',
                LTGDAI_PLUGIN_URL . 'assets/js/import.js',
                array('jquery'),
                LTGDAI_VERSION,
                true
            );
        }
    }
}

add_action('admin_menu', 'ltgdai_register_admin_pages');

/**
 * רישום תפריטי הניהול
 */
function ltgdai_register_admin_pages() {
    add_menu_page(
        'AI Import to GeoDirectory',
        'ListToGD',
        'manage_options',
        'ltgdai-import',
        'ltgdai_render_import_page',
        'dashicons-upload',
        26
    );

    add_submenu_page(
        'ltgdai-import',
        'Import Page',
        'Import',
        'manage_options',
        'ltgdai-import',
        'ltgdai_render_import_page'
    );

    add_submenu_page(
        'ltgdai-import',
        'Mapping Page',
        'Mapping',
        'manage_options',
        'ltgdai-mapping',
        'ltgdai_render_mapping_page'
    );
}

/**
 * הפעלת הפלאגין - בדיקות ויצירת הגדרות
 */
function ltgdai_activation() {
    if (!class_exists('GeoDirectory') && !function_exists('geodir_get_posttypes')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('נדרש תוסף GeoDirectory עבור פעולת ListToGD.');
    }
    
    $upload_dir = wp_upload_dir();
    $ltgdai_dir = $upload_dir['basedir'] . '/ltgdai';
    
    if (!file_exists($ltgdai_dir)) {
        wp_mkdir_p($ltgdai_dir);
    }
    
    if (!get_option('ltgdai_saved_urls')) {
        update_option('ltgdai_saved_urls', array());
    }
    
    if (!get_option('ltgdai_saved_post_types')) {
        update_option('ltgdai_saved_post_types', array());
    }
    
    if (!get_option('ltgdai_completed_tabs')) {
        update_option('ltgdai_completed_tabs', array());
    }
}
register_activation_hook(__FILE__, 'ltgdai_activation');

/**
 * כיבוי הפלאגין - ניקוי משאבים
 */
function ltgdai_deactivation() {
    wp_clear_scheduled_hook('ltgdai_cleanup_temp_files');
}
register_deactivation_hook(__FILE__, 'ltgdai_deactivation');

/**
 * הסרת הפלאגין - מחיקת כל הנתונים
 */
function ltgdai_uninstall() {
    delete_option('ltgdai_saved_urls');
    delete_option('ltgdai_saved_post_types');
    delete_option('ltgdai_completed_tabs');
    
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ltgdai_field_mappings_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ltgdai_extracted_data_%'");
    
    $upload_dir = wp_upload_dir();
    $ltgdai_dir = $upload_dir['basedir'] . '/ltgdai';
    
    if (file_exists($ltgdai_dir)) {
        ltgdai_recursive_rmdir($ltgdai_dir);
    }
}
register_uninstall_hook(__FILE__, 'ltgdai_uninstall');

/**
 * מחיקת תיקייה באופן רקורסיבי
 */
function ltgdai_recursive_rmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    ltgdai_recursive_rmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

/**
 * פונקציית ניקוי לפיתוח - להסיר לאחר הפקה
 */
add_action('wp_loaded', function() {
    if (!current_user_can('manage_options') || !isset($_GET['ltgdai_force_clean'])) {
        return;
    }
    
    global $wpdb;
    
    echo '<div style="background: white; padding: 20px; margin: 20px; border: 2px solid #0073aa;">';
    echo '<h2>ניקוי נתוני פלאגין ListToGD</h2>';
    
    $all_options = $wpdb->get_results("
        SELECT option_name, option_value 
        FROM {$wpdb->options} 
        WHERE option_name LIKE '%ltgd%' 
           OR option_name LIKE '%listto%'
           OR option_name LIKE '%mapping%'
           OR option_name LIKE '%extract%'
           OR option_name LIKE '%completed%'
    ");
    
    echo '<h3>נמצאו האפשרויות הבאות:</h3>';
    echo '<ul>';
    foreach ($all_options as $option) {
        echo '<li><strong>' . esc_html($option->option_name) . '</strong>: ' . substr(esc_html($option->option_value), 0, 100) . '...</li>';
    }
    echo '</ul>';
    
    $deleted_count = 0;
    foreach ($all_options as $option) {
        if ($wpdb->delete($wpdb->options, array('option_name' => $option->option_name))) {
            $deleted_count++;
        }
    }
    
    $patterns = array(
        'ltgdai_%',
        'ltgd_%', 
        '%mapping%',
        '%extract%',
        '%completed_tabs%'
    );
    
    $additional_deleted = 0;
    foreach ($patterns as $pattern) {
        $result = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
        $additional_deleted += $result;
    }
    
    wp_cache_flush();
    
    echo '<div style="background: #d4edda; padding: 15px; margin: 10px 0; border: 1px solid #c3e6cb; border-radius: 4px;">'; 
    echo '<h3 style="color: #155724;">ניקוי הושלם בהצלחה!</h3>';
    echo '<p>נמחקו ' . $deleted_count . ' אפשרויות עיקריות</p>';
    echo '<p>נמחקו ' . $additional_deleted . ' אפשרויות נוספות</p>';
    echo '<p>סה"כ נמחקו: ' . ($deleted_count + $additional_deleted) . ' רשומות</p>';
    echo '</div>';
    
    echo '<p><a href="' . admin_url('admin.php?page=ltgdai-mapping') . '" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">חזרה לעמוד המיפוי</a></p>';
    
    echo '</div>';
    
    exit;
});
?>