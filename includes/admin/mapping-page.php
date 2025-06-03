<?php
/**
 * עמוד המיפוי של הפלאגין ListToGD
 * מאפשר למשתמש למפות שדות מהמקור לשדות GeoDirectory
 */

// אבטחה – מניעת גישה ישירה לקובץ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * פונקציה להצגת ממשק המיפוי
 */
function ltgdai_render_mapping_page() {
    // עיבוד שליחת הטופס קודם
    ltgdai_process_mapping_form_submission();
    
    // קבלת נתוני ה-URL מעמוד הייבוא
    $urls = get_option('ltgdai_saved_urls', array());
    $post_types = get_option('ltgdai_saved_post_types', array());
    
    // ארגון הנתונים לפי סוג פוסט
    $organized_data = array();
    foreach ($urls as $index => $url) {
        if (empty($url)) continue;
        
        $post_type = isset($post_types[$index]) ? $post_types[$index] : '';
        if (empty($post_type)) continue;
        
        if (!isset($organized_data[$post_type])) {
            $organized_data[$post_type] = array();
        }
        
        $organized_data[$post_type][] = $url;
    }
    
    // אם אין נתונים, הצג הודעה
    if (empty($organized_data)) {
        echo '<div class="wrap ltgdai-container" dir="rtl">';
        echo '<h1 class="ltgdai-title">מיפוי שדות ל-GeoDirectory</h1>';
        echo '<div class="notice notice-warning"><p>לא נמצאו נתונים למיפוי. <a href="' . admin_url('admin.php?page=ltgdai-import') . '">חזור לעמוד היבוא</a> להזנת כתובות URL.</p></div>';
        echo '</div>';
        return;
    }
    
    // קביעת הטאב הנוכחי
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
    
    // אם לא נבחר טאב או הטאב לא קיים, בחר את הראשון
    if (empty($current_tab) || !isset($organized_data[$current_tab])) {
        $post_types_keys = array_keys($organized_data);
        $current_tab = reset($post_types_keys);
    }
    
    // טעינת handler למיפוי
    require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
    $mapping_handler = new MappingHandler();
    
    // קבלת מיפוי קיים לטאב הנוכחי, אם קיים
    $existing_mappings = $mapping_handler->get_field_mappings($current_tab);
    
    // סימון לשוניות שהושלמו
    $completed_tabs = get_option('ltgdai_completed_tabs', array());
    
    // אוטומטית חלץ נתונים אם אין נתונים שחולצו עבור הטאב הנוכחי
    $needs_extraction = true;
    
    foreach ($organized_data[$current_tab] as $url) {
        $option_name = 'ltgdai_extracted_data_' . $current_tab . '_' . md5($url);
        $extracted_data = get_option($option_name);
        
        if (!empty($extracted_data) && $extracted_data['success']) {
            $needs_extraction = false;
            break;
        }
    }
    
    // חילוץ נתונים אוטומטי אם צריך
    if ($needs_extraction && !empty($organized_data[$current_tab][0])) {
        // טעינת מחלץ התוכן
        require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
        $extractor = new ContentExtractor();
        
        // חילוץ נתונים מהקישור הראשון
        $url = $organized_data[$current_tab][0];
        $extracted_data = $extractor->extract_from_url($url);
        
        if ($extracted_data['success']) {
            // שמירת הנתונים שחולצו
            $option_name = 'ltgdai_extracted_data_' . $current_tab . '_' . md5($url);
            update_option($option_name, $extracted_data);
        }
    }
    
    // הגדרת התוכן לפני הצגת הממשק
    $gd_fields = $mapping_handler->get_geodirectory_fields($current_tab);
    
    // הצגת הממשק
    ?>
    <div class="wrap ltgdai-container" dir="rtl">
        <h1 class="ltgdai-title">מיפוי שדות ל-GeoDirectory</h1>
        
        <!-- לשוניות סוגי פוסטים -->
        <div class="ltgdai-tabs-container">
            <ul class="ltgdai-tabs">
                <?php foreach ($organized_data as $post_type => $post_urls): ?>
                    <?php 
                    $is_active = ($post_type === $current_tab);
                    $tab_class = $is_active ? 'ltgdai-tab active' : 'ltgdai-tab';
                    $post_type_label = ltgdai_get_post_type_label($post_type);
                    $is_completed = in_array($post_type, $completed_tabs);
                    ?>
                    <li class="<?php echo $tab_class; ?>">
                        <a href="<?php echo admin_url('admin.php?page=ltgdai-mapping&tab=' . $post_type); ?>">
                            <?php echo esc_html($post_type_label); ?>
                            <?php if ($is_completed): ?>
                                <span class="ltgdai-tab-completed">✓</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <!-- תוכן עמוד המיפוי -->
        <form method="post" id="ltgdai-mapping-form">
            <!-- שורת כפתורי פעולה -->
            <div class="ltgdai-actions-row">
                <div class="ltgdai-filter-bar">
                    <select id="ltgdai-field-filter">
                        <option value="">מחיקה</option>
                        <option value="delete-selected">מחק שדות נבחרים</option>
                        <option value="reset-selected">אפס מיפוי נבחרים</option>
                    </select>
                    <button type="button" id="ltgdai-apply-filter" class="button">בצע</button>
                </div>
                
                <div class="ltgdai-action-buttons">
                   
                    <button type="submit" name="ltgdai_reset_mappings" class="ltgdai-button" 
                            onclick="return confirm('האם אתה בטוח שברצונך לבטל את כל השינויים? פעולה זו תאפס את כל המיפויים.');">
                        בטל את כל השינויים
                    </button>
                </div>
            </div>
            
            <!-- פאנל תוכן ראשי -->
            <div class="ltgdai-panel">
                <div class="ltgdai-panel-header">
                    <h2>מפה תוכן מקור לשדות GeoDirectory</h2>
                    <p>בחר שדות מקור ושייך אותם לשדות GeoDirectory המתאימים.</p>
                </div>
                
                <div class="ltgdai-panel-content">
                    <!-- מיפוי השדות -->
                    <div id="ltgdai-mapping-list">
                        <?php
                        // עבור כל URL, נציג את נתוני החילוץ שלו, אם קיימים
                        foreach ($organized_data[$current_tab] as $url): 
                            $option_name = 'ltgdai_extracted_data_' . $current_tab . '_' . md5($url);
                            $extracted_data = get_option($option_name);
                            
                            if (!empty($extracted_data) && $extracted_data['success']):
                                // מציג רק את הטבלה הראשונה בינתיים
                                $first_table = reset($extracted_data['data']);
                        ?>
                        <div class="ltgdai-extracted-data">
                            <!-- <div class="ltgdai-url-display"><?php echo esc_url($url); ?></div> -->
                            
                            <?php if (!empty($first_table['headers'])): ?>
                            <?php
                            // מיפוי קיים
                            $url_key = md5($url);
                            $field_mappings = isset($existing_mappings['field_mappings'][$url_key]) ? 
                                              $existing_mappings['field_mappings'][$url_key] : array();
                            
                            // הצגת שורה לכל שדה מקור
                            foreach ($first_table['headers'] as $header_index => $source_field):
                                // מציאת שדה GD שכבר ממופה לשדה המקור הזה (אם קיים)
                                $mapped_gd_field = '';
                                foreach ($field_mappings as $gd_field => $src_field) {
                                    if ($src_field === $source_field) {
                                        $mapped_gd_field = $gd_field;
                                        break;
                                    }
                                }
                            ?>
                            <div class="ltgdai-mapping-row <?php echo !empty($mapped_gd_field) ? 'ltgdai-mapped-row' : ''; ?>">
                                <div class="ltgdai-field-selected">
                                    <input type="checkbox" 
                                           name="ltgdai_field_selected[<?php echo esc_attr($url_key); ?>][<?php echo esc_attr($source_field); ?>]" 
                                           value="1">
                                </div>
                                <div class="ltgdai-sample">
                                    <input type="hidden" name="ltgdai_source_fields[<?php echo esc_attr($url_key); ?>][]" 
                                           value="<?php echo esc_attr($source_field); ?>">
                                    <div class="ltgdai-sample-data">
                                        <?php
                                        // הצגת דוגמאות משדה זה
                                        $samples = array();
                                        $sample_count = min(5, count($first_table['rows']));
                                        
                                        for ($i = 0; $i < $sample_count; $i++):
                                            if (isset($first_table['rows'][$i][$header_index])):
                                                $samples[] = $first_table['rows'][$i][$header_index];
                                            endif;
                                        endfor;
                                        
                                        if (!empty($samples)):
                                            echo '<select class="ltgdai-sample-select">';
                                            foreach ($samples as $index => $sample):
                                                echo '<option value="' . esc_attr($index) . '">' . esc_html($sample) . '</option>';
                                            endforeach;
                                            echo '</select>';
                                        else:
                                            echo '<div class="ltgdai-empty-sample">אין דוגמאות זמינות</div>';
                                        endif;
                                        ?>
                                    </div>
                                </div>
                                <div class="ltgdai-gd-field">
                                    <select name="ltgdai_field_mapping[<?php echo esc_attr($url_key); ?>][<?php echo esc_attr($source_field); ?>]" 
                                            class="ltgdai-gd-select">
                                        <option value="">בחר שדה</option>
                                        <?php foreach ($gd_fields as $gd_field_key => $gd_field_data): ?>
                                        <option value="<?php echo esc_attr($gd_field_key); ?>" 
                                                <?php selected($mapped_gd_field, $gd_field_key); ?>>
                                            <?php echo esc_html($gd_field_data['label']); ?>
                                            <?php if ($gd_field_data['required']): ?>
                                                <span class="ltgdai-required">*</span>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notice notice-warning">
                                    <p>לא נמצאו כותרות בטבלה המחולצת. נסה לחלץ נתונים מחדש.</p>
                                </div>
                            <?php endif; ?>

                            <!-- מיכל לשדות מותאמים -->
                            <div id="ltgdai-custom-fields-container">
                                <?php
                                // טעינת שדות מותאמים שמורים
                                if (isset($existing_mappings['custom_fields'])) {
                                    $url_key = md5($url);
                                    if (isset($existing_mappings['custom_fields'][$url_key])) {
                                        foreach ($existing_mappings['custom_fields'][$url_key] as $index => $custom_field) {
                                            $custom_field_key = isset($custom_field['field_key']) ? $custom_field['field_key'] : 'custom_field_' . $index;
                                            $custom_name = $custom_field['name'];
                                            $mapped_gd_field = $custom_field['gd_field'];
                                            ?>
                                            <div class="ltgdai-mapping-row ltgdai-custom-field-row">
                                                <div class="ltgdai-field-selected">
                                                    <input type="checkbox" 
                                                           name="ltgdai_field_selected[<?php echo esc_attr($url_key); ?>][<?php echo esc_attr($custom_field_key); ?>]" 
                                                           value="1">
                                                </div>
                                                <div class="ltgdai-sample">
                                                    <input type="text" 
                                                           name="ltgdai_custom_field_name[<?php echo esc_attr($url_key); ?>][]" 
                                                           value="<?php echo esc_attr($custom_name); ?>"
                                                           placeholder="הזן שם שדה מותאם..." 
                                                           class="ltgdai-custom-field-input">
                                                    <input type="hidden" 
                                                           name="ltgdai_source_fields[<?php echo esc_attr($url_key); ?>][]" 
                                                           value="<?php echo esc_attr($custom_field_key); ?>">
                                                </div>
                                                <div class="ltgdai-gd-field">
                                                    <select name="ltgdai_field_mapping[<?php echo esc_attr($url_key); ?>][<?php echo esc_attr($custom_field_key); ?>]" 
                                                            class="ltgdai-gd-select">
                                                        <option value="">בחר שדה</option>
                                                        <?php foreach ($gd_fields as $gd_field_key => $gd_field_data): ?>
                                                        <option value="<?php echo esc_attr($gd_field_key); ?>" 
                                                                <?php selected($mapped_gd_field, $gd_field_key); ?>>
                                                            <?php echo esc_html($gd_field_data['label']); ?>
                                                            <?php if ($gd_field_data['required']): ?>
                                                                <span class="ltgdai-required">*</span>
                                                            <?php endif; ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                    }
                                }
                                ?>
                            </div>

                            <!-- כפתור הוספת שדה מותאם אישית -->
                            <div class="ltgdai-add-field-container">
                                <button type="button" id="ltgdai-add-custom-field" >+הוסף שדה </button>
                            </div>
                        </div>
                        <?php
                            // שמירת מידע על הקישור למיפוי
                            echo '<input type="hidden" name="ltgdai_url_mapping[]" value="' . esc_attr($url) . '">';
                            endif;
                        endforeach;
                        ?>
                    </div>
                    
                    <!-- כפתורי פעולה בתחתית הפאנל -->
                    <div class="ltgdai-bottom-actions">
                        <a href="<?php echo admin_url('admin.php?page=ltgdai-import'); ?>" class="ltgdai-button ltgdai-secondary">
                            חזור ליבוא
                        </a>
                        <button type="submit" name="ltgdai_ready_for_import" class="ltgdai-button ltgdai-success">
                            סמן כמוכן להזנה
                        </button>
                        <button type="button" id="ltgdai-start-import" class="ltgdai-button ltgdai-save">
                            התחל הזנה
                        </button>
                    </div>
                </div>
            </div>
            
            <?php wp_nonce_field('ltgdai_mapping_nonce', 'ltgdai_mapping_nonce'); ?>
            <input type="hidden" name="ltgdai_current_tab" value="<?php echo esc_attr($current_tab); ?>">
        </form>
    </div>
    <?php
}

/**
 * פונקציה לעיבוד שליחת טופס המיפוי
 */
/**
 * פונקציה לעיבוד שליחת טופס המיפוי - מתוקן לשדות מותאמים
 */
function ltgdai_process_mapping_form_submission() {
    if (isset($_POST['ltgdai_extract_data']) || isset($_POST['ltgdai_ready_for_import']) || isset($_POST['ltgdai_save_mapping']) || isset($_POST['ltgdai_reset_mappings'])) {
        // אימות nonce
        if (!isset($_POST['ltgdai_mapping_nonce']) || !wp_verify_nonce($_POST['ltgdai_mapping_nonce'], 'ltgdai_mapping_nonce')) {
            wp_die('אבטחה: הפעולה נכשלה.');
        }
        
        $current_tab = isset($_POST['ltgdai_current_tab']) ? sanitize_text_field($_POST['ltgdai_current_tab']) : '';
        
        // עיבוד פעולת איפוס כל השינויים
        if (isset($_POST['ltgdai_reset_mappings'])) {
            require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
            $mapping_handler = new MappingHandler();
            
            $mapping_handler->delete_field_mappings($current_tab);
            
            $completed_tabs = get_option('ltgdai_completed_tabs', array());
            $completed_tabs = array_diff($completed_tabs, array($current_tab));
            update_option('ltgdai_completed_tabs', $completed_tabs);
            
            echo '<div class="notice notice-success is-dismissible"><p>כל השינויים בוטלו בהצלחה! המיפויים אופסו למצב הראשוני.</p></div>';
            return;
        }
        
        // עיבוד פעולת חילוץ נתונים
        if (isset($_POST['ltgdai_extract_data'])) {
            $url = isset($_POST['ltgdai_url']) ? sanitize_url($_POST['ltgdai_url']) : '';
            if (!empty($url)) {
                require_once LTGDAI_PLUGIN_DIR . 'classes/ContentExtractor.php';
                $extractor = new ContentExtractor();
                
                $extracted_data = $extractor->extract_from_url($url);
                
                if ($extracted_data['success']) {
                    $option_name = 'ltgdai_extracted_data_' . $current_tab . '_' . md5($url);
                    update_option($option_name, $extracted_data);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>נתונים חולצו בהצלחה!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>שגיאה בחילוץ נתונים: ' . esc_html($extracted_data['message']) . '</p></div>';
                }
            }
        }
        
        // עיבוד סימון כמוכן להזנה או שמירת מיפוי
        if (isset($_POST['ltgdai_ready_for_import']) || isset($_POST['ltgdai_save_mapping'])) {
            require_once LTGDAI_PLUGIN_DIR . 'classes/MappingHandler.php';
            $mapping_handler = new MappingHandler();
            
            // קבלת נתוני המיפוי מהטופס
            $field_mappings = isset($_POST['ltgdai_field_mapping']) ? $_POST['ltgdai_field_mapping'] : array();
            $custom_field_names = isset($_POST['ltgdai_custom_field_name']) ? $_POST['ltgdai_custom_field_name'] : array();
            
            // איסוף נתוני מיפוי מעודכנים
            $mapping_data = array(
                'field_mappings' => array(),
                'custom_fields' => array()
            );
            
            // עיבוד המיפויים הרגילים
            foreach ($field_mappings as $url_key => $mappings) {
                $mapping_data['field_mappings'][$url_key] = array();
                
                foreach ($mappings as $source_field => $gd_field) {
                    if (!empty($gd_field)) {
                        $mapping_data['field_mappings'][$url_key][$gd_field] = $source_field;
                    }
                }
            }
            
            // עיבוד שדות מותאמים - הלוגיקה החדשה המתוקנת
            foreach ($custom_field_names as $url_key => $custom_names) {
                if (!isset($mapping_data['custom_fields'][$url_key])) {
                    $mapping_data['custom_fields'][$url_key] = array();
                }
                
                // עבור כל שדה מותאם שיש לו שם
                foreach ($custom_names as $index => $custom_name) {
                    if (!empty(trim($custom_name))) {
                        // יצירת מפתח השדה המותאם
                        $custom_field_key = 'custom_field_' . ($index + 1); // התחלה מ-1 במקום 0
                        
                        // חיפוש המיפוי המתאים לשדה הזה
                        $gd_field = '';
                        if (isset($field_mappings[$url_key][$custom_field_key]) && 
                            !empty($field_mappings[$url_key][$custom_field_key])) {
                            $gd_field = $field_mappings[$url_key][$custom_field_key];
                        }
                        
                        // שמירת השדה המותאם
                        $mapping_data['custom_fields'][$url_key][] = array(
                            'name' => trim($custom_name),
                            'gd_field' => $gd_field,
                            'field_key' => $custom_field_key
                        );
                    }
                }
            }
            
            // שמירת המיפוי הקיים (כולל פרמטרי עיבוד)
            $existing_mappings = $mapping_handler->get_field_mappings($current_tab);
            
            if (isset($existing_mappings['processing_params'])) {
                $mapping_data['processing_params'] = $existing_mappings['processing_params'];
            }
            
            // שמירת המיפוי המעודכן
            $mapping_handler->save_field_mappings($current_tab, $mapping_data);
            
            // סימון הטאב כמושלם
            $completed_tabs = get_option('ltgdai_completed_tabs', array());
            if (!in_array($current_tab, $completed_tabs)) {
                $completed_tabs[] = $current_tab;
                update_option('ltgdai_completed_tabs', $completed_tabs);
            }
            
            if (isset($_POST['ltgdai_ready_for_import'])) {
                echo '<div class="notice notice-success is-dismissible"><p>הסוג סומן כמוכן להזנה והנתונים נשמרו!</p></div>';
            }
            
            if (isset($_POST['ltgdai_save_mapping'])) {
                echo '<div class="notice notice-success is-dismissible"><p>המיפוי נשמר בהצלחה והטאב סומן כמוכן להזנה!</p></div>';
            }
        }
    }
}

/**
 * פונקציה לקבלת תווית עבור סוג פוסט
 *
 * @param string $post_type קוד סוג הפוסט
 * @return string תווית מתורגמת לעברית
 */
function ltgdai_get_post_type_label($post_type) {
    // שליפת שמות מותאמים מהפונקציה שהגדרת בעמוד היבוא
    if (function_exists('ltgdai_get_geodirectory_post_types')) {
        $gd_post_types = ltgdai_get_geodirectory_post_types();
        
        // אם קיים תרגום מוגדר מראש במערך, השתמש בו
        if (isset($gd_post_types[$post_type])) {
            return $gd_post_types[$post_type];
        }
    }
    
    // מערך גיבוי של שמות פוסט טייפ במקרה שהפונקציה לא זמינה
    $post_type_names = array(
        'post' => 'פוסטים',
        'article' => 'כתבות/מבזקים',
        'gd_place' => 'מקומות',
        // כאן אפשר להוסיף עוד סוגי פוסטים מותאמים
    );
    
    // אם קיים תרגום מוגדר מראש, השתמש בו
    if (isset($post_type_names[$post_type])) {
        return $post_type_names[$post_type];
    }
    
    // אם זה פוסט טייפ של GeoDirectory
    if (substr($post_type, 0, 3) === 'gd_') {
        $nice_name = ucfirst(str_replace('gd_', '', $post_type));
        return $nice_name;
    }
    
    // ברירת מחדל - השתמש בשם הפוסט טייפ כפי שהוא
    return ucfirst($post_type);
}