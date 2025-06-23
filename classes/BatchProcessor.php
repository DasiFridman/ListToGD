<?php
/**
 * מערכת עיבוד אצוות מתקדמת עם דוח מפורט
 * פותרת בעיות של זמן ארוך ושגיאות במהלך התהליך
 */

if (!defined('ABSPATH')) {
    exit;
}

class BatchProcessor
{
    private $batch_size = 5;
    private $max_execution_time = 25;
    private $start_time;
    private $dynamic_handler;
    
    public function __construct()
    {
        $this->start_time = time();
        
        require_once LTGDAI_PLUGIN_DIR . 'classes/DynamicFieldHandler.php';
        $this->dynamic_handler = new DynamicFieldHandler();
        
        @set_time_limit(30);
        @ini_set('max_execution_time', 30);
    }
    
    /**
     * התחלת עיבוד אצוות
     */
    public function start_batch_import($post_type)
    {
        error_log("התחלת עיבוד אצווה");
        error_log("סוג פוסט: $post_type");
        
        try {
            $batch_data = $this->prepare_batch_data($post_type);
            
            if (empty($batch_data['items'])) {
                return array(
                    'success' => false,
                    'message' => 'No data found for processing',
                    'completed' => true
                );
            }
            
            $this->save_batch_state($post_type, $batch_data);
            
            return $this->process_next_batch($post_type);
            
        } catch (Exception $e) {
            error_log("שגיאת יבוא אצווה: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Error starting batch import: ' . $e->getMessage(),
                'completed' => true
            );
        }
    }
    
