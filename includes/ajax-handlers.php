<?php
/**
 * מטפל בכל בקשות ה-AJAX של פלאגין ListToGD
 * 
 * הקובץ הזה מרכז את כל הפונקציות שמתקשרות עם JavaScript בצד הלקוח:
 * - מיפוי שדות ותצוגה מקדימה
 * - חילוץ וייבוא נתונים
 * - עיבוד קבצים
 * - ניהול אצוות
 * - דוחות וסטטיסטיקות
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * רישום כל פונקציות ה-AJAX
 */
function ltgdai_register_ajax_handlers() {
    // === מיפוי שדות ===
    add_action('wp_ajax_ltgdai_get_field_samples', 'ltgdai_ajax_get_field_samples');
    add_action('wp_ajax_ltgdai_save_field_processing', 'ltgdai_ajax_save_field_processing');
    add_action('wp_ajax_ltgdai_preview_processed_samples', 'ltgdai_ajax_preview_processed_samples');
    add_action('wp_ajax_ltgdai_reset_mapping', 'ltgdai_ajax_reset_mapping');
    add_action('wp_ajax_ltgdai_reset_tab_completion', 'ltgdai_ajax_reset_tab_completion');
    
    // === חילוץ נתונים ===
    add_action('wp_ajax_ltgdai_extract_data_again', 'ltgdai_ajax_extract_data_again');
    add_action('wp_ajax_ltgdai_extract_data_fresh', 'ltgdai_ajax_extract_data_fresh');
    
    // === ייבוא נתונים ===
    add_action('wp_ajax_ltgdai_start_import', 'ltgdai_ajax_start_import');
    add_action('wp_ajax_ltgdai_get_import_status', 'ltgdai_ajax_get_import_status');
    
    // === עיבוד קבצים ===
    add_action('wp_ajax_ltgdai_process_file_field', 'ltgdai_ajax_process_file_field');
    add_action('wp_ajax_ltgdai_check_file_status', 'ltgdai_ajax_check_file_status');
    
    // === עיבוד אצוות ===
    add_action('wp_ajax_ltgdai_start_batch_import', 'ltgdai_ajax_start_batch_import');
    add_action('wp_ajax_ltgdai_continue_batch_import', 'ltgdai_ajax_continue_batch_import');
    add_action('wp_ajax_ltgdai_get_batch_status', 'ltgdai_ajax_get_batch_status');
    
    // === ניהול לשוניות ודוחות ===
    add_action('wp_ajax_ltgdai_delete_completed_tab', 'ltgdai_ajax_delete_completed_tab');
    add_action('wp_ajax_ltgdai_enrich_session_report', 'ltgdai_ajax_enrich_session_report');
}
add_action('init', 'ltgdai_register_ajax_handlers');

// ===== AJAX פונקציות מיפוי שדות =====

/**
 * קבלת דוגמאות לשדה - מציג למשתמש איך השדה נראה
 */
