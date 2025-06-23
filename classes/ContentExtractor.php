<?php
/**
 * מחלקה לחילוץ תוכן מדפי אינטרנט
 * מיועדת לחילוץ טבלאות ונתונים מובנים מדפי HTML כולל קישורי קבצים
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentExtractor
{
    private $base_url;

    /**
     * חילוץ נתונים מכתובת URL
     */
    public function extract_from_url($url)
    {
        $parsed_url = parse_url($url);
        $this->base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return array(
                'success' => false,
                'message' => 'כתובת URL לא תקינה',
                'data' => null,
                'source_url' => $url
            );
        }

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'שגיאה בקריאת הדף: ' . $response->get_error_message(),
                'data' => null,
                'source_url' => $url
            );
        }

        $html_content = wp_remote_retrieve_body($response);

        return $this->parse_html_content($html_content, $url);
    }

    /**
     * ניתוח תוכן HTML וחילוץ טבלאות ונתונים
     */
    public function parse_html_content($html, $url)
    {
        if (empty($html)) {
            return array(
                'success' => false,
                'message' => 'לא נמצא תוכן בדף',
                'data' => null,
                'source_url' => $url
            );
        }

        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $tables = $dom->getElementsByTagName('table');

        if ($tables->length === 0) {
            return array(
                'success' => false,
                'message' => 'לא נמצאו טבלאות בדף',
                'data' => null,
                'html_content' => $html,
                'source_url' => $url
            );
        }

        $extracted_tables = array();

        foreach ($tables as $table_index => $table) {
            $table_data = $this->extract_table_data($table);

            if (empty($table_data['headers']) || count($table_data['rows']) < 1) {
                continue;
            }

            $extracted_tables[] = $table_data;
        }

        if (empty($extracted_tables)) {
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
     * חילוץ נתונים מטבלה עם זיהוי קישורים
     */
    public function extract_table_data($table)
    {
        $headers = array();
        $rows = array();

        $th_elements = $table->getElementsByTagName('th');

        if ($th_elements->length > 0) {
            foreach ($th_elements as $th) {
                $headers[] = $this->clean_text($th->textContent);
            }
        } else {
            $tr_elements = $table->getElementsByTagName('tr');

            if ($tr_elements->length > 0) {
                $first_row = $tr_elements->item(0);
                $td_elements = $first_row->getElementsByTagName('td');

                if ($td_elements->length > 0) {
                    foreach ($td_elements as $td) {
                        $headers[] = $this->clean_text($td->textContent);
                    }

                    $start_index = 1;
                } else {
                    $start_index = 0;
                }
            } else {
                return array('headers' => array(), 'rows' => array());
            }
        }

        if (empty($headers)) {
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
            $start_index = 1;
        }

        $tr_elements = $table->getElementsByTagName('tr');

        for ($i = $start_index; $i < $tr_elements->length; $i++) {
            $row = array();
            $td_elements = $tr_elements->item($i)->getElementsByTagName('td');

            for ($j = 0; $j < count($headers); $j++) {
                if ($j < $td_elements->length) {
                    $td = $td_elements->item($j);

                    $cell_data = $this->extract_cell_content($td);
                    $row[] = $cell_data;
                } else {
                    $row[] = '';
                }
            }

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
     * חילוץ תוכן מתא טבלה עם זיהוי קישורים
     */
    private function extract_cell_content($td)
    {
        $links = $td->getElementsByTagName('a');

        if ($links->length > 0) {
            $link = $links->item(0);
            $href = $link->getAttribute('href');

            if (!empty($href)) {
                $full_url = $this->convert_to_absolute_url($href);

                if ($this->is_file_link($full_url)) {
                    error_log("ContentExtractor: נמצא קישור קובץ: $full_url");
                    return $full_url;
                } else {
                    $link_text = $this->clean_text($link->textContent);
                    error_log("ContentExtractor: נמצא קישור רגיל, מחזיר רק טקסט: $link_text");
                    return $link_text;
                }
            }
        }

        $buttons = $td->getElementsByTagName('button');
        if ($buttons->length > 0) {
            $button = $buttons->item(0);
            $onclick = $button->getAttribute('onclick');

            if (!empty($onclick)) {
                $extracted_url = $this->extract_url_from_onclick($onclick);
                if ($extracted_url) {
                    $full_url = $this->convert_to_absolute_url($extracted_url);
                    
                    if ($this->is_file_link($full_url)) {
                        error_log("ContentExtractor: נמצא קישור קובץ בכפתור: $full_url");
                        return $full_url;
                    }
                }
            }
        }

        return $this->clean_text($td->textContent);
    }

    /**
     * המרת קישור יחסי לקישור מלא
     */
    private function convert_to_absolute_url($url)
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        if (substr($url, 0, 1) === '/') {
            return $this->base_url . $url;
        }

        return $this->base_url . '/' . $url;
    }

    /**
     * בדיקה אם זה קישור לקובץ
     */
    private function is_file_link($url)
    {
        $file_extensions = array(
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            'mp4', 'avi', 'mov', 'wmv', 'mp3', 'wav',
            'zip', 'rar', '7z', 'txt', 'csv'
        );

        $parsed_url = parse_url($url);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, $file_extensions)) {
            error_log("ContentExtractor: זוהה קובץ לפי סיומת: $extension");
            return true;
        }

        $file_patterns = array(
            '/\.ashx.*FileID.*\.(pdf|doc|docx|xls|xlsx)/i',
            '/GetPdfFile\.ashx/i',
            '/GetFile\.ashx.*FileID/i',
            '/download.*\.(pdf|doc|docx|xls|xlsx)/i',
            '/attachment.*\.(pdf|doc|docx|xls|xlsx)/i',
            '/[?&]FileID=.*\.(pdf|doc|docx|xls|xlsx)/i',
        );

        foreach ($file_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                error_log("ContentExtractor: זוהה קובץ לפי pattern: $pattern");
                return true;
            }
        }

        if ((strpos(strtolower($url), 'download') !== false || 
             strpos(strtolower($url), 'file') !== false ||
             strpos(strtolower($url), '.ashx') !== false) && 
            (strpos($url, 'FileID=') !== false || 
             strpos($url, 'documentId=') !== false ||
             strpos($url, 'attachmentId=') !== false)) {
            
            error_log("ContentExtractor: זוהה כקישור קובץ לפי מילות מפתח + פרמטרים");
            return true;
        }

        return false;
    }

    /**
     * חילוץ URL מאירוע onclick
     */
    private function extract_url_from_onclick($onclick)
    {
        $patterns = array(
            '/window\.open\([\'"]([^\'"]+)[\'"]/i',
            '/location\.href\s*=\s*[\'"]([^\'"]+)[\'"]/i',
            '/[\'"]([^\'"]*\.(pdf|doc|docx|xls|xlsx)[^\'"]*)[\'"]/',
            '/FileID=([^&\'"]+)/i'
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $onclick, $matches)) {
                return isset($matches[1]) ? $matches[1] : false;
            }
        }

        return false;
    }

    /**
     * חילוץ נתונים מרשימות
     */
    private function extract_lists_data($dom)
    {
        $lists = array();

        $ul_elements = $dom->getElementsByTagName('ul');

        if ($ul_elements->length === 0) {
            return array();
        }

        foreach ($ul_elements as $ul_index => $ul) {
            $li_elements = $ul->getElementsByTagName('li');

            if ($li_elements->length < 3) {
                continue;
            }

            $list_items = array();
            $pattern = null;
            $valid_pattern = true;

            foreach ($li_elements as $li) {
                $content = $this->clean_text($li->textContent);

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

            if (!$valid_pattern) {
                $list_items = array();

                foreach ($li_elements as $li) {
                    $list_items[] = $this->clean_text($li->textContent);
                }
            }

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
     */
    private function clean_text($text)
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text));
        return $clean;
    }

    /**
     * בדיקה אם שורה ריקה לחלוטין
     */
    private function is_empty_row($row)
    {
        foreach ($row as $cell) {
            if (!empty(trim($cell))) {
                return false;
            }
        }
        return true;
    }

    /**
     * שמירת נתונים שנחלצו לאפשרויות של וורדפרס
     */
    public function save_extracted_data($data, $post_type, $url)
    {
        if (empty($data) || !isset($data['data']) || !$data['success']) {
            return false;
        }

        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        update_option($option_name, $data);

        return true;
    }

    /**
     * קבלת נתונים שנשמרו לסוג פוסט ו-URL מסוימים
     */
    public function get_saved_data($post_type, $url)
    {
        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        $data = get_option($option_name, null);

        return $data;
    }

    /**
     * מחיקת נתונים שמורים
     */
    public function delete_saved_data($post_type, $url)
    {
        $option_name = 'ltgdai_extracted_data_' . sanitize_text_field($post_type) . '_' . md5($url);
        return delete_option($option_name);
    }

    /**
     * חילוץ דוגמאות משדה
     */
    public function get_field_samples($field, $post_type, $url, $limit = 5)
    {
        $data = $this->get_saved_data($post_type, $url);

        if (empty($data) || !isset($data['data']) || !is_array($data['data'])) {
            return array();
        }

        $first_table = reset($data['data']);

        if (empty($first_table) || empty($first_table['headers']) || empty($first_table['rows'])) {
            return array();
        }

        $header_index = array_search($field, $first_table['headers']);

        if ($header_index === false) {
            return array();
        }

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