    /**
     * עיבוד האצווה הבאה
     */
    public function process_next_batch($post_type)
    {
        error_log("עיבוד האצווה הבאה");
        
        $batch_state = $this->get_batch_state($post_type);
        
        if (!$batch_state || empty($batch_state['items'])) {
            return array(
                'success' => true,
                'message' => 'Batch processing completed',
                'completed' => true,
                'stats' => $this->get_final_stats($post_type)
            );
        }
        
        $results = array(
            'success' => true,
            'completed' => false,
            'processed_in_batch' => 0,
            'created_in_batch' => 0,
            'failed_in_batch' => 0,
            'files_processed_in_batch' => 0,
            'current_batch' => $batch_state['current_batch'],
            'total_batches' => $batch_state['total_batches'],
            'progress' => 0,
            'created_posts' => array(),
            'errors' => array()
        );
        
        try {
            require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
            require_once LTGDAI_PLUGIN_DIR . 'classes/FileProcessor.php';
            
            $mapping_handler = new MappingHandler();
            $file_processor = new FileProcessor();
            
            $mappings = $mapping_handler->get_field_mappings($post_type);
            
            $batch_items = array_slice($batch_state['items'], 0, $this->batch_size);
            
            foreach ($batch_items as $item_index => $item) {
                if ($this->is_time_limit_reached()) {
                    error_log("הגענו למגבלת זמן, עוצרים אצווה");
                    break;
                }
                
                $results['processed_in_batch']++;
                
                try {
                    $post_result = $this->process_single_post_with_files_improved(
                        $post_type,
                        $item,
                        $mappings,
                        $file_processor
                    );
                    
                    if ($post_result && !is_wp_error($post_result['post_id'])) {
                        $results['created_in_batch']++;
                        $results['files_processed_in_batch'] += $post_result['files_processed'];
                        
                        $results['created_posts'][] = array(
                            'id' => $post_result['post_id'],
                            'title' => get_the_title($post_result['post_id']),
                            'files_imported' => $post_result['files_processed']
                        );
                        
                        $this->update_total_stats($post_type, 'created', 1);
                        $this->update_total_stats($post_type, 'files', $post_result['files_processed']);
                        
                    } else {
                        $results['failed_in_batch']++;
                        $error_msg = is_wp_error($post_result) ? 
                            $post_result->get_error_message() : 'Unknown error';
                        $results['errors'][] = "Item {$item_index}: {$error_msg}";
                        
                        $this->update_total_stats($post_type, 'failed', 1);
                    }
                    
                    $this->update_total_stats($post_type, 'processed', 1);
                    
                } catch (Exception $e) {
                    $results['failed_in_batch']++;
                    $results['errors'][] = "Item {$item_index}: " . $e->getMessage();
                    $this->update_total_stats($post_type, 'failed', 1);
                    error_log("שגיאה בעיבוד פריט {$item_index}: " . $e->getMessage());
                }
                
                array_shift($batch_state['items']);
            }
            
            $batch_state['current_batch']++;
            $batch_state['processed_items'] += $results['processed_in_batch'];
            
            $results['progress'] = round(
                ($batch_state['processed_items'] / $batch_state['total_items']) * 100, 
                1
            );
            
            if (empty($batch_state['items'])) {
                $results['completed'] = true;
                $results['message'] = 'All batches completed successfully!';
                $this->cleanup_batch_state($post_type);
                $results['stats'] = $this->get_final_stats($post_type);
            } else {
                $this->save_batch_state($post_type, $batch_state);
                $results['message'] = "Batch {$batch_state['current_batch']}/{$batch_state['total_batches']} completed";
            }
            
            error_log("אצווה הושלמה: " . print_r($results, true));
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Batch processing error: ' . $e->getMessage();
            error_log("שגיאה בעיבוד אצווה: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * הכנת נתונים לעיבוד אצוות
     */
    private function prepare_batch_data($post_type)
    {
        $urls = get_option('ltgdai_saved_urls', array());
        $post_types = get_option('ltgdai_saved_post_types', array());
        
        $all_items = array();
        
        foreach ($urls as $index => $url) {
            if (isset($post_types[$index]) && $post_types[$index] === $post_type && !empty($url)) {
                $option_name = 'ltgdai_extracted_data_' . $post_type . '_' . md5($url);
                $extracted_data = get_option($option_name);
                
                if (!empty($extracted_data) && $extracted_data['success']) {
                    $first_table = reset($extracted_data['data']);
                    if (!empty($first_table['rows'])) {
                        foreach ($first_table['rows'] as $row_data) {
                            $all_items[] = array(
                                'url' => $url,
                                'row_data' => $row_data,
                                'headers' => $first_table['headers']
                            );
                        }
                    }
                }
            }
        }
        
        $total_items = count($all_items);
        $total_batches = ceil($total_items / $this->batch_size);
        
        return array(
            'items' => $all_items,
            'total_items' => $total_items,
            'total_batches' => $total_batches,
            'current_batch' => 1,
            'processed_items' => 0
        );
    }
    
    /**
     * עיבוד פוסט בודד עם קבצים ומעקב מפורט
     */
    private function process_single_post_with_files_improved($post_type, $item, $mappings, $file_processor)
    {
        $post_data = array_combine($item['headers'], $item['row_data']);
        $files_processed = 0;
        $failed_files = array();
        $original_files = array();
        
        if (empty($post_data)) {
            throw new Exception('Unable to process row data');
        }
        
        $url_key = md5($item['url']);
        $field_mappings = isset($mappings['field_mappings'][$url_key]) ?
            $mappings['field_mappings'][$url_key] : array();
        
        $custom_fields = isset($mappings['custom_fields'][$url_key]) ? 
            $mappings['custom_fields'][$url_key] : array();
        
        if (empty($field_mappings)) {
            throw new Exception('No field mappings found for URL: ' . $item['url']);
        }
        
        error_log("עיבוד פוסט בודד עם טיפול דינמי ומעקב קבצים");
        error_log("מיפוי שדות: " . print_r($field_mappings, true));
        error_log("שדות מותאמים: " . print_r($custom_fields, true));
        error_log("נתוני פוסט: " . print_r($post_data, true));
        
        $post_title = '';
        $post_content = '';
        $post_excerpt = '';
        
        foreach ($field_mappings as $gd_field => $source_field) {
            if (isset($post_data[$source_field])) {
                $value = $post_data[$source_field];
                
                switch ($gd_field) {
                    case 'post_title':
                        $post_title = $this->clean_title($value);
                        error_log("נמצאה כותרת: '$post_title' מהשדה: '$source_field'");
                        break;
                    case 'post_content':
                        $post_content = wp_kses_post($value);
                        error_log("נמצא תוכן: '$post_content' מהשדה: '$source_field'");
                        break;
                    case 'post_excerpt':
                        $post_excerpt = sanitize_text_field($value);
                        error_log("נמצא תקציר: '$post_excerpt' מהשדה: '$source_field'");
                        break;
                }
            }
        }
        
        if (empty($post_title)) {
            $post_title = $this->find_title_automatically($post_data);
            if (empty($post_title)) {
                $post_title = 'New Post ' . date('Y-m-d H:i:s');
            }
        }

        $post_args = array(
            'post_type' => $post_type,
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status' => 'publish',
        );
        
        if (!empty($post_excerpt)) {
            $post_args['post_excerpt'] = $post_excerpt;
        }
        
        error_log("יוצר פוסט עם פרמטרים: " . print_r($post_args, true));
        
        $new_post_id = wp_insert_post($post_args);
        
        if (is_wp_error($new_post_id) || !$new_post_id) {
            throw new Exception('Failed to create post');
        }
        
        error_log("פוסט נוצר בהצלחה עם ID: $new_post_id");
        
        // עיבוד שדות רגילים עם מעקב קבצים
        foreach ($field_mappings as $gd_field => $source_field) {
            if (in_array($gd_field, array('post_title', 'post_content', 'post_excerpt'))) {
                continue;
            }
            
            if (isset($post_data[$source_field]) && !empty($post_data[$source_field])) {
                $field_value = $post_data[$source_field];
                
                error_log("עיבוד שדה עם טיפול דינמי: '$gd_field' <- '$source_field' = '$field_value'");
                
                $is_file_url = $this->is_file_url_check($field_value);
                
                if ($is_file_url) {
                    $original_files[] = array(
                        'field' => $gd_field,
                        'original_url' => $field_value,
                        'filename' => $this->extract_filename($field_value)
                    );
                }
                
                $processed_value = $file_processor->process_field_value($field_value, $new_post_id);
                
                if ($processed_value !== $field_value) {
                    if ($is_file_url) {
                        if ($this->verify_file_upload_success($processed_value)) {
                            $files_processed++;
                            error_log("קובץ עובד בהצלחה עבור שדה '$gd_field'");
                        } else {
                            $failed_files[] = array(
                                'field' => $gd_field,
                                'original_url' => $field_value,
                                'processed_url' => $processed_value,
                                'filename' => $this->extract_filename($field_value),
                                'error' => 'File upload verification failed'
                            );
                            error_log("העלאת קובץ נכשלה עבור שדה '$gd_field'");
                        }
                    }
                } else if ($is_file_url) {
                    $failed_files[] = array(
                        'field' => $gd_field,
                        'original_url' => $field_value,
                        'processed_url' => $processed_value,
                        'filename' => $this->extract_filename($field_value),
                        'error' => 'File not processed - may not be accessible'
                    );
                    error_log("קובץ לא עובד עבור שדה '$gd_field'");
                }
                
                $save_result = $this->dynamic_handler->save_field_value_dynamic(
                    $new_post_id, 
                    $gd_field, 
                    $processed_value, 
                    $post_type
                );
                
                if (!$save_result) {
                    error_log("נכשל לשמור שדה '$gd_field' עם טיפול דינמי");
                } else {
                    error_log("הצליח לשמור שדה '$gd_field' עם טיפול דינמי");
                }
            }
        }
        
        // עיבוד השדות המותאמים עם מעקב קבצים
        if (!empty($custom_fields)) {
            error_log("עיבוד שדות מותאמים עם מעקב קבצים");
            
            foreach ($custom_fields as $custom_field) {
                if (!empty($custom_field['name']) && !empty($custom_field['gd_field'])) {
                    $custom_value = $custom_field['name'];
                    $custom_gd_field = $custom_field['gd_field'];
                    
                    error_log("עיבוד שדה מותאם: '$custom_gd_field' = '$custom_value'");
                    
                    $is_file_url = $this->is_file_url_check($custom_value);
                    
                    if ($is_file_url) {
                        $original_files[] = array(
                            'field' => $custom_gd_field,
                            'original_url' => $custom_value,
                            'filename' => $this->extract_filename($custom_value)
                        );
                    }
                    
                    $processed_custom_value = $file_processor->process_field_value($custom_value, $new_post_id);
                    
                    if ($processed_custom_value !== $custom_value) {
                        if ($is_file_url) {
                            if ($this->verify_file_upload_success($processed_custom_value)) {
                                $files_processed++;
                                error_log("קובץ מותאם עובד בהצלחה עבור שדה '$custom_gd_field'");
                            } else {
                                $failed_files[] = array(
                                    'field' => $custom_gd_field,
                                    'original_url' => $custom_value,
                                    'processed_url' => $processed_custom_value,
                                    'filename' => $this->extract_filename($custom_value),
                                    'error' => 'Custom field file upload failed'
                                );
                            }
                        }
                    } else if ($is_file_url) {
                        $failed_files[] = array(
                            'field' => $custom_gd_field,
                            'original_url' => $custom_value,
                            'processed_url' => $processed_custom_value,
                            'filename' => $this->extract_filename($custom_value),
                            'error' => 'Custom field file not processed'
                        );
                    }
                    
                    $custom_save_result = $this->dynamic_handler->save_field_value_dynamic(
                        $new_post_id, 
                        $custom_gd_field, 
                        $processed_custom_value, 
                        $post_type
                    );
                    
                    if (!$custom_save_result) {
                        error_log("נכשל לשמור שדה מותאם '$custom_gd_field'");
                    } else {
                        error_log("הצליח לשמור שדה מותאם '$custom_gd_field'");
                    }
                }
            }
        }
        
        $this->add_geodirectory_defaults_safe($new_post_id, $post_type);
        $this->sync_to_geodirectory_detail_table_improved($new_post_id, $post_type);
        
        $import_metadata = array(
            'source_url' => $item['url'],
            'failed_files' => $failed_files,
            'original_files' => $original_files,
            'stats' => array(
                'files_processed' => $files_processed,
                'files_failed' => count($failed_files),
                'total_original_files' => count($original_files)
            )
        );
        
        $this->save_import_metadata($new_post_id, $import_metadata);
        
        return array(
            'post_id' => $new_post_id,
            'files_processed' => $files_processed,
            'files_failed' => count($failed_files),
            'original_files_count' => count($original_files),
            'failed_files' => $failed_files
        );
    }
    
    /**
     * בדיקה אם ערך הוא קישור לקובץ
     */
    private function is_file_url_check($value)
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $file_extensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar');
        
        $extension = strtolower(pathinfo(parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        if (in_array($extension, $file_extensions)) {
            return true;
        }
        
        $patterns = array(
            '/\.ashx.*FileID/i',
            '/GetPdfFile/i',
            '/GetFile/i',
            '/download.*\.(pdf|doc|docx|xls|xlsx)/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * אימות שהקובץ הועלה בהצלחה
     */
    private function verify_file_upload_success($url)
    {
        $site_url = site_url();
        $upload_dir = wp_upload_dir();
        
        if (strpos($url, $upload_dir['baseurl']) === 0 || strpos($url, $site_url) === 0) {
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
            return file_exists($file_path) && filesize($file_path) > 0;
        }
        
        return false;
    }

    /**
     * חילוץ שם קובץ מURL
     */
    private function extract_filename($url)
    {
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        
        $filename = basename($path);
        
        if (empty($filename) || strpos($filename, '.') === false) {
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $params);
                
                if (isset($params['FileID'])) {
                    return 'קובץ_' . $params['FileID'];
                }
                
                if (isset($params['file'])) {
                    return basename($params['file']);
                }
            }
            
            return 'קובץ_לא_ידוע';
        }
        
        return $filename;
    }

    /**
     * שמירת מטאדטה לדוח
     */
    private function save_import_metadata($post_id, $import_metadata)
    {
        update_post_meta($post_id, 'ltgdai_imported', true);
        update_post_meta($post_id, 'ltgdai_import_date', current_time('mysql'));
        
        if (!empty($import_metadata['failed_files'])) {
            update_post_meta($post_id, 'ltgdai_failed_files', $import_metadata['failed_files']);
        }
        
        if (!empty($import_metadata['source_url'])) {
            update_post_meta($post_id, 'ltgdai_source_url', $import_metadata['source_url']);
        }
        
        if (!empty($import_metadata['stats'])) {
            update_post_meta($post_id, 'ltgdai_import_stats', $import_metadata['stats']);
        }
    }
    
    /**
     * הוספת ברירות מחדל בטוחה
     */
    private function add_geodirectory_defaults_safe($post_id, $post_type)
    {
        if (substr($post_type, 0, 3) === 'gd_') {
            error_log("הוספת ברירות מחדל GeoDirectory בבטחה לפוסט: $post_id");

            if (!get_post_meta($post_id, 'geodir_latitude', true)) {
                update_post_meta($post_id, 'geodir_latitude', '31.7683');
                update_post_meta($post_id, 'geodir_longitude', '35.2137');
                update_post_meta($post_id, 'geodir_city', 'Jerusalem');
                update_post_meta($post_id, 'geodir_region', 'Jerusalem');
                update_post_meta($post_id, 'geodir_country', 'Israel');
            }

            if (!get_post_meta($post_id, 'geodir_featured', true)) {
                update_post_meta($post_id, 'geodir_featured', '0');
            }

            error_log("ברירות מחדל GeoDirectory נוספו בבטחה");
        }
    }
    
    /**
     * סינכרון משופר לטבלת הפרטים של GeoDirectory
     */
    private function sync_to_geodirectory_detail_table_improved($post_id, $post_type)
    {
        error_log("סינכרון משופר לטבלת פרטים GeoDirectory");
        error_log("Post ID: $post_id, Post Type: $post_type");
        
        global $wpdb;
        
        $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$detail_table'") == $detail_table;
        if (!$table_exists) {
            error_log("טבלת פרטים לא קיימת: $detail_table");
            return false;
        }
        
        $columns_query = "SHOW COLUMNS FROM $detail_table";
        $columns_result = $wpdb->get_results($columns_query);
        $existing_columns = array();
        
        foreach ($columns_result as $column) {
            $existing_columns[] = $column->Field;
        }
        
        error_log("עמודות זמינות בטבלת פרטים: " . implode(', ', $existing_columns));
        
        $all_meta = get_post_meta($post_id);
        error_log("כל המטא לפוסט $post_id: " . print_r(array_keys($all_meta), true));
        
        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $detail_table WHERE post_id = %d",
            $post_id
        ));
        
        $update_data = array();
        
        $post = get_post($post_id);
        if ($post) {
            if (in_array('post_title', $existing_columns)) {
                $update_data['post_title'] = $post->post_title;
            }
            if (in_array('post_status', $existing_columns)) {
                $update_data['post_status'] = $post->post_status;
            }
        }
        
        foreach ($all_meta as $meta_key => $meta_values) {
            if (strpos($meta_key, '_') === 0) {
                continue;
            }
            
            if (in_array($meta_key, $existing_columns) && !empty($meta_values[0])) {
                $value = $meta_values[0];
                $update_data[$meta_key] = $value;
                error_log("מוסיף לעדכון: $meta_key = $value");
            } else {
                if (!in_array($meta_key, $existing_columns)) {
                    error_log("שדה $meta_key דולג: עמודה לא קיימת בטבלת פרטים");
                }
            }
        }
        
        if (in_array('post_category', $existing_columns)) {
            $categories = $this->get_post_categories($post_id, $post_type);
            if (!empty($categories)) {
                $update_data['post_category'] = $categories;
                error_log("קטגוריות: " . $update_data['post_category']);
            }
        }
        
        if (in_array('post_tags', $existing_columns)) {
            $tags = $this->get_post_tags($post_id, $post_type);
            if (!empty($tags)) {
                $update_data['post_tags'] = $tags;
                error_log("תגיות: " . $update_data['post_tags']);
            }
        }
        
        $geo_mappings = array(
            'geodir_latitude' => 'latitude',
            'geodir_longitude' => 'longitude', 
            'geodir_city' => 'city',
            'geodir_region' => 'region',
            'geodir_country' => 'country',
            'geodir_featured' => 'featured'
        );
        
        foreach ($geo_mappings as $meta_key => $table_field) {
            if (in_array($table_field, $existing_columns)) {
                $value = get_post_meta($post_id, $meta_key, true);
                if (!empty($value)) {
                    $update_data[$table_field] = $value;
                    error_log("מוסיף שדה גיאוגרפי: $table_field = $value");
                }
            }
        }
        
        $important_fields = array(
            'gd__protocols__year',
            'gd__protocols__file', 
            'gd_protocols_date',
            'address'
        );
        
        foreach ($important_fields as $important_field) {
            if (in_array($important_field, $existing_columns)) {
                $value = get_post_meta($post_id, $important_field, true);
                if (!empty($value) && !isset($update_data[$important_field])) {
                    $update_data[$important_field] = $value;
                    error_log("מוסיף שדה חשוב: $important_field = $value");
                }
            }
        }
        
        error_log("נתוני עדכון סופיים לטבלת פרטים:");
        error_log(print_r($update_data, true));
        
        if (empty($update_data)) {
            error_log("אין נתונים לעדכון בטבלת פרטים");
            return false;
        }
        
        if ($existing_row) {
            $result = $wpdb->update(
                $detail_table,
                $update_data,
                array('post_id' => $post_id)
            );
            
            if ($result === false) {
                error_log("נכשל לעדכן טבלת פרטים: " . $wpdb->last_error);
                return false;
            }
            
            error_log("עודכנה בהצלחה שורה קיימת בטבלת פרטים");
        } else {
            $update_data['post_id'] = $post_id;
            
            $result = $wpdb->insert($detail_table, $update_data);
            
            if ($result === false) {
                error_log("נכשל להוסיף לטבלת פרטים: " . $wpdb->last_error);
                return false;
            }
            
            error_log("הוספה בהצלחה שורה חדשה לטבלת פרטים");
        }
        
        $verification_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $detail_table WHERE post_id = %d",
            $post_id
        ));
        
        error_log("אימות - נתונים בטבלת פרטים:");
        error_log(print_r($verification_row, true));
        
        return true;
    }
    
