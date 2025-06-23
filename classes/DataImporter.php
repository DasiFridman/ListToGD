<?php

if (!defined('ABSPATH')) {
    exit;
}

class DataImporter
{
    private $mapping_handler;
    private $content_extractor;
    private $file_processor;
    private $dynamic_handler;
    private $debug_logs = array();

    public function __construct()
    {
        require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
        require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
        require_once LTGDAI_PLUGIN_DIR . 'classes/FileProcessor.php';
        require_once LTGDAI_PLUGIN_DIR . 'classes/DynamicFieldHandler.php';

        $this->mapping_handler = new MappingHandler();
        $this->content_extractor = new ContentExtractor();
        $this->file_processor = new FileProcessor();
        $this->dynamic_handler = new DynamicFieldHandler();
    }

    /**
     * יבוא סוג פוסט
     */
    public function import_post_type($post_type)
    {
        require_once LTGDAI_PLUGIN_DIR . 'classes/BatchProcessor.php';
        $batch_processor = new BatchProcessor();
        
        return $batch_processor->start_batch_import($post_type);
    }

    /**
     * יצירת פוסט עם קבצים ושדות מותאמים
     */
    private function create_post_with_files_and_custom_fields($post_type, $row_data, $headers, $field_mappings, $custom_fields = array())
    {
        $post_data = array_combine($headers, $row_data);
        $files_processed = 0;

        if (empty($post_data)) {
            throw new Exception('Unable to process row data');
        }

        $post_title = '';
        $post_content = '';
        $post_excerpt = '';

        // חילוץ שדות בסיסיים
        foreach ($field_mappings as $gd_field => $source_field) {
            if (isset($post_data[$source_field])) {
                $value = $post_data[$source_field];
                
                switch ($gd_field) {
                    case 'post_title':
                        $post_title = $this->clean_title($value);
                        break;
                    case 'post_content':
                        $post_content = wp_kses_post($value);
                        break;
                    case 'post_excerpt':
                        $post_excerpt = sanitize_text_field($value);
                        break;
                }
            }
        }

        // וודא שיש כותרת
        if (empty($post_title)) {
            $post_title = $this->find_title_automatically($post_data);
            if (empty($post_title)) {
                $post_title = 'New Post ' . date('Y-m-d H:i:s');
            }
        }

        // יצירת הפוסט
        $post_args = array(
            'post_type' => $post_type,
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status' => 'publish',
        );

        if (!empty($post_excerpt)) {
            $post_args['post_excerpt'] = $post_excerpt;
        }

        $new_post_id = wp_insert_post($post_args);

        if (is_wp_error($new_post_id) || !$new_post_id) {
            throw new Exception('Failed to create post');
        }

        // שמירת השדות המותאמים עם המחלקה הדינמית
        foreach ($field_mappings as $gd_field => $source_field) {
            if (in_array($gd_field, array('post_title', 'post_content', 'post_excerpt'))) {
                continue;
            }

            if (isset($post_data[$source_field]) && !empty($post_data[$source_field])) {
                $field_value = $post_data[$source_field];
                
                // עיבוד הקובץ אם זה קישור
                $processed_value = $this->file_processor->process_field_value($field_value, $new_post_id);
                
                if ($processed_value !== $field_value) {
                    $files_processed++;
                }
                
                // שמירת השדה עם המחלקה הדינמית
                $this->dynamic_handler->save_field_value_dynamic(
                    $new_post_id, 
                    $gd_field, 
                    $processed_value, 
                    $post_type
                );
            }
        }

        // עיבוד השדות המותאמים
        if (!empty($custom_fields)) {
            foreach ($custom_fields as $custom_field) {
                if (!empty($custom_field['name']) && !empty($custom_field['gd_field'])) {
                    $custom_value = $custom_field['name'];
                    $custom_gd_field = $custom_field['gd_field'];
                    
                    // עיבוד הקובץ אם זה קישור
                    $processed_custom_value = $this->file_processor->process_field_value($custom_value, $new_post_id);
                    
                    if ($processed_custom_value !== $custom_value) {
                        $files_processed++;
                    }
                    
                    // שמירה דינמית עם המחלקה החדשה
                    $this->dynamic_handler->save_field_value_dynamic(
                        $new_post_id, 
                        $custom_gd_field, 
                        $processed_custom_value, 
                        $post_type
                    );
                }
            }
        }

        // הוספת ברירות מחדל
        $this->add_geodirectory_defaults($new_post_id, $post_type);

        // סינכרון לטבלת הפרטים
        $this->sync_to_geodirectory_detail_table($new_post_id, $post_type);

        return array(
            'post_id' => $new_post_id,
            'files_processed' => $files_processed
        );
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
     * הוספת ברירות מחדל של GeoDirectory
     */
    private function add_geodirectory_defaults($post_id, $post_type)
    {
        if (substr($post_type, 0, 3) === 'gd_') {
            if (!get_post_meta($post_id, 'geodir_latitude', true)) {
                update_post_meta($post_id, 'geodir_latitude', '31.7683');
                update_post_meta($post_id, 'geodir_longitude', '35.2137');
                update_post_meta($post_id, 'geodir_city', 'Jerusalem');
                update_post_meta($post_id, 'geodir_region', 'Jerusalem');
                update_post_meta($post_id, 'geodir_country', 'Israel');
            }

            update_post_meta($post_id, 'geodir_featured', '0');
        }
    }

    /**
     * סינכרון לטבלת הפרטים של GeoDirectory
     */
    private function sync_to_geodirectory_detail_table($post_id, $post_type)
    {
        global $wpdb;
        
        $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$detail_table'") == $detail_table;
        if (!$table_exists) {
            return false;
        }
        
        $columns_result = $wpdb->get_results("SHOW COLUMNS FROM $detail_table");
        $existing_columns = array();
        
        foreach ($columns_result as $column) {
            $existing_columns[] = $column->Field;
        }
        
        $all_meta = get_post_meta($post_id);
        
        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $detail_table WHERE post_id = %d",
            $post_id
        ));
        
