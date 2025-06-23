<?php
/**
 * עמוד היבוא של הפלאגין ListToGD
 * מאפשר למשתמש להזין כתובות URL של רשימות שברצונו להסב ל-GeoDirectory
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * פונקציה להצגת ממשק היבוא
 */
function ltgdai_render_import_page() {
    ltgdai_process_import_form_submission();
    
    $saved_urls = get_option('ltgdai_saved_urls', array());
    $post_types = get_option('ltgdai_saved_post_types', array());
    
    if (empty($saved_urls) || (isset($_GET['reset']) && $_GET['reset'] == 'true')) {
        $saved_urls = array('', '', '');
        $post_types = array('', '', '');
    }
    
    $gd_post_types = ltgdai_get_geodirectory_post_types();
    
    ltgdai_render_import_form($saved_urls, $post_types, $gd_post_types);
}

/**
 * קבלת סוגי פוסטים של GeoDirectory
 */
function ltgdai_get_geodirectory_post_types() {
    $gd_post_types = array(
        'post' => 'פוסטים',
        'article' => 'כתבות/מבזקים'
    );
    
    $registered_post_types = get_post_types(['public' => true], 'objects');
    
    foreach ($registered_post_types as $type) {
        if (substr($type->name, 0, 3) === 'gd_') {
            $display_name = isset($type->labels->name) ? $type->labels->name : 
                           (isset($type->labels->singular_name) ? $type->labels->singular_name : 
                            ucfirst(str_replace('gd_', '', $type->name)));
            
            $gd_post_types[$type->name] = $display_name;
        }
    }
    
    if (function_exists('geodir_get_posttypes')) {
        $gd_specific_types = geodir_get_posttypes('array');
        foreach ($gd_specific_types as $type_name => $type_data) {
            if (!isset($gd_post_types[$type_name])) {
                if (isset($type_data['labels']['name'])) {
                    $gd_post_types[$type_name] = $type_data['labels']['name'];
                } else if (isset($type_data['labels']['singular_name'])) {
                    $gd_post_types[$type_name] = $type_data['labels']['singular_name'];
                } else {
                    $gd_post_types[$type_name] = ucfirst(str_replace('gd_', '', $type_name));
                }
            }
        }
    }
    
    asort($gd_post_types);
    
    $fixed_types = array(
        'post' => 'פוסטים',
        'article' => 'כתבות/מבזקים'
    );
    
    $gd_post_types = array_merge($fixed_types, $gd_post_types);
    
    if (count($gd_post_types) <= 2) {
        $gd_post_types['no_gd'] = 'לא נמצאו סוגי פוסטים של GeoDirectory';
    }
    
    return $gd_post_types;
}

/**
 * הצגת טופס היבוא
 */
function ltgdai_render_import_form($saved_urls, $post_types, $gd_post_types) {
    ?>
    <div class="wrap ltgdai-container" dir="rtl">
        <h1 class="ltgdai-title">ייבוא מקורות ל-GeoDirectory</h1>

        <form method="post" id="ltgdai-import-form">
            <div class="ltgdai-panel">
                <div class="ltgdai-panel-header">
                    <h2>הזן את כתובת דפי הרשימות שברצונך להסב</h2>
                </div>
                
                <div class="ltgdai-panel-content">
                    <table class="ltgdai-url-table">
                        <thead>
                            <tr>
                                <th class="ltgdai-url">URL</th>
                                <th class="ltgdai-post-type">GD</th>
                                <th class="ltgdai-action">מחק</th>
                            </tr>
                        </thead>
                        <tbody id="ltgdai-url-list">
                            <?php foreach ($saved_urls as $index => $url): 
                                $post_type = isset($post_types[$index]) ? $post_types[$index] : '';
                            ?>
                            <tr class="ltgdai-url-row">
                                <td class="ltgdai-url">
                                    <input type="url" name="ltgdai_urls[]" value="<?php echo esc_attr($url); ?>" 
                                           placeholder="https://example.com/listing/2" class="ltgdai-url-input">
                                </td>
                                <td class="ltgdai-post-type">
                                    <select name="ltgdai_post_types[]" class="ltgdai-post-type-select">
                                        <?php 
                                        foreach ($gd_post_types as $type_name => $type_label) {
                                            echo '<option value="' . esc_attr($type_name) . '"' . 
                                                selected($post_type, $type_name, false) . '>' . 
                                                esc_html($type_label) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td class="ltgdai-action">
                                    <button type="button" class="ltgdai-delete-url">מחק</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="ltgdai-add-url-container">
                        <button type="button" id="ltgdai-add-url" class="button">+URL</button>
                    </div>
                </div>
            </div>

            <div class="ltgdai-button-row">
                <button type="submit" name="ltgdai_save" class="ltgdai-button ltgdai-save">שמור</button>
                <button type="submit" name="ltgdai_mapping" class="ltgdai-button ltgdai-mapping">המשך למיפוי</button>
            </div>

            <?php wp_nonce_field('ltgdai_import_nonce', 'ltgdai_nonce'); ?>
        </form>
    </div>
    <?php
}

/**
 * עיבוד שליחת טופס היבוא
 */
function ltgdai_process_import_form_submission() {
    if (isset($_POST['ltgdai_save']) || isset($_POST['ltgdai_mapping'])) {
        if (!isset($_POST['ltgdai_nonce']) || !wp_verify_nonce($_POST['ltgdai_nonce'], 'ltgdai_import_nonce')) {
            wp_die('אבטחה: הפעולה נכשלה.');
        }
        
        $urls = isset($_POST['ltgdai_urls']) ? array_map('sanitize_url', $_POST['ltgdai_urls']) : array();
        $post_types = isset($_POST['ltgdai_post_types']) ? array_map('sanitize_text_field', $_POST['ltgdai_post_types']) : array();
        
        $filtered_urls = array();
        $filtered_post_types = array();
        
        foreach ($urls as $index => $url) {
            if (!empty($url)) {
                $filtered_urls[] = $url;
                $filtered_post_types[] = $post_types[$index];
            }
        }
        
        update_option('ltgdai_saved_urls', $filtered_urls);
        update_option('ltgdai_saved_post_types', $filtered_post_types);
        
        if (isset($_POST['ltgdai_save'])) {
            echo '<div class="notice notice-success is-dismissible"><p>הנתונים נשמרו בהצלחה!</p></div>';
        }
        
        if (isset($_POST['ltgdai_mapping'])) {
            wp_redirect(admin_url('admin.php?page=ltgdai-mapping'));
            exit;
        }
    }
}