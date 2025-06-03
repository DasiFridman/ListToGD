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
define('LTGDAI_VERSION', '1.0');

// טעינת קבצים
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/import-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/admin/mapping-page.php';
require_once LTGDAI_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once LTGDAI_PLUGIN_DIR . 'classes/DataImporter.php';

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
            LTGDAI_VERSION
        );
        
        // אם אנחנו בעמוד המיפוי, טען את הקבצים הספציפיים שלו
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
            
            // הוספת משתנה עולמי לJS עם כתובת ה-AJAX
            wp_localize_script('ltgdai-mapping-scripts', 'ltgdai_ajax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ltgdai_ajax_nonce')
            ));
        } else {
            // סקריפטים לעמוד היבוא
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

// הוספת תפריט לניהול
add_action('admin_menu', 'ltgdai_register_admin_pages');

/**
 * רישום עמודי הניהול של הפלאגין בתפריט הניהול
 */
function ltgdai_register_admin_pages() {
    // עמוד ראשי בתפריט
    add_menu_page(
        'AI Import to GeoDirectory',  // כותרת העמוד
        'ListToGD',                  // שם התפריט
        'manage_options',            // הרשאות גישה
        'ltgdai-import',            // מזהה העמוד
        'ltgdai_render_import_page',  // פונקציה להצגת העמוד
        'dashicons-upload',          // אייקון
        26                           // מיקום בתפריט
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

/**
 * פונקציה להפעלה בעת הפעלת הפלאגין
 */
function ltgdai_activation() {
    // בדיקה האם GeoDirectory מותקן
    if (!class_exists('GeoDirectory') && !function_exists('geodir_get_posttypes')) {
        // הודעת שגיאה אם GeoDirectory לא מותקן
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('יש להתקין את התוסף GeoDirectory לפני הפעלת ListToGD.');
    }
    
    // יצירת תיקיות נדרשות
    $upload_dir = wp_upload_dir();
    $ltgdai_dir = $upload_dir['basedir'] . '/ltgdai';
    
    if (!file_exists($ltgdai_dir)) {
        wp_mkdir_p($ltgdai_dir);
    }
    
    // יצירת אפשרויות ברירת מחדל
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
 * פונקציה להפעלה בעת כיבוי הפלאגין
 */
function ltgdai_deactivation() {
    // ניקוי תזמונים
    wp_clear_scheduled_hook('ltgdai_cleanup_temp_files');
}
register_deactivation_hook(__FILE__, 'ltgdai_deactivation');

/**
 * פונקציה להפעלה בעת הסרת הפלאגין
 */
function ltgdai_uninstall() {
    // הסרת כל האפשרויות ששמר הפלאגין
    delete_option('ltgdai_saved_urls');
    delete_option('ltgdai_saved_post_types');
    delete_option('ltgdai_completed_tabs');
    
    // מחיקת כל אפשרויות המיפוי
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ltgdai_field_mappings_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ltgdai_extracted_data_%'");
    
    // מחיקת תיקיית ההעלאות
    $upload_dir = wp_upload_dir();
    $ltgdai_dir = $upload_dir['basedir'] . '/ltgdai';
    
    if (file_exists($ltgdai_dir)) {
        ltgdai_recursive_rmdir($ltgdai_dir);
    }
}
register_uninstall_hook(__FILE__, 'ltgdai_uninstall');

/**
 * פונקציה למחיקת תיקייה באופן רקורסיבי
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