<?php
/**
 * מחלקה לעיבוד אוטומטי של קבצים עבור ListToGD
 * מזהה קישורי קבצים ומעבירה אותם לספריית המדיה
 */

if (!defined('ABSPATH')) {
    exit;
}

class FileProcessor {
    
    private $supported_extensions = array(
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'mp4', 'avi', 'mov', 'wmv', 'mp3', 'wav',
        'zip', 'rar', '7z', 'txt'
    );
    
    private $processed_files = array();
    
    /**
     * עיבוד ערך שדה - בדיקה אם זה קישור לקובץ והעברה אוטומטית
     */
    public function process_field_value($field_value, $post_id = 0) {
        $field_value = trim($field_value);
        
        if (empty($field_value)) {
            return $field_value;
        }
        
        error_log("FileProcessor: בודק ערך - $field_value");
        
        if ($this->is_file_url($field_value)) {
            error_log("FileProcessor: זוהה כקישור קובץ - מתחיל עיבוד");
            
            $result = $this->import_file_to_media_library_sync($field_value, $post_id);
            error_log("FileProcessor: תוצאה - מ: $field_value אל: $result");
            return $result;
        } else {
            error_log("FileProcessor: לא זוהה כקישור קובץ - $field_value");
        }
        
        return $field_value;
    }
    
    /**
     * בדיקה אם הערך הוא קישור לקובץ
     */
    private function is_file_url($value) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $parsed_url = parse_url($value);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if (in_array($extension, $this->supported_extensions)) {
            return true;
        }
        
        // בדיקה מיוחדת לקישורי ashx ודינמיים
        $file_patterns = array(
            '/\.ashx.*FileID/i',
            '/\.aspx.*file/i',
            '/download.*\.(pdf|doc|docx|xls|xlsx)/i',
            '/attachment.*\.(pdf|doc|docx|xls|xlsx)/i',
            '/GetPdfFile/i',
            '/GetFile/i',
            '/[?&]FileID=/i',
            '/[?&]file=/i',
        );
        
