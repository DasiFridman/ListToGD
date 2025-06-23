<?php
/**
 * מחלקה דינמית לטיפול בכל סוגי השדות של GeoDirectory
 * מעבדת ומאמתת שדות בצורה חכמה לפי הסוג
 */

if (!defined('ABSPATH')) {
    exit;
}

class DynamicFieldHandler
{
    private $field_types_cache = array();
    private $taxonomy_cache = array();
    
    /**
     * שמירת ערך שדה באופן דינמי עם עיבוד לפי סוג
     */
    public function save_field_value_dynamic($post_id, $field_key, $field_value, $post_type = null)
    {
        $clean_value = $this->clean_field_value($field_value, $field_key);
        
        if (empty($clean_value) && $clean_value !== '0' && !$this->allow_empty_field($field_key)) {
            return false;
        }
        
        if (!$post_type) {
            $post = get_post($post_id);
            $post_type = $post ? $post->post_type : '';
        }
        
        $field_info = $this->get_field_info($field_key, $post_type);
        
        // שמירה במטא
        $meta_saved = update_post_meta($post_id, $field_key, $clean_value);
        
        // שמירה לפי סוג השדה
        $type_saved = $this->save_by_field_type($post_id, $field_key, $clean_value, $field_info, $post_type);
        
        // שמירה לטבלת פרטים
        $detail_saved = $this->save_to_detail_table_smart($post_id, $field_key, $clean_value, $post_type);
        
        return $meta_saved || $type_saved || $detail_saved;
    }
    
    /**
     * בדיקה אם שדה יכול להיות ריק
     */
    private function allow_empty_field($field_key)
    {
        $allowed_empty = array('post_tags', 'post_excerpt', 'address', 'street', 'street2', 'zip');
        return in_array($field_key, $allowed_empty);
    }
    
    /**
     * שמירה חכמה לטבלת פרטים
     */
    private function save_to_detail_table_smart($post_id, $field_key, $field_value, $post_type)
    {
        global $wpdb;
        
        $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$detail_table'") != $detail_table) {
            return false;
        }
        
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $detail_table");
        $field_mapping = $this->get_field_mapping_for_detail_table($field_key);
        $target_field = $field_mapping ? $field_mapping : $field_key;
        
        if (!in_array($target_field, $columns)) {
            return false;
        }
        
