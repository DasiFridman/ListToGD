<?php
/**
 * טיפול בבקשות AJAX של פלאגין ListToGD
 */

// אבטחה – מניעת גישה ישירה לקובץ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * רישום פונקציות AJAX
 */
function ltgdai_register_ajax_handlers() {
    // קבלת דוגמאות לשדה
    add_action('wp_ajax_ltgdai_get_field_samples', 'ltgdai_ajax_get_field_samples');
    
    // שמירת פרמטרי עיבוד שדה
    add_action('wp_ajax_ltgdai_save_field_processing', 'ltgdai_ajax_save_field_processing');
    
    // תצוגה מקדימה של דוגמאות מעובדות
    add_action('wp_ajax_ltgdai_preview_processed_samples', 'ltgdai_ajax_preview_processed_samples');
    
    // איפוס מיפוי
    add_action('wp_ajax_ltgdai_reset_mapping', 'ltgdai_ajax_reset_mapping');
    
    // איפוס סימון הטאב כמוכן להזנה
    add_action('wp_ajax_ltgdai_reset_tab_completion', 'ltgdai_ajax_reset_tab_completion');
    
    // חילוץ נתונים מחדש
    add_action('wp_ajax_ltgdai_extract_data_again', 'ltgdai_ajax_extract_data_again');
}
add_action('init', 'ltgdai_register_ajax_handlers');

/**
 * קבלת דוגמאות לשדה
 */
function ltgdai_ajax_get_field_samples() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    
    // בדיקת תקינות הפרמטרים
    if (empty($field) || empty($post_type) || empty($url)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // טעינת מחלץ התוכן
    require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
    $extractor = new ContentExtractor();
    
    // קבלת הדוגמאות
    $samples = $extractor->get_field_samples($field, $post_type, $url, 5);
    
    // שליחת התוצאה
    wp_send_json_success(array(
        'samples' => $samples
    ));
}

/**
 * שמירת פרמטרי עיבוד שדה
 */
function ltgdai_ajax_save_field_processing() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $source_field = isset($_POST['source_field']) ? sanitize_text_field($_POST['source_field']) : '';
    $gd_field = isset($_POST['gd_field']) ? sanitize_text_field($_POST['gd_field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $processing_params = isset($_POST['processing_params']) ? $_POST['processing_params'] : array();
    
    // בדיקת תקינות הפרמטרים
    if (empty($source_field) || empty($gd_field) || empty($post_type) || empty($url)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // סניטיזציה לפרמטרי העיבוד
    $sanitized_params = array();
    
    if (isset($processing_params['ai_prompt'])) {
        $sanitized_params['ai_prompt'] = sanitize_textarea_field($processing_params['ai_prompt']);
    }
    
    if (isset($processing_params['replace_from'])) {
        $sanitized_params['replace_from'] = sanitize_text_field($processing_params['replace_from']);
    }
    
    if (isset($processing_params['replace_to'])) {
        $sanitized_params['replace_to'] = sanitize_text_field($processing_params['replace_to']);
    }
    
    // טעינת ה-handler למיפוי
    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    
    // שמירת פרמטרי העיבוד
    $result = $mapping_handler->save_field_processing($post_type, $url, $gd_field, $source_field, $sanitized_params);
    
    if ($result) {
        wp_send_json_success(array('message' => 'פרמטרי העיבוד נשמרו בהצלחה'));
    } else {
        wp_send_json_error(array('message' => 'שגיאה בשמירת פרמטרי העיבוד'));
    }
}

/**
 * תצוגה מקדימה של דוגמאות מעובדות
 */
function ltgdai_ajax_preview_processed_samples() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $processing_params = isset($_POST['processing_params']) ? $_POST['processing_params'] : array();
    
    // בדיקת תקינות הפרמטרים
    if (empty($field) || empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // סניטיזציה לפרמטרי העיבוד
    $sanitized_params = array();
    
    if (isset($processing_params['ai_prompt'])) {
        $sanitized_params['ai_prompt'] = sanitize_textarea_field($processing_params['ai_prompt']);
    }
    
    if (isset($processing_params['replace_from'])) {
        $sanitized_params['replace_from'] = sanitize_text_field($processing_params['replace_from']);
    }
    
    if (isset($processing_params['replace_to'])) {
        $sanitized_params['replace_to'] = sanitize_text_field($processing_params['replace_to']);
    }
    
    // טעינת ה-handler למיפוי
    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    
    // קבלת דוגמאות מעובדות
    $processed_samples = $mapping_handler->preview_processed_samples($field, $post_type, $url, $sanitized_params);
    
    // שליחת התוצאה
    wp_send_json_success(array(
        'samples' => $processed_samples
    ));
}

/**
 * איפוס מיפוי
 */
function ltgdai_ajax_reset_mapping() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    // בדיקת תקינות הפרמטרים
    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // מחיקת אפשרות המיפוי
    $option_name = 'ltgdai_field_mappings_' . $post_type;
    $result = delete_option($option_name);
    
    // מחיקה מהטאבים המושלמים
    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    $index = array_search($post_type, $completed_tabs);
    
    if ($index !== false) {
        unset($completed_tabs[$index]);
        update_option('ltgdai_completed_tabs', $completed_tabs);
    }
    
    wp_send_json_success(array('message' => 'המיפוי אופס בהצלחה'));
}

/**
 * איפוס סימון הטאב כמוכן להזנה
 */
function ltgdai_ajax_reset_tab_completion() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // הסרה מרשימת הטאבים המושלמים
    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    $index = array_search($post_type, $completed_tabs);
    
    if ($index !== false) {
        unset($completed_tabs[$index]);
        update_option('ltgdai_completed_tabs', array_values($completed_tabs));
    }
    
    wp_send_json_success(array('message' => 'הסימון כמוכן להזנה בוטל בהצלחה'));
}