        $update_data = array();
        
        // שדות בסיסיים
        $post = get_post($post_id);
        if ($post) {
            if (in_array('post_title', $existing_columns)) {
                $update_data['post_title'] = $post->post_title;
            }
            if (in_array('post_status', $existing_columns)) {
                $update_data['post_status'] = $post->post_status;
            }
        }
        
        // השדות המותאמים
        foreach ($all_meta as $meta_key => $meta_values) {
            if (strpos($meta_key, '_') === 0) {
                continue;
            }
            
            if (in_array($meta_key, $existing_columns) && !empty($meta_values[0])) {
                $value = $meta_values[0];
                $update_data[$meta_key] = $value;
            }
        }
        
        // נתונים גיאוגרפיים
        $geo_fields = array(
            'geodir_latitude' => 'latitude',
            'geodir_longitude' => 'longitude', 
            'geodir_city' => 'city',
            'geodir_region' => 'region',
            'geodir_country' => 'country',
            'geodir_featured' => 'featured'
        );
        
        foreach ($geo_fields as $meta_key => $table_field) {
            if (in_array($table_field, $existing_columns) && isset($all_meta[$meta_key]) && !empty($all_meta[$meta_key][0])) {
                $update_data[$table_field] = $all_meta[$meta_key][0];
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        if ($existing_row) {
            $result = $wpdb->update(
                $detail_table,
                $update_data,
                array('post_id' => $post_id)
            );
        } else {
            $update_data['post_id'] = $post_id;
            $result = $wpdb->insert($detail_table, $update_data);
        }
        
        return $result !== false;
    }

    /**
     * תיקון פוסט קיים
     */
    public function fix_existing_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $post_type = $post->post_type;
        return $this->sync_to_geodirectory_detail_table($post_id, $post_type);
    }

    /**
     * קבלת סטטיסטיקות עיבוד קבצים
     */
    public function get_file_processing_stats()
    {
        return $this->file_processor->get_processing_stats();
    }

    /**
     * הוספת לוג דיבוג
     */
    private function add_debug_log($message, $data = null)
    {
        $log_entry = array(
            'time' => date('H:i:s'),
            'message' => $message,
            'data' => $data
        );

        $this->debug_logs[] = $log_entry;

        $existing_logs = get_option('ltgdai_debug_logs', array());
        $existing_logs[] = $log_entry;

        if (count($existing_logs) > 50) {
            $existing_logs = array_slice($existing_logs, -50);
        }

        update_option('ltgdai_debug_logs', $existing_logs);
    }