        $existing_row = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $detail_table WHERE post_id = %d",
            $post_id
        ));
        
        if ($existing_row) {
            $result = $wpdb->update(
                $detail_table,
                array($target_field => $field_value),
                array('post_id' => $post_id),
                array('%s'),
                array('%d')
            );
        } else {
            $result = $wpdb->insert(
                $detail_table,
                array('post_id' => $post_id, $target_field => $field_value),
                array('%d', '%s')
            );
        }
        
        return $result !== false;
    }
    
    /**
     * מיפוי שדות לטבלת פרטים
     */
    private function get_field_mapping_for_detail_table($field_key)
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
        
        return isset($mappings[$field_key]) ? $mappings[$field_key] : null;
    }
    
    /**
     * שמירה לפי סוג שדה
     */
    private function save_by_field_type($post_id, $field_key, $clean_value, $field_info, $post_type)
    {
        switch ($field_info['type']) {
            case 'datepicker':
            case 'date':
                return $this->save_date_field($post_id, $field_key, $clean_value);
                
            case 'select':
            case 'multiselect':
                return $this->save_select_field($post_id, $field_key, $clean_value, $field_info);
                
            case 'categories':
            case 'category':
                return $this->save_category_field($post_id, $field_key, $clean_value, $post_type);
                
            case 'tags':
                return $this->save_tags_field($post_id, $field_key, $clean_value, $post_type);
                
            case 'url':
                return $this->save_url_field($post_id, $field_key, $clean_value);
                
            case 'email':
                return $this->save_email_field($post_id, $field_key, $clean_value);
                
            case 'phone':
                return $this->save_phone_field($post_id, $field_key, $clean_value);
                
            case 'address':
                return $this->save_address_field($post_id, $field_key, $clean_value);
                
            case 'textarea':
                return $this->save_textarea_field($post_id, $field_key, $clean_value);
                
            case 'number':
            case 'float':
                return $this->save_number_field($post_id, $field_key, $clean_value);
                
            case 'checkbox':
                return $this->save_checkbox_field($post_id, $field_key, $clean_value);
                
            case 'text':
            default:
                return $this->save_text_field($post_id, $field_key, $clean_value);
        }
    }
    
    /**
     * שמירת שדה בחירה עם התאמת אפשרויות
     */
    private function save_select_field($post_id, $field_key, $value, $field_info)
    {
        // טיפול מיוחד בשדות שנה
        if (strpos($field_key, 'year') !== false || strpos($field_key, 'שנה') !== false) {
            $processed_value = $this->process_year_value($value);
            update_post_meta($post_id, $field_key, $processed_value);
            return true;
        }
        
        // התאמה לאפשרויות השדה
        if (!empty($field_info['options'])) {
            $matched_value = $this->find_matching_option_value($value, $field_info['options']);
            if ($matched_value !== null) {
                update_post_meta($post_id, $field_key, $matched_value);
                return true;
            }
        }
        
        // טיפול ב-multiselect
        if ($field_info['type'] === 'multiselect') {
            $processed_value = $this->process_multiselect_value($value, $field_info['options']);
            update_post_meta($post_id, $field_key, $processed_value);
            return true;
        }
        
        update_post_meta($post_id, $field_key, $value);
        return true;
    }
    
    /**
     * חיפוש ערך מתאים באפשרויות
     */
    private function find_matching_option_value($input_value, $options)
    {
        if (empty($options) || empty($input_value)) {
            return null;
        }
        
        $input_clean = trim(strtolower($input_value));
        
        // חיפוש מדויק
        foreach ($options as $option_value => $option_label) {
            if (strtolower(trim($option_value)) === $input_clean) {
                return $option_value;
            }
            if (strtolower(trim($option_label)) === $input_clean) {
                return $option_value;
            }
        }
        
        // חיפוש חלקי
        foreach ($options as $option_value => $option_label) {
            if (strpos(strtolower(trim($option_label)), $input_clean) !== false) {
                return $option_value;
            }
            if (strpos(strtolower(trim($option_value)), $input_clean) !== false) {
                return $option_value;
            }
        }
        
        return $input_value;
    }
    
    /**
     * עיבוד ערך multiselect
     */
    private function process_multiselect_value($value, $options)
    {
        if (empty($value)) {
            return array();
        }
        
        if (is_array($value)) {
            $result = array();
            foreach ($value as $single_value) {
                $matched = $this->find_matching_option_value($single_value, $options);
                if ($matched !== null) {
                    $result[] = $matched;
                }
            }
            return $result;
        }
        
        $values_array = preg_split('/[,;\s]+/', $value);
        $result = array();
        
        foreach ($values_array as $single_value) {
            $single_value = trim($single_value);
            if (!empty($single_value)) {
                $matched = $this->find_matching_option_value($single_value, $options);
                if ($matched !== null) {
                    $result[] = $matched;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * עיבוד ערך שנה
     */
    private function process_year_value($value)
    {
        if (is_numeric($value)) {
            if (strlen($value) == 4) {
                return $value;
            } else if (strlen($value) == 2) {
                return '20' . $value;
            }
        }
        
        if (preg_match('/(\d{4})/', $value, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/(\d{2})/', $value, $matches)) {
            return '20' . $matches[1];
        }
        
        return $value;
    }
    
    /**
     * שמירת שדה checkbox
     */
    private function save_checkbox_field($post_id, $field_key, $value)
    {
        $checkbox_value = in_array(strtolower($value), array('1', 'true', 'yes', 'כן', 'on')) ? '1' : '0';
        update_post_meta($post_id, $field_key, $checkbox_value);
        return true;
    }
    
    /**
     * ניקוי ערך השדה
     */
    private function clean_field_value($value, $field_key = '')
    {
        $value = trim($value);
        
        if (empty($value)) {
            return '';
        }
        
        $prefixes_to_remove = array(
            'קטגוריה:', 'קטגוריה :', 'תאריך:', 'תאריך :', 'סוג:', 'סוג :',
            'מספר:', 'מספר :', 'שם הפרוטוקול:', 'שם הפרוטוקול :', 'כתובת:', 'כתובת :',
            'שנה:', 'שנה :', 'תיאור:', 'תיאור :', 'address:', 'year:', 'category:',
            'date:', 'type:', 'number:', 'description:', 'title:', 'name:'
        );
        
        foreach ($prefixes_to_remove as $prefix) {
            if (stripos($value, $prefix) === 0) {
                $value = trim(substr($value, strlen($prefix)));
                break;
            }
        }
        
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);
        
        if ($value === ':' || $value === '') {
            return '';
        }
        
        return $value;
    }
    
    /**
     * קבלת מידע השדה מ-GeoDirectory
     */
    private function get_field_info($field_key, $post_type)
    {
        $cache_key = $post_type . '_' . $field_key;
        if (isset($this->field_types_cache[$cache_key])) {
            return $this->field_types_cache[$cache_key];
        }
        
        $field_info = array(
            'type' => 'text',
            'required' => false,
            'options' => array(),
            'validation' => array()
        );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'geodir_custom_fields';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            $field_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE htmlvar_name = %s AND post_type = %s AND is_active = 1",
                $field_key,
                $post_type
            ));
            
            if ($field_data) {
                $field_info['type'] = $field_data->field_type;
                $field_info['required'] = !empty($field_data->is_required);
                
                if (!empty($field_data->option_values)) {
                    $field_info['options'] = $this->parse_field_options($field_data->option_values);
                }
                
                $field_info['validation'] = $this->parse_field_validation($field_data);
            }
        }
        
        if ($field_info['type'] === 'text') {
            $field_info['type'] = $this->guess_field_type_by_name($field_key);
        }
        
        $this->field_types_cache[$cache_key] = $field_info;
        return $field_info;
    }
    
    /**
     * ניחוש סוג שדה לפי השם
     */
    private function guess_field_type_by_name($field_key)
    {
        $field_key_lower = strtolower($field_key);
        
        if (preg_match('/date|תאריך|_dat/', $field_key_lower)) {
            return 'datepicker';
        }
        
        if (preg_match('/category|קטגוריה|post_category/', $field_key_lower)) {
            return 'categories';
        }
        
        if (preg_match('/tag|תג|post_tags/', $field_key_lower)) {
            return 'tags';
        }
        
        if (preg_match('/url|link|file|קישור|קובץ/', $field_key_lower)) {
            return 'url';
        }
        
        if (preg_match('/address|כתובת|street|רחוב/', $field_key_lower)) {
            return 'address';
        }
        
        if (preg_match('/year|שנה/', $field_key_lower)) {
            return 'select';
        }
        
        if (preg_match('/phone|טלפון|fax|פקס/', $field_key_lower)) {
            return 'phone';
        }
        
        if (preg_match('/email|mail|דוא/', $field_key_lower)) {
            return 'email';
        }
        
        if (preg_match('/check|enabled|disabled|הארכה/', $field_key_lower)) {
            return 'checkbox';
        }
        
        return 'text';
    }
    
    /**
     * פרסור אפשרויות שדה
     */
    private function parse_field_options($options_string)
    {
        if (empty($options_string)) {
            return array();
        }
        
        $options = array();
        
        if (strpos($options_string, '|') !== false) {
            $items = explode(',', $options_string);
            foreach ($items as $item) {
                $item = trim($item);
                if (strpos($item, '|') !== false) {
                    list($value, $label) = explode('|', $item, 2);
                    $options[trim($value)] = trim($label);
                } else {
                    $options[$item] = $item;
                }
            }
        } else {
            $items = explode(',', $options_string);
            foreach ($items as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $options[$item] = $item;
                }
            }
        }
        
        return $options;
    }
    
    /**
     * שמירת שדה כתובת
     */
    private function save_address_field($post_id, $field_key, $value)
    {
        update_post_meta($post_id, $field_key, sanitize_text_field($value));
        
        if ($field_key === 'address') {
            update_post_meta($post_id, 'geodir_street', $value);
        }
        
        return true;
    }
    
    /**
     * שמירת שדה תאריך
     */
    private function save_date_field($post_id, $field_key, $value)
    {
        $processed_date = $this->process_date_value($value);
        update_post_meta($post_id, $field_key, $processed_date);
        
        if ($processed_date !== $value) {
            update_post_meta($post_id, $field_key . '_original', $value);
        }
        
        return true;
    }
    
    /**
     * עיבוד ערך תאריך
     */
    private function process_date_value($date_value)
    {
        if (empty($date_value)) {
            return null;
        }
        
        $formats = array(
            'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/y', 'j/n/Y', 'j.n.Y'
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
     * שמירת שדה קטגוריה
     */
    private function save_category_field($post_id, $field_key, $category_name, $post_type) 
    {
        $taxonomy = $this->find_category_taxonomy($post_type, $field_key);
        
        if (!$taxonomy) {
            return update_post_meta($post_id, $field_key, $category_name);
        }
        
        $term = get_term_by('name', $category_name, $taxonomy);
        
        if (!$term) {
            $term_data = wp_insert_term($category_name, $taxonomy);
            if (is_wp_error($term_data)) {
                return update_post_meta($post_id, $field_key, $category_name);
            }
            $term_id = $term_data['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        
        $result = wp_set_post_terms($post_id, array($term_id), $taxonomy);
        
        if (is_wp_error($result)) {
            return false;
        }
        
        update_post_meta($post_id, $field_key, $category_name);
        return true;
    }
    
    /**
     * שמירת שדה תגיות
     */
    private function save_tags_field($post_id, $field_key, $tags_value, $post_type) 
    {
        if (empty($tags_value) || trim($tags_value) === ':') {
            return true;
        }
        
        $taxonomy = $this->find_tags_taxonomy($post_type, $field_key);
        
        if (!$taxonomy) {
            return update_post_meta($post_id, $field_key, $tags_value);
        }
        
        $tags_array = preg_split('/[,;\s]+/', $tags_value);
        $tags_array = array_filter(array_map('trim', $tags_array));
        
        if (empty($tags_array)) {
            return true;
        }
        
        $result = wp_set_post_terms($post_id, $tags_array, $taxonomy);
        
        if (is_wp_error($result)) {
            return false;
        }
        
        update_post_meta($post_id, $field_key, $tags_value);
        return true;
    }
    
    /**
     * שמירת שדה URL
     */
    private function save_url_field($post_id, $field_key, $value) 
    {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            if (!preg_match('/^https?:\/\//', $value)) {
                $value = 'http://' . $value;
            }
        }
        
        update_post_meta($post_id, $field_key, esc_url_raw($value));
        return true;
    }
    
    /**
     * שמירת שדה אימייל
     */
    private function save_email_field($post_id, $field_key, $value) 
    {
        if (!empty($value) && !is_email($value)) {
            return update_post_meta($post_id, $field_key, $value);
        }
        return update_post_meta($post_id, $field_key, sanitize_email($value));
    }
    
    /**
     * שמירת שדה טלפון
     */
    private function save_phone_field($post_id, $field_key, $value) 
    {
        $phone = preg_replace('/[^\d\-\+\(\)\s]/', '', $value);
        return update_post_meta($post_id, $field_key, $phone);
    }
    
    /**
     * שמירת שדה טקסט ארוך
     */
    private function save_textarea_field($post_id, $field_key, $value) 
    {
        return update_post_meta($post_id, $field_key, sanitize_textarea_field($value));
    }
    
    /**
     * שמירת שדה מספר
     */
    private function save_number_field($post_id, $field_key, $value) 
    {
        $number = preg_replace('/[^\d\.\-]/', '', $value);
        if (is_numeric($number)) {
            return update_post_meta($post_id, $field_key, $number);
        }
        return update_post_meta($post_id, $field_key, $value);
    }
    
    /**
     * שמירת שדה טקסט רגיל
     */
    private function save_text_field($post_id, $field_key, $value) 
    {
        update_post_meta($post_id, $field_key, sanitize_text_field($value));
        return true;
    }
    
    /**
     * חיפוש טקסונומיית קטגוריות
     */
    private function find_category_taxonomy($post_type, $field_key = null) 
    {
        $cache_key = $post_type . '_category';
        if (isset($this->taxonomy_cache[$cache_key])) {
            return $this->taxonomy_cache[$cache_key];
        }
        
        $possible_taxonomies = array();
        
        if ($field_key && $field_key !== 'post_category') {
            $possible_taxonomies[] = $field_key;
        }
        
        $possible_taxonomies = array_merge($possible_taxonomies, array(
            $post_type . 'category',
            'gd_' . str_replace('gd_', '', $post_type) . 'category',
            $post_type . '_category',
            'category'
        ));
        
        foreach ($possible_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $this->taxonomy_cache[$cache_key] = $taxonomy;
                return $taxonomy;
            }
        }
        
        $this->taxonomy_cache[$cache_key] = false;
        return false;
    }
    
    /**
     * חיפוש טקסונומיית תגיות
     */
    private function find_tags_taxonomy($post_type, $field_key = null) 
    {
        $cache_key = $post_type . '_tags';
        if (isset($this->taxonomy_cache[$cache_key])) {
            return $this->taxonomy_cache[$cache_key];
        }
        
        $possible_taxonomies = array();
        
        if ($field_key && $field_key !== 'post_tags') {
            $possible_taxonomies[] = $field_key;
        }
        
        $possible_taxonomies = array_merge($possible_taxonomies, array(
            $post_type . '_tags',
            'gd_' . str_replace('gd_', '', $post_type) . '_tags',
            $post_type . 'tags',
            'post_tag'
        ));
        
        foreach ($possible_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $this->taxonomy_cache[$cache_key] = $taxonomy;
                return $taxonomy;
            }
        }
        
        $this->taxonomy_cache[$cache_key] = false;
        return false;
    }
    
    /**
     * פרסור אימות שדה
     */
    private function parse_field_validation($field_data) 
    {
        $validation = array();
        
        if (!empty($field_data->is_required)) {
            $validation['required'] = true;
        }
        
        if (!empty($field_data->validation_pattern)) {
            $validation['pattern'] = $field_data->validation_pattern;
        }
        
        return $validation;
    }
    
    /**
     * עיבוד שדות מותאמים
     */
    public function process_custom_fields($post_id, $custom_fields, $post_type)
    {
        if (empty($custom_fields) || !is_array($custom_fields)) {
            return true;
        }
        
        foreach ($custom_fields as $custom_field) {
            if (!empty($custom_field['name']) && !empty($custom_field['gd_field'])) {
                $this->save_field_value_dynamic(
                    $post_id, 
                    $custom_field['gd_field'], 
                    $custom_field['name'], 
                    $post_type
                );
            }
        }
        
        return true;
    }
    
    /**
     * בדיקה האם פוסט טייפ הוא של GeoDirectory
     */
    public function is_geodirectory_post_type($post_type)
    {
        if (substr($post_type, 0, 3) === 'gd_') {
            return true;
        }
        
        if (function_exists('geodir_is_gd_post_type')) {
            return geodir_is_gd_post_type($post_type);
        }
        
        return false;
    }
    
    /**
     * סינכרון כל שדות הפוסט לטבלת פרטים
     */
    public function sync_all_post_fields($post_id, $post_type = null)
    {
        if (!$post_type) {
            $post = get_post($post_id);
            $post_type = $post ? $post->post_type : '';
        }
        
        $all_meta = get_post_meta($post_id);
        $synced_count = 0;
        
        foreach ($all_meta as $meta_key => $meta_values) {
            if (strpos($meta_key, '_') === 0 && !in_array($meta_key, array('_search_title'))) {
                continue;
            }
            
            $meta_value = $meta_values[0];
            
            if (!empty($meta_value) || $this->allow_empty_field($meta_key)) {
                $this->save_to_detail_table_smart($post_id, $meta_key, $meta_value, $post_type);
                $synced_count++;
            }
        }
        
        return $synced_count;
    }
}