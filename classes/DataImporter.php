<?php
/**
 * מחלקה לביצוע הזנה של נתונים ל-GeoDirectory
 * בהתבסס על הקוד המקורי שעובד במלואו
 */

// אבטחה – מניעת גישה ישירה לקובץ
if (!defined('ABSPATH')) {
    exit;
}

class DataImporter {
    
    private $mapping_handler;
    private $content_extractor;
    
    public function __construct() {
        require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
        require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
        
        $this->mapping_handler = new MappingHandler();
        $this->content_extractor = new ContentExtractor();
    }
    
    /**
     * ביצוע הזנה מלאה לסוג פוסט מסוים
     */
    public function import_post_type($post_type) {
        $results = array(
            'success' => false,
            'message' => '',
            'created_posts' => array(),
            'errors' => array(),
            'total_processed' => 0,
            'total_created' => 0,
            'total_failed' => 0
        );
        
        try {
            // קבלת נתונים בסיסיים
            $urls = get_option('ltgdai_saved_urls', array());
            $post_types = get_option('ltgdai_saved_post_types', array());
            $mappings = $this->mapping_handler->get_field_mappings($post_type);
            
            if (empty($mappings) || empty($mappings['field_mappings'])) {
                $results['message'] = 'לא נמצא מיפוי שדות לסוג הפוסט הזה';
                return $results;
            }
            
            // איסוף URLs הרלוונטיים לסוג הפוסט
            $relevant_urls = array();
            foreach ($urls as $index => $url) {
                if (isset($post_types[$index]) && $post_types[$index] === $post_type) {
                    $relevant_urls[] = $url;
                }
            }
            
            if (empty($relevant_urls)) {
                $results['message'] = 'לא נמצאו קישורים לסוג הפוסט הזה';
                return $results;
            }
            
            // עיבוד כל URL
            foreach ($relevant_urls as $url) {
                $url_results = $this->process_url_data($url, $post_type, $mappings);
                
                $results['total_processed'] += $url_results['processed'];
                $results['total_created'] += $url_results['created'];
                $results['total_failed'] += $url_results['failed'];
                
                $results['created_posts'] = array_merge($results['created_posts'], $url_results['posts']);
                $results['errors'] = array_merge($results['errors'], $url_results['errors']);
            }
            
            // הכנת הודעת סיום
            if ($results['total_created'] > 0) {
                $results['success'] = true;
                $results['message'] = sprintf(
                    'ההזנה הושלמה! נוצרו %d פוסטים מתוך %d שעובדו.',
                    $results['total_created'],
                    $results['total_processed']
                );
            } else {
                $results['message'] = 'לא נוצרו פוסטים חדשים';
            }
            
        } catch (Exception $e) {
            $results['message'] = 'שגיאה בהזנה: ' . $e->getMessage();
            error_log('DataImporter Error: ' . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * עיבוד נתונים מ-URL יחיד
     */
    private function process_url_data($url, $post_type, $mappings) {
        $results = array(
            'processed' => 0,
            'created' => 0,
            'failed' => 0,
            'posts' => array(),
            'errors' => array()
        );
        
        // קבלת הנתונים המחולצים
        $option_name = 'ltgdai_extracted_data_' . $post_type . '_' . md5($url);
        $extracted_data = get_option($option_name);
        
        if (empty($extracted_data) || !$extracted_data['success']) {
            $results['errors'][] = 'לא נמצאו נתונים מחולצים עבור: ' . $url;
            return $results;
        }
        
        // קבלת מיפוי השדות לURL הזה
        $url_key = md5($url);
        $field_mappings = isset($mappings['field_mappings'][$url_key]) ? 
                         $mappings['field_mappings'][$url_key] : array();
        
        if (empty($field_mappings)) {
            $results['errors'][] = 'לא נמצא מיפוי שדות עבור: ' . $url;
            return $results;
        }
        
        // עיבוד כל שורת נתונים
        $first_table = reset($extracted_data['data']);
        if (!empty($first_table['rows'])) {
            foreach ($first_table['rows'] as $row_index => $row_data) {
                $results['processed']++;
                
                try {
                    $post_id = $this->create_post_with_taxonomies($post_type, $row_data, $first_table['headers'], $field_mappings);
                    
                    if ($post_id && !is_wp_error($post_id)) {
                        $results['created']++;
                        $results['posts'][] = array(
                            'id' => $post_id,
                            'title' => get_the_title($post_id),
                            'url' => get_permalink($post_id)
                        );
                    } else {
                        $results['failed']++;
                        $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'שגיאה לא ידועה';
                        $results['errors'][] = "שורה {$row_index}: {$error_msg}";
                    }
                    
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "שורה {$row_index}: " . $e->getMessage();
                }
            }
        }
        
        return $results;
    }
    
    /**
     * יצירת פוסט עם טקסונומיות - בהתבסס על הקוד המקורי הפועל
     */
    private function create_post_with_taxonomies($post_type, $row_data, $headers, $field_mappings) {
        // יצירת מערך נתונים כמו בקוד המקורי
        $post_data = array_combine($headers, $row_data);
        
        if (empty($post_data)) {
            return false;
        }
        
        // מציאת כותרת ותוכן
        $post_title = '';
        $post_content = '';
        
        // מציאת כותרת
        foreach ($field_mappings as $gd_field => $source_field) {
            if ($gd_field === 'post_title' || stripos($source_field, 'title') !== false || stripos($source_field, 'כותרת') !== false) {
                $post_title = isset($post_data[$source_field]) ? $post_data[$source_field] : '';
                break;
            }
        }
        
        // אם לא נמצאה כותרת במיפוי, נסה להשתמש במפתח 'title' או הערך הראשון
        if (empty($post_title)) {
            if (isset($post_data['title'])) {
                $post_title = $post_data['title'];
            } elseif (isset($post_data['Title'])) {
                $post_title = $post_data['Title'];
            } else {
                $post_title = reset($post_data); // הערך הראשון
            }
        }
        
        // מציאת תוכן
        foreach ($field_mappings as $gd_field => $source_field) {
            if ($gd_field === 'post_content' || stripos($source_field, 'content') !== false || stripos($source_field, 'תוכן') !== false) {
                $post_content = isset($post_data[$source_field]) ? $post_data[$source_field] : '';
                break;
            }
        }
        
        // אם אין תוכן, נשתמש במפתח 'content'
        if (empty($post_content) && isset($post_data['content'])) {
            $post_content = $post_data['content'];
        }
        
        // יצירת הפוסט - בדיוק כמו בקוד המקורי
        $new_post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_title' => sanitize_text_field($post_title),
            'post_content' => wp_kses_post($post_content),
            'post_status' => 'publish',
        ]);
        
        if ($new_post_id && !is_wp_error($new_post_id)) {
            // הוספת כל השדות המותאמים וטקסונומיות - כמו בקוד המקורי
            foreach ($field_mappings as $gd_field => $source_field) {
                if (isset($post_data[$source_field]) && !empty($post_data[$source_field])) {
                    $field_value = $post_data[$source_field];
                    
                    // טיפול בטקסונומיות - בדיוק כמו בקוד המקורי
                    if (taxonomy_exists($gd_field)) {
                        wp_set_post_terms($new_post_id, sanitize_text_field($field_value), $gd_field);
                    } else {
                        // שמירה כמטא-דאטה - כמו בקוד המקורי
                        update_post_meta($new_post_id, $gd_field, sanitize_text_field($field_value));
                    }
                }
            }
            
            // טיפול בשדות נוספים שלא ממופים אבל קיימים בנתונים
            foreach ($post_data as $key => $value) {
                if (!empty($value) && !in_array($key, ['title', 'content', 'Title', 'Content'])) {
                    // אם זה לא שדה שכבר עובד עליו, שמור כמטא
                    $already_mapped = false;
                    foreach ($field_mappings as $gd_field => $source_field) {
                        if ($source_field === $key) {
                            $already_mapped = true;
                            break;
                        }
                    }
                    
                    if (!$already_mapped) {
                        // בדיקה אם זו טקסונומיה
                        if (taxonomy_exists($key)) {
                            wp_set_post_terms($new_post_id, sanitize_text_field($value), $key);
                        } else {
                            update_post_meta($new_post_id, $key, sanitize_text_field($value));
                        }
                    }
                }
            }
            
            // הוספת שדות ברירת מחדל של GeoDirectory אם נדרש
            $this->add_geodirectory_defaults($new_post_id, $post_type);
        }
        
        return $new_post_id;
    }
    
    /**
     * הוספת שדות ברירת מחדל של GeoDirectory
     */
    private function add_geodirectory_defaults($post_id, $post_type) {
        // שדות חובה לGeoDirectory
        if (substr($post_type, 0, 3) === 'gd_') {
            // מיקום ברירת מחדל אם לא קיים
            if (!get_post_meta($post_id, 'geodir_latitude', true)) {
                update_post_meta($post_id, 'geodir_latitude', '31.7683');
                update_post_meta($post_id, 'geodir_longitude', '35.2137');
                update_post_meta($post_id, 'geodir_street', '');
                update_post_meta($post_id, 'geodir_city', 'ירושלים');
                update_post_meta($post_id, 'geodir_region', 'ירושלים');
                update_post_meta($post_id, 'geodir_country', 'ישראל');
                update_post_meta($post_id, 'geodir_zip', '');
            }
            
            // שדות מערכת של GeoDirectory
            update_post_meta($post_id, 'geodir_featured', '0');
            update_post_meta($post_id, 'geodir_post_package_id', '1');
            update_post_meta($post_id, 'geodir_expire_date', '0000-00-00');
            update_post_meta($post_id, 'geodir_contact', '');
            update_post_meta($post_id, 'geodir_email', '');
            update_post_meta($post_id, 'geodir_website', '');
            update_post_meta($post_id, 'geodir_phone', '');
            update_post_meta($post_id, 'geodir_twitter', '');
            update_post_meta($post_id, 'geodir_facebook', '');
        }
    }
    
    /**
     * דיבוג המיפוי
     */
    public function debug_mapping($post_type) {
        $mappings = $this->mapping_handler->get_field_mappings($post_type);
        error_log('Debug Mapping for ' . $post_type . ': ' . print_r($mappings, true));
        return $mappings;
    }
}