    /**
     * סיום תוצאות
     */
    private function finalize_results($results)
    {
        $results['debug_logs'] = $this->debug_logs;
        $results['file_stats'] = $this->get_file_processing_stats();

        return $results;
    }

    /**
     * קבלת לוגי דיבוג
     */
    public function get_debug_logs()
    {
        return get_option('ltgdai_debug_logs', array());
    }

    /**
     * ניקוי לוגי דיבוג
     */
    public function clear_debug_logs()
    {
        return delete_option('ltgdai_debug_logs');
    }

    /**
     * סינכרון מלא לטבלת פרטים GeoDirectory
     */
    public function sync_to_geodirectory_detail_table_complete($post_id, $post_type)
    {
        global $wpdb;
        
        $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$detail_table'") == $detail_table;
        if (!$table_exists) {
            return false;
        }
        
        // קבל רשימת עמודות בטבלה
        $columns_result = $wpdb->get_results("SHOW COLUMNS FROM $detail_table");
        $existing_columns = array();
        $column_types = array();
        
        foreach ($columns_result as $column) {
            $existing_columns[] = $column->Field;
            $column_types[$column->Field] = $column->Type;
        }
        
        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $detail_table WHERE post_id = %d",
            $post_id
        ));
        
        $update_data = array();
        $data_types = array();
        
        // שדות בסיסיים של הפוסט
        $post = get_post($post_id);
        if ($post) {
            if (in_array('post_title', $existing_columns)) {
                $update_data['post_title'] = $post->post_title;
                $data_types[] = '%s';
            }
            if (in_array('post_status', $existing_columns)) {
                $update_data['post_status'] = $post->post_status;
                $data_types[] = '%s';
            }
            if (in_array('post_content', $existing_columns)) {
                $update_data['post_content'] = $post->post_content;
                $data_types[] = '%s';
            }
            if (in_array('post_excerpt', $existing_columns)) {
                $update_data['post_excerpt'] = $post->post_excerpt;
                $data_types[] = '%s';
            }
        }
        
        // כל המטא של הפוסט עם מיפוי חכם
        $all_meta = get_post_meta($post_id);
        
        foreach ($all_meta as $meta_key => $meta_values) {
            if (strpos($meta_key, '_') === 0 && $meta_key !== '_search_title') {
                continue;
            }
            
            if (!empty($meta_values[0]) || $meta_values[0] === '0') {
                $value = $meta_values[0];
                
                // מיפוי שדות מיוחדים
                $mapped_field = $this->map_meta_to_detail_field($meta_key);
                $target_field = $mapped_field ? $mapped_field : $meta_key;
                
                if (in_array($target_field, $existing_columns)) {
                    $processed_value = $this->process_value_by_column_type($value, $column_types[$target_field]);
                    
                    $update_data[$target_field] = $processed_value;
                    $data_types[] = $this->get_format_by_column_type($column_types[$target_field]);
                }
            }
        }
        
        // טיפול מיוחד בקטגוריות ותגיות
        $this->add_taxonomy_data_to_update_fixed($post_id, $post_type, $existing_columns, $update_data, $data_types);
        
        // נתונים גיאוגרפיים עם מיפוי נכון
        $this->add_geo_data_to_update_fixed($post_id, $existing_columns, $update_data, $data_types);
        
        // שדה חיפוש מיוחד
        if (in_array('_search_title', $existing_columns) && !empty($post->post_title)) {
            $update_data['_search_title'] = $post->post_title;
            $data_types[] = '%s';
        }
        
        // ברירות מחדל נדרשות
        $this->add_default_values($existing_columns, $update_data, $data_types);
        
        if (empty($update_data)) {
            return false;
        }
        
        if ($existing_row) {
            $result = $wpdb->update(
                $detail_table,
                $update_data,
                array('post_id' => $post_id),
                $data_types,
                array('%d')
            );
        } else {
            $update_data['post_id'] = $post_id;
            array_unshift($data_types, '%d');
            
            $result = $wpdb->insert($detail_table, $update_data, $data_types);
        }
        
        return $result !== false;
    }

    /**
     * מיפוי שדות מטא לשדות בטבלת הפרטים
     */
    private function map_meta_to_detail_field($meta_key)
    {
        $mappings = array(
            'geodir_latitude' => 'latitude',
            'geodir_longitude' => 'longitude',
            'geodir_city' => 'city',
            'geodir_region' => 'region',
            'geodir_country' => 'country',
            'geodir_featured' => 'featured',
            'geodir_street' => 'street',
            'address' => 'street',
        );
        
        return isset($mappings[$meta_key]) ? $mappings[$meta_key] : null;
    }

    /**
     * הוספת נתוני טקסונומיות לעדכון
     */
    private function add_taxonomy_data_to_update_fixed($post_id, $post_type, $existing_columns, &$update_data, &$data_types)
    {
        if (in_array('post_category', $existing_columns)) {
            $categories = $this->get_post_categories_for_detail_fixed($post_id, $post_type);
            if (!empty($categories)) {
                $update_data['post_category'] = $categories;
                $data_types[] = '%s';
            }
        }
        
        if (in_array('post_tags', $existing_columns)) {
            $tags = $this->get_post_tags_for_detail_fixed($post_id, $post_type);
            if (!empty($tags)) {
                $update_data['post_tags'] = $tags;
                $data_types[] = '%s';
            }
        }
    }

    /**
     * הוספת נתונים גיאוגרפיים לעדכון
     */
    private function add_geo_data_to_update_fixed($post_id, $existing_columns, &$update_data, &$data_types)
    {
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
                if (!empty($value) || $value === '0') {
                    $update_data[$table_field] = $value;
                    $data_types[] = ($table_field === 'featured') ? '%d' : '%s';
                }
            }
        }
    }

    /**
     * קבלת קטגוריות הפוסט עבור טבלת הפרטים
     */
    private function get_post_categories_for_detail_fixed($post_id, $post_type)
    {
        // בדוק קודם במטא
        $category_meta = get_post_meta($post_id, 'post_category', true);
        if (!empty($category_meta)) {
            return $category_meta;
        }
        
        // אחר כך בטקסונומיות
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
     * קבלת תגיות הפוסט עבור טבלת הפרטים
     */
    private function get_post_tags_for_detail_fixed($post_id, $post_type)
    {
        // בדוק קודם במטא
        $tags_meta = get_post_meta($post_id, 'post_tags', true);
        if (!empty($tags_meta)) {
            return $tags_meta;
        }
        
        // אחר כך בטקסונומיות
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
     * עיבוד ערך לפי סוג העמודה
     */
    private function process_value_by_column_type($value, $column_type)
    {
        $column_type = strtolower($column_type);
        
        if (strpos($column_type, 'date') !== false || strpos($column_type, 'datetime') !== false) {
            return $this->normalize_date_value_improved($value);
        }
        
        if (strpos($column_type, 'int') !== false || strpos($column_type, 'decimal') !== false || strpos($column_type, 'float') !== false) {
            return is_numeric($value) ? $value : 0;
        }
        
        return sanitize_text_field($value);
    }

    /**
     * נרמול ערכי תאריך
     */
    private function normalize_date_value_improved($date_value)
    {
        if (empty($date_value)) {
            return null;
        }
        
        // אם זה כבר בפורמט נכון
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
            return $date_value;
        }
        
        $formats = array(
            'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/y', 'j/n/Y', 'j.n.Y', 'j-n-Y'
        );
        
        foreach ($formats as $format) {
            $date_obj = DateTime::createFromFormat($format, trim($date_value));
            if ($date_obj !== false) {
                return $date_obj->format('Y-m-d');
            }
        }
        
        return $date_value;
    }

    /**
     * הוספת ברירות מחדל נדרשות
     */
    private function add_default_values($existing_columns, &$update_data, &$data_types)
    {
        $defaults = array(
            'featured' => '0',
            'overall_rating' => '0',
            'rating_count' => '0',
            'submit_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        );
        
        foreach ($defaults as $field => $default_value) {
            if (in_array($field, $existing_columns) && !isset($update_data[$field])) {
                $update_data[$field] = $default_value;
                $data_types[] = ($field === 'featured' || $field === 'overall_rating' || $field === 'rating_count') ? '%d' : '%s';
            }
        }
    }

    /**
     * קבלת פורמט לפי סוג העמודה
     */
    private function get_format_by_column_type($column_type)
    {
        $column_type = strtolower($column_type);
        
        if (strpos($column_type, 'int') !== false) {
            return '%d';
        }
        
        if (strpos($column_type, 'decimal') !== false || strpos($column_type, 'float') !== false) {
            return '%f';
        }
        
        return '%s';
    }
}