        foreach ($file_patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                error_log("FileProcessor: מצא קישור קובץ! URL: $value");
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * העברת קובץ לספריית המדיה של וורדפרס עם המתנה מלאה
     */
    private function import_file_to_media_library_sync($file_url, $post_id = 0) {
        error_log("FileProcessor: מתחיל הורדת קובץ מ-URL: $file_url");
        
        $file_hash = md5($file_url);
        
        if (isset($this->processed_files[$file_hash])) {
            error_log("FileProcessor: קובץ כבר קיים במטמון");
            return $this->processed_files[$file_hash];
        }
        
        $existing_attachment = $this->find_existing_attachment($file_url);
        if ($existing_attachment) {
            error_log("FileProcessor: קובץ כבר קיים במסד הנתונים");
            $this->processed_files[$file_hash] = $existing_attachment;
            return $existing_attachment;
        }
        
        try {
            if (!function_exists('media_handle_sideload')) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
            }
            
            error_log("FileProcessor: מוריד קובץ זמני...");
            
            $temp_file = $this->download_file_safely($file_url);
            
            if (is_wp_error($temp_file)) {
                error_log('FileProcessor Error: ' . $temp_file->get_error_message());
                return $file_url;
            }
            
            error_log("FileProcessor: קובץ זמני נוצר בהצלחה: $temp_file");
            
            if (!file_exists($temp_file) || filesize($temp_file) == 0) {
                error_log("FileProcessor: קובץ זמני לא תקין");
                @unlink($temp_file);
                return $file_url;
            }
            
            $file_array = array(
                'name' => $this->generate_safe_filename($file_url),
                'tmp_name' => $temp_file,
                'error' => 0,
                'size' => filesize($temp_file)
            );
            
            error_log("FileProcessor: מעלה לספריית המדיה עם שם: " . $file_array['name']);
            
            $attachment_id = media_handle_sideload($file_array, $post_id);
            
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            
            if (is_wp_error($attachment_id)) {
                error_log('FileProcessor Media Error: ' . $attachment_id->get_error_message());
                return $file_url;
            }
            
            error_log("FileProcessor: קובץ הועלה בהצלחה! ID: $attachment_id");
            
            $new_url = wp_get_attachment_url($attachment_id);
            
            if (!$new_url) {
                error_log("FileProcessor: שגיאה בקבלת URL חדש");
                return $file_url;
            }
            
            // וידוא שהקובץ באמת קיים
            $upload_dir = wp_upload_dir();
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $new_url);
            
            if (!file_exists($file_path)) {
                error_log("FileProcessor: קובץ לא נמצא לאחר העלאה: $file_path");
                return $file_url;
            }
            
            error_log("FileProcessor: URL חדש נוצר ואומת: $new_url");
            
            $this->save_file_mapping($file_url, $new_url, $attachment_id);
            $this->processed_files[$file_hash] = $new_url;
            $this->update_attachment_metadata($attachment_id, $file_url);
            
            return $new_url;
            
        } catch (Exception $e) {
            error_log('FileProcessor Exception: ' . $e->getMessage());
            return $file_url;
        }
    }
    
    /**
     * הורדה בטוחה של קובץ עם המתנה מלאה
     */
    private function download_file_safely($file_url) {
        $parsed_url = parse_url($file_url);
        if (!$parsed_url || !isset($parsed_url['scheme'])) {
            return new WP_Error('invalid_url', 'קישור לא תקין');
        }
        
        error_log("FileProcessor: מתחיל הורדה מ-URL: $file_url");
        
        $args = array(
            'timeout' => 120,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => array(
                'Accept' => 'application/pdf,application/octet-stream,*/*',
                'Cache-Control' => 'no-cache'
            )
        );
        
        $temp_file = download_url($file_url, 120, false, $args);
        
        if (is_wp_error($temp_file)) {
            error_log("FileProcessor: שגיאה בהורדה - " . $temp_file->get_error_message());
            return $temp_file;
        }
        
        error_log("FileProcessor: הורדה הצליחה, קובץ זמני: $temp_file");
        
        if (!file_exists($temp_file) || filesize($temp_file) == 0) {
            @unlink($temp_file);
            return new WP_Error('empty_file', 'הקובץ שהורד ריק');
        }
        
        $file_size = filesize($temp_file);
        error_log("FileProcessor: גודל קובץ: $file_size bytes");
        
        $max_size = 50 * 1024 * 1024; // 50MB מקסימום
        if ($file_size > $max_size) {
            @unlink($temp_file);
            return new WP_Error('file_too_large', 'הקובץ גדול מדי (מעל 50MB)');
        }
        
        return $temp_file;
    }
    
    /**
     * יצירת שם קובץ בטוח
     */
    private function generate_safe_filename($file_url) {
        $parsed_url = parse_url($file_url);
        
        if (preg_match('/FileID=([^&]+)/i', $file_url, $matches)) {
            $file_id = $matches[1];
            $file_id = preg_replace('/\.(pdf|doc|docx|xls|xlsx)$/i', '', $file_id);
            $safe_name = 'protocol_' . sanitize_file_name($file_id) . '.pdf';
            error_log("FileProcessor: שם קובץ נוצר מ-FileID: $safe_name");
            return $safe_name;
        }
        
        $original_name = basename($parsed_url['path']);
        $safe_name = sanitize_file_name($original_name);
        
        if (empty($safe_name) || $safe_name == '.' || strlen($safe_name) < 3) {
            $timestamp = date('Y-m-d_H-i-s');
            $safe_name = 'imported_file_' . $timestamp . '.pdf';
        }
        
        error_log("FileProcessor: שם קובץ סופי: $safe_name");
        return $safe_name;
    }
    
    /**
     * חיפוש קובץ קיים במסד הנתונים
     */
    private function find_existing_attachment($original_url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'ltgdai_original_url' 
             AND meta_value = %s 
             LIMIT 1",
            $original_url
        ));
        
        if ($attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                return $url;
            }
        }
        
        return false;
    }
    
    /**
     * שמירת מיפוי קובץ לשימוש עתידי
     */
    private function save_file_mapping($original_url, $new_url, $attachment_id) {
        update_post_meta($attachment_id, 'ltgdai_original_url', $original_url);
        update_post_meta($attachment_id, 'ltgdai_import_date', current_time('mysql'));
        
        $file_mappings = get_option('ltgdai_file_mappings', array());
        $file_mappings[md5($original_url)] = array(
            'original_url' => $original_url,
            'new_url' => $new_url,
            'attachment_id' => $attachment_id,
            'import_date' => current_time('mysql')
        );
        
        update_option('ltgdai_file_mappings', $file_mappings);
    }
    
    /**
     * עדכון מטאדאטה של הקובץ
     */
    private function update_attachment_metadata($attachment_id, $original_url) {
        $parsed_url = parse_url($original_url);
        $source_domain = isset($parsed_url['host']) ? $parsed_url['host'] : 'לא ידוע';
        
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_excerpt' => sprintf('קובץ שיובא מ-%s', $source_domain),
        ));
        
        update_post_meta($attachment_id, '_wp_attachment_image_alt', 'קובץ מיובא');
    }
    
    /**
     * קבלת סטטיסטיקות על קבצים מעובדים
     */
    public function get_processing_stats() {
        $file_mappings = get_option('ltgdai_file_mappings', array());
        
        return array(
            'total_files' => count($file_mappings),
            'processed_today' => $this->count_files_processed_today($file_mappings),
            'file_types' => $this->get_file_types_stats($file_mappings)
        );
    }
    
    /**
     * ספירת קבצים שעובדו היום
     */
    private function count_files_processed_today($file_mappings) {
        $today = date('Y-m-d');
        $count = 0;
        
        foreach ($file_mappings as $mapping) {
            if (isset($mapping['import_date']) && 
                strpos($mapping['import_date'], $today) === 0) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * קבלת סטטיסטיקות לפי סוגי קבצים
     */
    private function get_file_types_stats($file_mappings) {
        $stats = array();
        
        foreach ($file_mappings as $mapping) {
            $extension = pathinfo($mapping['original_url'], PATHINFO_EXTENSION);
            $extension = strtolower($extension);
            
            if (!isset($stats[$extension])) {
                $stats[$extension] = 0;
            }
            $stats[$extension]++;
        }
        
        return $stats;
    }
    
    /**
     * ניקוי קבצים ישנים
     */
    public function cleanup_old_files($days_old = 30) {
        $file_mappings = get_option('ltgdai_file_mappings', array());
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        $deleted_count = 0;
        
        foreach ($file_mappings as $hash => $mapping) {
            if (isset($mapping['import_date']) && 
                $mapping['import_date'] < $cutoff_date) {
                
                if (isset($mapping['attachment_id'])) {
                    wp_delete_attachment($mapping['attachment_id'], true);
                    $deleted_count++;
                }
                
                unset($file_mappings[$hash]);
            }
        }
        
        update_option('ltgdai_file_mappings', $file_mappings);
        
        return $deleted_count;
    }
}