    /**
     * קבלת קטגוריות הפוסט
     */
    private function get_post_categories($post_id, $post_type)
    {
        $taxonomies = array(
            $post_type . 'category',
            'gd_' . str_replace('gd_', '', $post_type) . 'category',
            $post_type . '_category',
            'category'
        );
        
        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
                if (!empty($terms) && !is_wp_error($terms)) {
                    return implode(',', $terms);
                }
            }
        }
        
        return '';
    }
    
    /**
     * קבלת תגיות הפוסט
     */
    private function get_post_tags($post_id, $post_type)
    {
        $taxonomies = array(
            $post_type . '_tags',
            'gd_' . str_replace('gd_', '', $post_type) . '_tags',
            $post_type . 'tags',
            'post_tag'
        );
        
        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
                if (!empty($terms) && !is_wp_error($terms)) {
                    return implode(',', $terms);
                }
            }
        }
        
        return '';
    }
    
    /**
     * מציאת כותרת אוטומטית
     */
    private function find_title_automatically($post_data)
    {
        $title_keywords = array('title', 'name', 'subject', 'heading', 'שם', 'כותרת', 'פרוטוקול');

        foreach ($post_data as $key => $value) {
            $key_lower = strtolower($key);
            foreach ($title_keywords as $keyword) {
                if (strpos($key_lower, strtolower($keyword)) !== false && !empty($value)) {
                    return $this->clean_title($value);
                }
            }
        }

        foreach ($post_data as $value) {
            if (!empty(trim($value))) {
                return $this->clean_title($value);
            }
        }

        return '';
    }
    
    /**
     * ניקוי כותרת
     */
    private function clean_title($title)
    {
        $cleaned = sanitize_text_field($title);
        
        if (strpos($cleaned, ':') !== false) {
            if (!preg_match('/\d{2}:\d{2}/', $cleaned)) {
                $parts = explode(':', $cleaned, 2);
                if (count($parts) == 2) {
                    $cleaned = trim($parts[1]);
                }
            }
        }
        
        return !empty($cleaned) ? $cleaned : '';
    }
    
    /**
     * שמירת מצב האצווה
     */
    private function save_batch_state($post_type, $batch_data)
    {
        $option_name = 'ltgdai_batch_state_' . $post_type;
        update_option($option_name, $batch_data);
    }
    
    /**
     * קבלת מצב האצווה
     */
    private function get_batch_state($post_type)
    {
        $option_name = 'ltgdai_batch_state_' . $post_type;
        return get_option($option_name, null);
    }
    
    /**
     * ניקוי מצב האצווה
     */
    private function cleanup_batch_state($post_type)
    {
        $option_name = 'ltgdai_batch_state_' . $post_type;
        delete_option($option_name);
    }
    
    /**
     * עדכון סטטיסטיקות כלליות
     */
    private function update_total_stats($post_type, $type, $count)
    {
        $stats_option = 'ltgdai_import_stats_' . $post_type;
        $stats = get_option($stats_option, array(
            'processed' => 0,
            'created' => 0,
            'failed' => 0,
            'files' => 0
        ));
        
        $stats[$type] += $count;
        update_option($stats_option, $stats);
    }
    
    /**
     * קבלת סטטיסטיקות סופיות
     */
    private function get_final_stats($post_type)
    {
        $stats_option = 'ltgdai_import_stats_' . $post_type;
        $stats = get_option($stats_option, array());
        
        delete_option($stats_option);
        
        return $stats;
    }
    
    /**
     * בדיקה אם הגענו למגבלת הזמן
     */
    private function is_time_limit_reached()
    {
        return (time() - $this->start_time) >= $this->max_execution_time;
    }
}