function ltgdai_ajax_get_field_samples() {
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

    if (empty($field) || empty($post_type) || empty($url)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
    $extractor = new ContentExtractor();
    $samples = $extractor->get_field_samples($field, $post_type, $url, 5);

    wp_send_json_success(array('samples' => $samples));
}

/**
 * שמירת הגדרות עיבוד שדה - איך לעבד את הנתונים
 */
function ltgdai_ajax_save_field_processing() {
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $source_field = isset($_POST['source_field']) ? sanitize_text_field($_POST['source_field']) : '';
    $gd_field = isset($_POST['gd_field']) ? sanitize_text_field($_POST['gd_field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $processing_params = isset($_POST['processing_params']) ? $_POST['processing_params'] : array();

    if (empty($source_field) || empty($gd_field) || empty($post_type) || empty($url)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

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

    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    $result = $mapping_handler->save_field_processing($post_type, $url, $gd_field, $source_field, $sanitized_params);

    if ($result) {
        wp_send_json_success(array('message' => 'פרמטרי העיבוד נשמרו בהצלחה'));
    } else {
        wp_send_json_error(array('message' => 'שגיאה בשמירת פרמטרי העיבוד'));
    }
}

/**
 * תצוגה מקדימה של נתונים מעובדים
 */
function ltgdai_ajax_preview_processed_samples() {
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $processing_params = isset($_POST['processing_params']) ? $_POST['processing_params'] : array();

    if (empty($field) || empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

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

    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    $processed_samples = $mapping_handler->preview_processed_samples($field, $post_type, $url, $sanitized_params);

    wp_send_json_success(array('samples' => $processed_samples));
}

/**
 * איפוס מיפוי שדות
 */
function ltgdai_ajax_reset_mapping() {
    if (!check_ajax_referer('ltgdai_mapping_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $option_name = 'ltgdai_field_mappings_' . $post_type;
    delete_option($option_name);

    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    $index = array_search($post_type, $completed_tabs);
    if ($index !== false) {
        unset($completed_tabs[$index]);
        update_option('ltgdai_completed_tabs', $completed_tabs);
    }

    wp_send_json_success(array('message' => 'המיפוי אופס בהצלחה'));
}

/**
 * איפוס סימון לשונית כמושלמת
 */
function ltgdai_ajax_reset_tab_completion() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    $index = array_search($post_type, $completed_tabs);
    if ($index !== false) {
        unset($completed_tabs[$index]);
        update_option('ltgdai_completed_tabs', array_values($completed_tabs));
    }

    wp_send_json_success(array('message' => 'הסימון כמוכן להזנה בוטל בהצלחה'));
}

// ===== AJAX פונקציות חילוץ נתונים =====

/**
 * חילוץ נתונים מחדש
 */
function ltgdai_ajax_extract_data_again() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

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

    require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
    $extractor = new ContentExtractor();
    $extracted_count = 0;

    foreach ($post_type_urls as $url) {
        if (!empty($url)) {
            $extracted_data = $extractor->extract_from_url($url);
            if ($extracted_data['success']) {
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
 * חילוץ נתונים עם זיהוי קישורים משופר
 */
function ltgdai_ajax_extract_data_fresh() {
    if (!check_ajax_referer('ltgdai_extract_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $urls = get_option('ltgdai_saved_urls', array());
    $post_types = get_option('ltgdai_saved_post_types', array());

    $post_type_urls = array();
    foreach ($urls as $index => $url) {
        if (isset($post_types[$index]) && $post_types[$index] === $post_type && !empty($url)) {
            $post_type_urls[] = $url;
        }
    }

    if (empty($post_type_urls)) {
        wp_send_json_error(array('message' => 'לא נמצאו URL-ים לסוג הפוסט הזה'));
    }

    require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
    $extractor = new ContentExtractor();

    $extracted_count = 0;
    $total_urls = count($post_type_urls);
    $errors = array();

    foreach ($post_type_urls as $url) {
        try {
            $option_name = 'ltgdai_extracted_data_' . $post_type . '_' . md5($url);
            delete_option($option_name);

            $extracted_data = $extractor->extract_from_url($url);

            if ($extracted_data['success']) {
                update_option($option_name, $extracted_data);
                $extracted_count++;
            } else {
                $errors[] = "URL: {$url} - " . $extracted_data['message'];
            }
        } catch (Exception $e) {
            $errors[] = "URL: {$url} - שגיאה: " . $e->getMessage();
        }
    }

    if ($extracted_count > 0) {
        $message = "חולצו מחדש נתונים מ-{$extracted_count} מתוך {$total_urls} קישורים";
        if (!empty($errors)) {
            $message .= ". שגיאות: " . implode(', ', array_slice($errors, 0, 2));
            if (count($errors) > 2) {
                $message .= " ועוד...";
            }
        }

        wp_send_json_success(array(
            'message' => $message,
            'extracted_count' => $extracted_count,
            'total_urls' => $total_urls,
            'errors' => $errors
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'לא הצלחנו לחלץ נתונים מאף קישור',
            'errors' => $errors
        ));
    }
}

// ===== AJAX פונקציות ייבוא =====

/**
 * התחלת ייבוא לסוג פוסט
 */
function ltgdai_ajax_start_import() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    require_once LTGDAI_PLUGIN_DIR . 'classes/DataImporter.php';
    $importer = new DataImporter();
    $results = $importer->import_post_type($post_type);

    update_option('ltgdai_import_results_' . $post_type, $results);

    if ($results['success']) {
        wp_send_json_success($results);
    } else {
        wp_send_json_error($results);
    }
}

/**
 * קבלת מצב ייבוא נוכחי
 */
function ltgdai_ajax_get_import_status() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $results = get_option('ltgdai_import_results_' . $post_type, null);

    if ($results) {
        wp_send_json_success($results);
    } else {
        wp_send_json_error(array('message' => 'לא נמצאו תוצאות'));
    }
}

// ===== AJAX פונקציות עיבוד קבצים =====

/**
 * עיבוד שדה שמכיל קישור לקובץ
 */
function ltgdai_ajax_process_file_field() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $field_value = isset($_POST['field_value']) ? esc_url_raw($_POST['field_value']) : '';

    if (empty($field_value)) {
        wp_send_json_error(array('message' => 'ערך שדה חסר'));
    }

    require_once LTGDAI_PLUGIN_DIR . 'classes/FileProcessor.php';
    $file_processor = new FileProcessor();
    $processed_value = $file_processor->process_field_value($field_value);

    $file_processed = ($processed_value !== $field_value);

    wp_send_json_success(array(
        'original_value' => $field_value,
        'processed_value' => $processed_value,
        'file_processed' => $file_processed,
        'message' => $file_processed ? 'קובץ עובד בהצלחה!' : 'זה לא קישור לקובץ'
    ));
}

/**
 * בדיקת מצב קובץ - האם כבר קיים במערכת
 */
function ltgdai_ajax_check_file_status() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $file_url = isset($_POST['file_url']) ? esc_url_raw($_POST['file_url']) : '';

    if (empty($file_url)) {
        wp_send_json_error(array('message' => 'קישור קובץ חסר'));
    }

    global $wpdb;
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} 
         WHERE meta_key = 'ltgdai_original_url' 
         AND meta_value = %s 
         LIMIT 1",
        $file_url
    ));

    if ($attachment_id) {
        $new_url = wp_get_attachment_url($attachment_id);
        if ($new_url) {
            wp_send_json_success(array(
                'exists' => true,
                'attachment_id' => $attachment_id,
                'new_url' => $new_url,
                'message' => 'הקובץ כבר קיים במערכת'
            ));
        }
    }

    wp_send_json_success(array(
        'exists' => false,
        'message' => 'הקובץ עדיין לא יובא'
    ));
}

// ===== AJAX פונקציות עיבוד אצוות =====

/**
 * התחלת עיבוד אצוות
 */
function ltgdai_ajax_start_batch_import() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    try {
        require_once LTGDAI_PLUGIN_DIR . 'classes/BatchProcessor.php';
        $batch_processor = new BatchProcessor();
        $results = $batch_processor->start_batch_import($post_type);

        if ($results['success']) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error($results);
        }
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'שגיאה בהתחלת עיבוד האצוות: ' . $e->getMessage()));
    }
}