/**
 * חילוץ נתונים מחדש
 */
function ltgdai_ajax_extract_data_again() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // קבלת ה-URLs לסוג הפוסט הזה
    $urls = get_option('ltgdai_saved_urls', array());
    $post_types = get_option('ltgdai_saved_post_types', array());
    
    $post_type_urls = array();
    foreach ($urls as $index => $url) {
        if (isset($post_types[$index]) && $post_types[$index] === $post_type) {
            $post_type_urls[] = $url;
        }
    }
    
    if (empty($post_type_urls)) {
        wp_send_json_error(array('message' => 'לא נמצאו URL-ים לסוג הפוסט הזה'));
    }
    
    // טעינת מחלץ התוכן
    require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
    $extractor = new ContentExtractor();
    
    $extracted_count = 0;
    
    // חילוץ מחדש מכל ה-URLs
    foreach ($post_type_urls as $url) {
        if (!empty($url)) {
            $extracted_data = $extractor->extract_from_url($url);
            
            if ($extracted_data['success']) {
                // שמירת הנתונים שחולצו (דריסת הקיימים)
                $option_name = 'ltgdai_extracted_data_' . $post_type . '_' . md5($url);
                update_option($option_name, $extracted_data);
                $extracted_count++;
            }
        }
    }
    
    if ($extracted_count > 0) {
        wp_send_json_success(array('message' => 'חולצו מחדש נתונים מ-' . $extracted_count . ' קישורים'));
    } else {
        wp_send_json_error(array('message' => 'לא הצלחנו לחלץ נתונים מאף קישור'));
    }
}


/**
 * רישום פונקציות AJAX להזנה - הוסיפי לפונקציה ltgdai_register_ajax_handlers
 */
// הוסיפי את השורות האלה בתוך הפונקציה ltgdai_register_ajax_handlers:

    // ביצוע הזנה
    add_action('wp_ajax_ltgdai_start_import', 'ltgdai_ajax_start_import');
    
    // קבלת סטטוס הזנה
    add_action('wp_ajax_ltgdai_get_import_status', 'ltgdai_ajax_get_import_status');

/**
 * ביצוע הזנה לסוג פוסט מסוים
 */
function ltgdai_ajax_start_import() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    // קבלת הפרמטרים
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // טעינת מחלקת ההזנה
    require_once LTGDAI_PLUGIN_DIR . 'classes/DataImporter.php';
    $importer = new DataImporter();
    
    // ביצוע ההזנה
    $results = $importer->import_post_type($post_type);
    
    // שמירת תוצאות ההזנה
    update_option('ltgdai_import_results_' . $post_type, $results);
    
    if ($results['success']) {
        wp_send_json_success($results);
    } else {
        wp_send_json_error($results);
    }
}

/**
 * קבלת סטטוס הזנה נוכחית
 */
function ltgdai_ajax_get_import_status() {
    // בדיקת אבטחה
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }
    
    // בדיקת הרשאות
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }
    
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }
    
    // קבלת תוצאות ההזנה האחרונה
    $results = get_option('ltgdai_import_results_' . $post_type, null);
    
    if ($results) {
        wp_send_json_success($results);
    } else {
        wp_send_json_error(array('message' => 'לא נמצאו תוצאות'));
    }
}