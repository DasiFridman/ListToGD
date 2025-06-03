<?php
/**
 * מחלקה לחילוץ תוכן מדפי אינטרנט
 * מיועדת לחילוץ טבלאות ונתונים מובנים מדפי HTML
 */

// אבטחה – מניעת גישה ישירה לקובץ
if (!defined('ABSPATH')) {
    exit;
}

class ContentExtractor {
    /**
     * חילוץ נתונים מכתובת URL
     * 
     * @param string $url כתובת ה-URL לחילוץ
     * @return array מערך עם נתוני התוכן המחולץ או שגיאה
     */
    public function extract_from_url($url) {
        // בדיקת תקינות ה-URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return array(
                'success' => false,
                'message' => 'כתובת URL לא תקינה',
                'data' => null,
                'source_url' => $url
            );
        }
        
        // שימוש ב-WP HTTP API לביצוע הבקשה
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false // במקרה של בעיות SSL
        ));
        
        // בדיקת שגיאות
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'שגיאה בקריאת הדף: ' . $response->get_error_message(),
                'data' => null,
                'source_url' => $url
            );
        }
        
        // קבלת הקוד של הדף
        $html_content = wp_remote_retrieve_body($response);
        
        // חילוץ הנתונים המובנים
        return $this->parse_html_content($html_content, $url);
    }
    
    /**
     * ניתוח תוכן HTML וחילוץ טבלאות ונתונים
     * 
     * @param string $html תוכן ה-HTML של הדף
     * @param string $url כתובת ה-URL המקורית (לצורך תיעוד)
     * @return array מערך עם נתוני התוכן המחולץ
     */
    public function parse_html_content($html, $url) {
        // בדיקה אם התוכן ריק
        if (empty($html)) {
            return array(
                'success' => false,
                'message' => 'לא נמצא תוכן בדף',
                'data' => null,
                'source_url' => $url
            );
        }
        
        // טעינת הספריה DOM של PHP לניתוח HTML
        $dom = new DOMDocument();
        
        // מניעת שגיאות פרסור (נפוץ ב-HTML לא תקין)
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        // חיפוש טבלאות בדף
        $tables = $dom->getElementsByTagName('table');
        
        // אם אין טבלאות, נחזיר הודעה מתאימה
        if ($tables->length === 0) {
            return array(
                'success' => false,
                'message' => 'לא נמצאו טבלאות בדף',
                'data' => null,
                'html_content' => $html, // מחזיר את התוכן המלא במקרה שנרצה לנתח ידנית
                'source_url' => $url
            );
        }
        
        // מערך לשמירת כל הטבלאות שנמצאו
        $extracted_tables = array();
        
        // עיבוד כל טבלה
        foreach ($tables as $table_index => $table) {
            $table_data = $this->extract_table_data($table);
            
            // אם הטבלה הזו ריקה או אין בה מספיק שורות/עמודות, נדלג עליה
            if (empty($table_data['headers']) || count($table_data['rows']) < 1) {
                continue;
            }
            
            $extracted_tables[] = $table_data;
        }
        
        // בדיקה אם מצאנו טבלאות עם נתונים
        if (empty($extracted_tables)) {
            // נסה לחלץ נתונים ממשתני ul/li אם לא נמצאו טבלאות
            $extracted_lists = $this->extract_lists_data($dom);
            
            if (!empty($extracted_lists)) {
                return array(
                    'success' => true,
                    'message' => 'נמצאו רשימות עם נתונים',
                    'data' => $extracted_lists,
                    'source_url' => $url
                );
            }
            
            return array(
                'success' => false,
                'message' => 'לא ניתן לחלץ נתונים מובנים מהדף',
                'data' => null,
                'html_content' => $html,
                'source_url' => $url
            );
        }
        
        return array(
            'success' => true,
            'message' => 'נמצאו ' . count($extracted_tables) . ' טבלאות עם נתונים',
            'data' => $extracted_tables,
            'source_url' => $url
        );
    }
    
    /**
     * חילוץ נתונים מטבלה
     * 
     * @param DOMElement $table אלמנט הטבלה
     * @return array מערך עם כותרות ושורות הטבלה
     */
    public function extract_table_data($table) {
        $headers = array();
        $rows = array();
        
        // חיפוש כותרות בטבלה (th)
        $th_elements = $table->getElementsByTagName('th');
        
        // אם יש אלמנטי th, נשתמש בהם ככותרות
        if ($th_elements->length > 0) {
            foreach ($th_elements as $th) {
                $headers[] = $this->clean_text($th->textContent);
            }
        } else {
            // אחרת, ננסה למצוא שורה ראשונה כמקור לכותרות
            $tr_elements = $table->getElementsByTagName('tr');
            
            if ($tr_elements->length > 0) {
                $first_row = $tr_elements->item(0);
                $td_elements = $first_row->getElementsByTagName('td');
                
                if ($td_elements->length > 0) {
                    foreach ($td_elements as $td) {
                        $headers[] = $this->clean_text($td->textContent);
                    }
                    
                    // נסיר את השורה הראשונה מהטבלה כי השתמשנו בה ככותרות
                    $start_index = 1;
                } else {
                    // אם אין תאים בשורה הראשונה, נייצר כותרות גנריות
                    $start_index = 0;
                }
            } else {
                // אם אין שורות בכלל, נחזיר מערך ריק
                return array('headers' => array(), 'rows' => array());
            }
        }
        
        // אם עדיין אין לנו כותרות, נייצר כותרות גנריות
        if (empty($headers)) {
            // נבדוק את מספר העמודות בשורה הראשונה
            $tr_elements = $table->getElementsByTagName('tr');
            if ($tr_elements->length > 0) {
                $first_row = $tr_elements->item(0);
                $td_elements = $first_row->getElementsByTagName('td');
                
                for ($i = 0; $i < $td_elements->length; $i++) {
                    $headers[] = 'עמודה ' . ($i + 1);
                }
            }
            $start_index = 0;
        } else {
            $start_index = 1; // נתחיל מהשורה השנייה כי הראשונה היא כותרות
        }
        
        // עכשיו נחלץ את שורות הנתונים
        $tr_elements = $table->getElementsByTagName('tr');
        
        for ($i = $start_index; $i < $tr_elements->length; $i++) {
            $row = array();
            $td_elements = $tr_elements->item($i)->getElementsByTagName('td');
            
            // אם יש מספר שונה של תאים מהכותרות, נמלא ערכים ריקים
            for ($j = 0; $j < count($headers); $j++) {
                if ($j < $td_elements->length) {
                    $row[] = $this->clean_text($td_elements->item($j)->textContent);
                } else {
                    $row[] = '';
                }
            }
            
            // נוסיף את השורה למערך רק אם היא לא ריקה לחלוטין
            if (!$this->is_empty_row($row)) {
                $rows[] = $row;
            }
        }
        
        return array(
            'headers' => $headers,
            'rows' => $rows
        );
    }
    
    /**
     * חילוץ נתונים מרשימות (ul/li)
     * 
     * @param DOMDocument $dom אובייקט ה-DOM של הדף
     * @return array מערך של נתונים מחולצים מרשימות
     */
    private function extract_lists_data($dom) {
        $lists = array();
        
        // חיפוש רשימות
        $ul_elements = $dom->getElementsByTagName('ul');
        
        if ($ul_elements->length === 0) {
            return array();
        }
        
        foreach ($ul_elements as $ul_index => $ul) {
            $li_elements = $ul->getElementsByTagName('li');
            
            // נדלג על רשימות קטנות מדי
            if ($li_elements->length < 3) {
                continue;
            }
            
            $list_items = array();
            $pattern = null;
            $valid_pattern = true;
            
            // בדיקה אם יש תבנית קבועה ברשימה (לדוגמה: שם: ערך)
            foreach ($li_elements as $li) {
                $content = $this->clean_text($li->textContent);
                
                // נסיון לזהות תבנית של "שם: ערך"
                if (preg_match('/^(.+?)[:\-]\s*(.+)$/', $content, $matches)) {
                    $name = trim($matches[1]);
                    $value = trim($matches[2]);
                    
                    $list_items[] = array(
                        'name' => $name,
                        'value' => $value
                    );
                } else {
                    $valid_pattern = false;
                    break;
                }
            }
            
            // אם לא זיהינו תבנית קבועה, נשמור כרשימה רגילה
            if (!$valid_pattern) {
                $list_items = array();
                
                foreach ($li_elements as $li) {
                    $list_items[] = $this->clean_text($li->textContent);
                }
            }
            
            // אם מצאנו פריטים, נוסיף לרשימת התוצאות
            if (!empty($list_items)) {
                $headers = $valid_pattern ? array('שם', 'ערך') : array('פריט');
                $rows = array();
                
                if ($valid_pattern) {
                    foreach ($list_items as $item) {
                        $rows[] = array($item['name'], $item['value']);
                    }
                } else {
                    foreach ($list_items as $item) {
                        $rows[] = array($item);
                    }
                }
                
                $lists[] = array(
                    'headers' => $headers,
                    'rows' => $rows,
                    'type' => 'list'
                );
            }
        }
        
        return $lists;
    }
    
    /**
     * ניקוי טקסט מרווחים מיותרים
     * 
     * @param string $text הטקסט לניקוי
     * @return string הטקסט המנוקה
     */
    private function clean_text($text) {
        // הסרת רווחים עודפים וירידות שורה
        $clean = trim(preg_replace('/\s+/', ' ', $text));
        return $clean;
    }
    
    /**
     * בדיקה אם שורה ריקה לחלוטין
     * 
     * @param array $row השורה לבדיקה
     * @return bool האם השורה ריקה
     */
    private function is_empty_row($row) {
        foreach ($row as $cell) {
            if (!empty(trim($cell))) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * שמירת נתונים שנחלצו לאפשרויות של וורדפרס
     * 
     * @param array $data הנתונים לשמירה
     * @param string $post_type סוג הפוסט של GeoDirectory
     * @param string $url כתובת ה-URL המקורית
     * @return bool האם השמירה הצליחה
     */
    public function save_extracted_data($data, $post_type, $url) {
        if (empty($data) || !isset($data['data']) || !$data['success']) {
            return false;
        }
        
        // שמירת הנתונים באפשרויות וורדפרס
        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        update_option($option_name, $data);
        
        return true;
    }
    
    /**
     * קבלת נתונים שנשמרו לסוג פוסט ו-URL מסוימים
     * 
     * @param string $post_type סוג הפוסט של GeoDirectory
     * @param string $url כתובת ה-URL המקורית
     * @return array|null הנתונים שנשמרו או null אם אין נתונים
     */
    public function get_saved_data($post_type, $url) {
        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        $data = get_option($option_name, null);
        
        return $data;
    }
    
    /**
     * מחיקת נתונים שמורים
     * 
     * @param string $post_type סוג הפוסט של GeoDirectory
     * @param string $url כתובת ה-URL המקורית
     * @return bool האם המחיקה הצליחה
     */
    public function delete_saved_data($post_type, $url) {
        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        return delete_option($option_name);
    }
    
    /**
     * חילוץ דוגמאות משדה
     * 
     * @param string $field שם השדה
     * @param string $post_type סוג הפוסט
     * @param string $url כתובת ה-URL המקורית
     * @param int $limit מספר הדוגמאות המקסימלי
     * @return array מערך של דוגמאות
     */
    public function get_field_samples($field, $post_type, $url, $limit = 5) {
        // קבלת הנתונים השמורים
        $data = $this->get_saved_data($post_type, $url);
        
        if (empty($data) || !isset($data['data']) || !is_array($data['data'])) {
            return array();
        }
        
        // מציאת הטבלה הראשונה
        $first_table = reset($data['data']);
        
        if (empty($first_table) || empty($first_table['headers']) || empty($first_table['rows'])) {
            return array();
        }
        
        // מציאת האינדקס של השדה המבוקש
        $header_index = array_search($field, $first_table['headers']);
        
        if ($header_index === false) {
            return array();
        }
        
        // איסוף דוגמאות
        $samples = array();
        $sample_count = 0;
        
        foreach ($first_table['rows'] as $row) {
            if (isset($row[$header_index]) && !empty($row[$header_index])) {
                $samples[] = $row[$header_index];
                $sample_count++;
                
                if ($sample_count >= $limit) {
                    break;
                }
            }
        }
        
        return $samples;
    }
}