/**
 * המשך עיבוד האצווה הבאה
 */
function ltgdai_ajax_continue_batch_import() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    try {
        require_once LTGDAI_PLUGIN_DIR . 'classes/BatchProcessor.php';
        $batch_processor = new BatchProcessor();
        $results = $batch_processor->process_next_batch($post_type);

        if ($results['success']) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error($results);
        }
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'שגיאה בהמשך עיבוד האצוות: ' . $e->getMessage()));
    }
}

/**
 * קבלת מצב עיבוד האצוות
 */
function ltgdai_ajax_get_batch_status() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $batch_state_option = 'ltgdai_batch_state_' . $post_type;
    $batch_state = get_option($batch_state_option, null);

    if ($batch_state) {
        $progress = round(($batch_state['processed_items'] / $batch_state['total_items']) * 100, 1);

        wp_send_json_success(array(
            'active' => true,
            'current_batch' => $batch_state['current_batch'],
            'total_batches' => $batch_state['total_batches'],
            'processed_items' => $batch_state['processed_items'],
            'total_items' => $batch_state['total_items'],
            'progress' => $progress
        ));
    } else {
        wp_send_json_success(array(
            'active' => false,
            'message' => 'אין תהליך עיבוד פעיל'
        ));
    }
}

// ===== AJAX פונקציות ניהול =====

/**
 * מחיקת לשונית שהושלמה
 */
