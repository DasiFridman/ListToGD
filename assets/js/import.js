/**
 * סקריפטים עבור אזור הניהול של פלאגין ListToGD
 */
jQuery(document).ready(function($) {
    /**
     * הוספת שורת URL חדשה
     */
    $('#ltgdai-add-url').on('click', function() {
        // יצירת שורה חדשה עם תאים לפי הסדר הנדרש: קישור, GD, מחיקה
        var newRow = `
            <tr class="ltgdai-url-row">
                <td class="ltgdai-url">
                    <input type="url" name="ltgdai_urls[]" value="" 
                           placeholder="https://example.com/listing/2" class="ltgdai-url-input">
                </td>
                <td class="ltgdai-post-type">
                    <select name="ltgdai_post_types[]" class="ltgdai-post-type-select">
                        ${getAllPostTypeOptions()}
                    </select>
                </td>
                <td class="ltgdai-action">
                    <button type="button" class="ltgdai-delete-url">מחק</button>
                </td>
            </tr>
        `;
        $('#ltgdai-url-list').append(newRow);
    });
    
    /**
     * מחיקת שורת URL
     */
    $(document).on('click', '.ltgdai-delete-url', function() {
        // לא למחוק אם זו השורה היחידה
        if ($('.ltgdai-url-row').length > 1) {
            $(this).closest('tr').remove();
        } else {
            // לנקות את השדות במקום למחוק
            $(this).closest('tr').find('input[type="url"]').val('');
            $(this).closest('tr').find('select').val('');
        }
    });
    
    /**
     * בדיקת תקינות הטופס לפני שליחה
     */
    $('#ltgdai-import-form').on('submit', function(e) {
        // בדיקת תקינות בסיסית
        var hasEmptyUrls = false;
        $('.ltgdai-url-input').each(function() {
            if (!$(this).val()) {
                hasEmptyUrls = true;
            }
        });
        
        if (hasEmptyUrls) {
            e.preventDefault();
            alert('יש למלא את כל שדות ה-URL');
        }
    });
    
    /**
     * פונקציה עזר להשגת כל האפשרויות של סוגי הפוסטים
     * הערה: הפונקציה משתמשת באלמנטים קיימים בדף
     */
    function getAllPostTypeOptions() {
        var options = '';
        $('.ltgdai-url-row:first-child .ltgdai-post-type-select option').each(function() {
            options += `<option value="${$(this).val()}">${$(this).text()}</option>`;
        });
        return options;
    }
});