function ltgdai_ajax_delete_completed_tab() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';

    if (empty($post_type)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    $deleted_items = 0;

    // מחיקת נתונים מחולצים
    $urls = get_option('ltgdai_saved_urls', array());
    $post_types = get_option('ltgdai_saved_post_types', array());

    foreach ($urls as $index => $url) {
        if (isset($post_types[$index]) && $post_types[$index] === $post_type && !empty($url)) {
            $option_name = 'ltgdai_extracted_data_' . $post_type . '_' . md5($url);
            delete_option($option_name);
        }
    }

    // מחיקת מיפויים
    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    $mapping_handler->delete_field_mappings($post_type);

    // הסרה מרשימת לשוניות מושלמות
    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    $completed_tabs = array_diff($completed_tabs, array($post_type));
    update_option('ltgdai_completed_tabs', array_values($completed_tabs));

    // מחיקת URLs של הלשונית
    $filtered_urls = array();
    $filtered_post_types = array();

    foreach ($urls as $index => $url) {
        if (isset($post_types[$index]) && $post_types[$index] !== $post_type) {
            $filtered_urls[] = $url;
            $filtered_post_types[] = $post_types[$index];
        } else if (isset($post_types[$index]) && $post_types[$index] === $post_type) {
            $deleted_items++;
        }
    }

    update_option('ltgdai_saved_urls', $filtered_urls);
    update_option('ltgdai_saved_post_types', $filtered_post_types);

    // ניקוי נתוני סטטיסטיקות
    delete_option('ltgdai_import_stats_' . $post_type);
    delete_option('ltgdai_batch_state_' . $post_type);
    delete_option('ltgdai_import_results_' . $post_type);

    // בדיקה אם יש עוד לשוניות
    $remaining_post_types = array_unique($filtered_post_types);
    $has_remaining_tabs = !empty($remaining_post_types);

    $response_data = array(
        'deleted_items' => $deleted_items,
        'has_remaining_tabs' => $has_remaining_tabs
    );

    if ($has_remaining_tabs) {
        $next_post_type = $remaining_post_types[0];
        $response_data['next_tab_url'] = admin_url('admin.php?page=ltgdai-mapping&tab=' . $next_post_type);
        $response_data['message'] = "הלשונית '$post_type' נמחקה. מעבר للשונית הבאה...";
    } else {
        $response_data['import_page_url'] = admin_url('admin.php?page=ltgdai-import');
        $response_data['message'] = "כל הלשוניות הושלמו! חוזר לעמוד היבוא...";
    }

    wp_send_json_success($response_data);
}

/**
 * העשרת דוח סשן עם נתונים נוספים
 */
function ltgdai_ajax_enrich_session_report() {
    if (!check_ajax_referer('ltgdai_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'בדיקת אבטחה נכשלה'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'אין לך הרשאות מתאימות'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    $session_data_json = isset($_POST['session_data']) ? $_POST['session_data'] : '';

    if (empty($post_type) || empty($session_data_json)) {
        wp_send_json_error(array('message' => 'פרמטרים חסרים'));
    }

    try {
        $session_data = json_decode(stripslashes($session_data_json), true);
        
        if (!$session_data || !isset($session_data['posts'])) {
            wp_send_json_error(array('message' => 'נתוני סשן לא תקינים'));
        }
        
        // העשרת כל פוסט בסשן
        foreach ($session_data['posts'] as &$post) {
            $post_id = $post['post_id'];
            
            if (!empty($post_id) && is_numeric($post_id)) {
                $wp_post = get_post($post_id);
                
                if ($wp_post) {
                    $post['title'] = $wp_post->post_title;
                    $post['post_url'] = get_edit_post_link($post_id);
                    $post['creation_status'] = ($wp_post->post_status === 'publish') ? 'פורסם' : 'טיוטה';
                    
                    // בדיקת קבצים שנכשלו
                    $failed_files = get_post_meta($post_id, 'ltgdai_failed_files', true);
                    if (!empty($failed_files) && is_array($failed_files)) {
                        $post['failed_files_count'] = count($failed_files);
                        $post['failed_files'] = array_column($failed_files, 'original_url');
                        $post['notes'] .= '; ' . count($failed_files) . ' קבצים נכשלו';
                    }
                    
                    // חיפוש קבצים שהועלו
                    $uploaded_files = array();
                    $all_meta = get_post_meta($post_id);
                    
                    foreach ($all_meta as $meta_key => $meta_values) {
                        if (!empty($meta_values[0]) && filter_var($meta_values[0], FILTER_VALIDATE_URL)) {
                            $url = $meta_values[0];
                            $upload_dir = wp_upload_dir();
                            
                            if (strpos($url, $upload_dir['baseurl']) === 0) {
                                $uploaded_files[] = $url;
                            }
                        }
                    }
                    
                    $post['uploaded_files'] = $uploaded_files;
                    $post['uploaded_files_count'] = count($uploaded_files);
                }
            }
        }
        
        // עדכון סיכום
        $session_data['summary']['enriched_at'] = current_time('mysql');
        $session_data['summary']['total_uploaded_files'] = array_sum(array_column($session_data['posts'], 'uploaded_files_count'));
        $session_data['summary']['total_failed_files'] = array_sum(array_column($session_data['posts'], 'failed_files_count'));
        
        wp_send_json_success(array(
            'enriched_report' => $session_data,
            'message' => 'דוח הועשר בהצלחה'
        ));
        
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'שגיאה בהעשרת הדוח: ' . $e->getMessage()));
    }
}