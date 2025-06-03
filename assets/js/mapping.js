/**
 * סקריפטים עבור עמוד המיפוי של פלאגין ListToGD - עם שדות מותאמים אישית - מתוקן
 */
jQuery(document).ready(function($) {
    // מונה לשדות מותאמים חדשים - התחלה מהשדות הקיימים
    var customFieldCounter = $('#ltgdai-custom-fields-container .ltgdai-custom-field-row').length;
    
    /**
     * עדכון מראה שורה כאשר משתנה הבחירה של שדה GD - ללא בחירה אוטומטית
     */
    $(document).on('change', '.ltgdai-gd-select', function() {
        var $select = $(this);
        var $row = $select.closest('.ltgdai-mapping-row');
        var $checkbox = $row.find('input[type="checkbox"]');
        
        // עדכון מראה השורה בלבד - ללא בחירה אוטומטית
        if ($select.val() !== '') {
            $row.addClass('ltgdai-mapped-row');
        } else {
            $row.removeClass('ltgdai-mapped-row');
            // אם לא נבחר שדה, בטל גם את הצ'קבוקס
            $checkbox.prop('checked', false);
        }
    });
    
    /**
     * סימון/ביטול סימון כאשר לוחצים על צ'קבוקס - עדכון מראה
     */
    $(document).on('change', '.ltgdai-field-selected input[type="checkbox"]:not(:disabled)', function() {
        var $checkbox = $(this);
        var $row = $checkbox.closest('.ltgdai-mapping-row');
        var $select = $row.find('.ltgdai-gd-select');
        
        // עדכון מראה השורה לפי מצב הצ'קבוקס
        if ($checkbox.prop('checked')) {
            // אם יש שדה GD נבחר, הדגש את השורה
            if ($select.val() !== '') {
                $row.addClass('ltgdai-mapped-row');
            }
        } else {
            // אם הצ'קבוקס לא מסומן, הסר הדגשה
            $row.removeClass('ltgdai-mapped-row');
        }
    });
    
    /**
     * כפתור ביצוע פעולה נבחרת - רק על שדות נבחרים
     */
    $('#ltgdai-apply-filter').on('click', function() {
        var action = $('#ltgdai-field-filter').val();
        var selectedRows = $('.ltgdai-field-selected input[type="checkbox"]:checked').closest('.ltgdai-mapping-row');
        
        if (selectedRows.length === 0) {
            alert('נא לבחור לפחות שדה אחד לפני ביצוע הפעולה');
            return;
        }
        
        if (action === 'delete-selected') {
            if (confirm('האם אתה בטוח שברצונך למחוק ' + selectedRows.length + ' שדות נבחרים?')) {
                selectedRows.each(function() {
                    $(this).fadeOut(300, function() {
                        $(this).remove();
                    });
                });
                
                // עדכון הודעה
                setTimeout(function() {
                    alert(selectedRows.length + ' שדות נמחקו בהצלחה');
                }, 400);
            }
        } else if (action === 'reset-selected') {
            if (confirm('האם אתה בטוח שברצונך לאפס את המיפוי של ' + selectedRows.length + ' שדות נבחרים?')) {
                selectedRows.each(function() {
                    var $row = $(this);
                    var $select = $row.find('.ltgdai-gd-select');
                    var $checkbox = $row.find('input[type="checkbox"]');
                    
                    // איפוס הבחירות
                    $select.val('');
                    $checkbox.prop('checked', false);
                    $row.removeClass('ltgdai-mapped-row');
                });
                
                alert('המיפוי של ' + selectedRows.length + ' שדות נבחרים אופס בהצלחה');
            }
        } else {
            alert('נא לבחור פעולה מהרשימה');
        }
    });
    
    /**
     * כפתור חילוץ מחדש
     */
    $('#ltgdai-extract-again').on('click', function() {
        if (confirm('האם אתה בטוח שברצונך לחלץ נתונים מחדש? זה עלול לדרוס את המיפוי הנוכחי.')) {
            // שליחת בקשה AJAX לחילוץ מחדש
            $.ajax({
                url: ltgdai_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ltgdai_extract_data_again',
                    post_type: $('input[name="ltgdai_current_tab"]').val(),
                    nonce: ltgdai_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('נתונים חולצו מחדש בהצלחה!');
                        window.location.reload();
                    } else {
                        alert('שגיאה בחילוץ הנתונים: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('שגיאה בתקשורת עם השרת');
                }
            });
        }
    });
    
    /**
     * בדיקה אם יש לפחות שדה אחד עם מיפוי לפני שמירה
     */
    $('#ltgdai-mapping-form').on('submit', function(e) {
        if ($('button[name="ltgdai_save_mapping"]').is(':focus') || $('button[name="ltgdai_ready_for_import"]').is(':focus')) {
            var anyMapped = false;
            var hasCustomFields = false;
            
            // בדיקה אם יש לפחות שדה אחד עם מיפוי (בלי תלות בצ'קבוקס)
            $('.ltgdai-gd-select').each(function() {
                if ($(this).val() !== '') {
                    anyMapped = true;
                    return false; // יציאה מהלולאה
                }
            });
            
            // בדיקה אם יש שדות מותאמים עם שמות
            $('.ltgdai-custom-field-input').each(function() {
                if ($(this).val().trim() !== '') {
                    hasCustomFields = true;
                    anyMapped = true; // שדה מותאם עם שם נחשב כמיפוי
                    return false;
                }
            });
            
            if (!anyMapped && !hasCustomFields) {
                alert('נא לבחור לפחות שדה GD אחד למיפוי או להוסיף שדה מותאם');
                e.preventDefault();
                return false;
            }
        }
    });
    
    /**
     * הוספת אפקטים ויזואליים
     */
    
    // הדגשת שורה במעבר עכבר
    $(document).on('mouseenter', '.ltgdai-mapping-row', function() {
        $(this).css('transform', 'translateX(-2px)');
    });
    
    $(document).on('mouseleave', '.ltgdai-mapping-row', function() {
        $(this).css('transform', 'translateX(0)');
    });
    
    /**
     * סנכרון מראה עם מצב טעינת הדף - עדכון לפי צ'קבוקס ושדה GD
     */
    function initializeMappingRows() {
        $('.ltgdai-mapping-row').each(function() {
            var $row = $(this);
            var $select = $row.find('.ltgdai-gd-select');
            var $checkbox = $row.find('input[type="checkbox"]');
            
            // הדגש שורה רק אם יש שדה GD נבחר
            if ($select.val() !== '') {
                $row.addClass('ltgdai-mapped-row');
            } else {
                $row.removeClass('ltgdai-mapped-row');
            }
        });
    }
    
    // הרצת הפונקציה בטעינת הדף
    initializeMappingRows();
    
    /**
     * טיפול במקשי קיצור
     */
    $(document).on('keydown', function(e) {
        // Ctrl+S לשמירה
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            $('button[name="ltgdai_ready_for_import"]').click();
        }
    });
    
    /**
     * שיפור נגישות
     */
    
    // הוספת תמיכה במקלדת לתפריטי בחירה
    $('.ltgdai-gd-select').on('focus', function() {
        $(this).closest('.ltgdai-mapping-row').addClass('ltgdai-focused');
    });
    
    $('.ltgdai-gd-select').on('blur', function() {
        $(this).closest('.ltgdai-mapping-row').removeClass('ltgdai-focused');
    });
    
    // הוספת CSS עבור פוקוס
    if (!$('#ltgdai-focus-style').length) {
        $('<style id="ltgdai-focus-style">')
            .text('.ltgdai-focused { box-shadow: 0 0 5px rgba(0, 115, 170, 0.3); }')
            .appendTo('head');
    }
    
    /**
     * הוספת פונקציונליות נוספת למחיקה
     */
    
    // הוספת כפתור בחירת הכל / ביטול הכל
    function addSelectAllButton() {
        if ($('#ltgdai-select-all-btn').length === 0) {
            var selectAllBtn = '<button type="button" id="ltgdai-select-all-btn" class="button" style="margin-left: 10px;">בחר הכל</button>';
            $('.ltgdai-filter-bar').prepend(selectAllBtn);
        }
    }
    
    // הוספת הכפתור
    addSelectAllButton();
    
    // פונקציונליות בחירת הכל / ביטול הכל
    $(document).on('click', '#ltgdai-select-all-btn', function() {
        var allChecked = $('.ltgdai-field-selected input[type="checkbox"]:not(:checked)').length === 0;
        
        if (allChecked) {
            // אם הכל מסומן, בטל הכל
            $('.ltgdai-field-selected input[type="checkbox"]').prop('checked', false).trigger('change');
            $(this).text('בחר הכל');
        } else {
            // אם לא הכל מסומן, בחר הכל
            $('.ltgdai-field-selected input[type="checkbox"]').prop('checked', true).trigger('change');
            $(this).text('בטל הכל');
        }
    });
    
    // עדכון טקסט הכפתור לפי המצב
    $(document).on('change', '.ltgdai-field-selected input[type="checkbox"]', function() {
        var allChecked = $('.ltgdai-field-selected input[type="checkbox"]:not(:checked)').length === 0;
        var anyChecked = $('.ltgdai-field-selected input[type="checkbox"]:checked').length > 0;
        
        if (allChecked && anyChecked) {
            $('#ltgdai-select-all-btn').text('בטל הכל');
        } else {
            $('#ltgdai-select-all-btn').text('בחר הכל');
        }
    });
    
    /**
     * הוספת שדה מותאם אישית - גרסה מתוקנת לחלוטין
     */
    $('#ltgdai-add-custom-field').on('click', function() {
        customFieldCounter++; // העלאת המונה
        
        // קבלת ה-URL key מהשדות הקיימים
        var urlKey = getUrlKeyFromExistingField();
        
        if (!urlKey) {
            alert('שגיאה: לא ניתן לזהות את מפתח ה-URL');
            return;
        }
        
        // יצירת רשימת אפשרויות GD
        var gdOptions = '<option value="">בחר שדה</option>';
        $('.ltgdai-gd-select').first().find('option:not(:first)').each(function() {
            gdOptions += '<option value="' + $(this).val() + '">' + $(this).text() + '</option>';
        });
        
        // יצירת השדה החדש
        var newCustomField = `
            <div class="ltgdai-mapping-row ltgdai-custom-field-row">
                <div class="ltgdai-field-selected">
                    <input type="checkbox" 
                           name="ltgdai_field_selected[${urlKey}][custom_field_${customFieldCounter}]" 
                           value="1">
                </div>
                <div class="ltgdai-sample">
                    <input type="text" 
                           name="ltgdai_custom_field_name[${urlKey}][]" 
                           placeholder="הזן שם שדה מותאם..." 
                           class="ltgdai-custom-field-input">
                </div>
                <div class="ltgdai-gd-field">
                    <select name="ltgdai_field_mapping[${urlKey}][custom_field_${customFieldCounter}]" 
                            class="ltgdai-gd-select">
                        ${gdOptions}
                    </select>
                </div>
            </div>
        `;
        
        // הוספה לקונטיינר השדות המותאמים
        $('#ltgdai-custom-fields-container').append(newCustomField);
        
        // פוקוס על השדה החדש
        $('#ltgdai-custom-fields-container .ltgdai-custom-field-input:last').focus();
    });

    /**
     * פונקציה לקבלת URL key מהשדות הקיימים
     */
    function getUrlKeyFromExistingField() {
        var urlKey = '';
        
        // חיפוש בשדות הקיימים
        var existingField = $('.ltgdai-mapping-row').first().find('input[name*="ltgdai_field_selected["]');
        if (existingField.length > 0) {
            var name = existingField.attr('name');
            var match = name.match(/ltgdai_field_selected\[([^\]]+)\]/);
            if (match) {
                urlKey = match[1];
            }
        }
        
        // אם לא מצאנו, נסה מה-URL mapping
        if (!urlKey) {
            var firstUrl = $('input[name="ltgdai_url_mapping[]"]').first().val();
            if (firstUrl) {
                urlKey = simpleMD5(firstUrl);
            }
        }
        
        return urlKey || 'default';
    }

    /**
     * טיפול בכפתור ההזנה החדש
     */
    $(document).on('click', '#ltgdai-start-import', function(e) {
        e.preventDefault();
        
        // בדיקה שיש מיפוי
        var anyMapped = false;
        $('.ltgdai-gd-select').each(function() {
            if ($(this).val() !== '') {
                anyMapped = true;
                return false;
            }
        });
        
        if (!anyMapped) {
            alert('נא לבחור לפחות שדה GD אחד למיפוי לפני ההזנה');
            return;
        }
        
        if (confirm('האם אתה בטוח שברצונך להתחיל בהזנת הנתונים? התהליך עלול לארך מספר דקות.')) {
            // קודם שמור את המיפוי, ואז התחל הזנה
            saveMappingThenImport();
        }
    });

    /**
     * שמירת מיפוי ולאחר מכן הזנה
     */
    function saveMappingThenImport() {
        // שמירת המיפוי תחילה
        var formData = $('#ltgdai-mapping-form').serialize();
        formData += '&ltgdai_ready_for_import=1';
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            success: function(response) {
                // לאחר שמירה מוצלחת, התחל הזנה
                startImportProcess();
            },
            error: function() {
                alert('שגיאה בשמירת המיפוי. נסה שוב.');
            }
        });
    }

    /**
     * התחלת תהליך ההזנה
     */
    function startImportProcess() {
        var currentTab = $('input[name="ltgdai_current_tab"]').val();
        
        if (!currentTab) {
            alert('שגיאה: לא נמצא סוג פוסט נוכחי');
            return;
        }
        
        // הצגת אינדיקטור טעינה
        showImportProgress();
        
        // בדיקה אם יש ltgdai_ajax object
        var ajaxUrl = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.ajaxurl : ajaxurl;
        var nonce = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.nonce : '';
        
        // שליחת בקשת AJAX להתחלת ההזנה
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ltgdai_start_import',
                post_type: currentTab,
                nonce: nonce
            },
            success: function(response) {
                hideImportProgress();
                
                if (response.success) {
                    showImportResults(response.data);
                } else {
                    showImportError(response.data);
                }
            },
            error: function(xhr, status, error) {
                hideImportProgress();
                alert('שגיאה בתקשורת עם השרת: ' + error);
            }
        });
    }

    /**
     * הצגת אינדיקטור התקדמות
     */
    function showImportProgress() {
        // הסר כל מודל קיים
        $('#ltgdai-import-modal').remove();
        
        var progressModal = `
            <div id="ltgdai-import-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                direction: rtl;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    text-align: center;
                    min-width: 300px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="
                        width: 40px;
                        height: 40px;
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #0073aa;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 20px;
                    "></div>
                    <h3 style="margin: 0 0 10px; color: #333;">מבצע הזנה...</h3>
                    <p style="margin: 0; color: #666;">אנא המתן, התהליך עלול לארך מספר דקות</p>
                </div>
            </div>
        `;
        
        $('body').append(progressModal);
        
        // הוספת אנימציה
        if (!$('#ltgdai-spinner-style').length) {
            $('head').append(`
                <style id="ltgdai-spinner-style">
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `);
        }
    }

    /**
     * הסתרת אינדיקטור התקדמות
     */
    function hideImportProgress() {
        $('#ltgdai-import-modal').remove();
    }

    /**
     * הצגת תוצאות ההזנה
     */
    function showImportResults(results) {
        var resultsHtml = `
            <div id="ltgdai-results-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                direction: rtl;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 600px;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="
                            width: 60px;
                            height: 60px;
                            background: #46b450;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0 auto 15px;
                            color: white;
                            font-size: 30px;
                        ">✓</div>
                        <h2 style="color: #46b450; margin: 0;">ההזנה הושלמה בהצלחה!</h2>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 10px;">סיכום תוצאות:</h3>
                        <p><strong>סה"כ עובדו:</strong> ${results.total_processed || 0} שורות</p>
                        <p><strong>נוצרו בהצלחה:</strong> ${results.total_created || 0} פוסטים</p>
                        <p><strong>נכשלו:</strong> ${results.total_failed || 0} שורות</p>
                    </div>
        `;
        
        // הצגת פוסטים שנוצרו
        if (results.created_posts && results.created_posts.length > 0) {
            resultsHtml += `
                <div style="margin-bottom: 20px;">
                    <h3>פוסטים שנוצרו:</h3>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
            `;
            
            results.created_posts.forEach(function(post) {
                resultsHtml += `
                    <div style="padding: 8px; border-bottom: 1px solid #eee;">
                        <strong><a href="${post.url || '#'}" target="_blank">${post.title || 'ללא כותרת'}</a></strong>
                        <small style="color: #666; display: block;">ID: ${post.id}</small>
                    </div>
                `;
            });
            
            resultsHtml += `
                    </div>
                </div>
            `;
        }
        
        // הצגת שגיאות אם יש
        if (results.errors && results.errors.length > 0) {
            resultsHtml += `
                <div style="margin-bottom: 20px;">
                    <h3 style="color: #e53935;">שגיאות:</h3>
                    <div style="max-height: 150px; overflow-y: auto; background: #ffebee; border: 1px solid #ffcdd2; padding: 10px;">
            `;
            
            results.errors.forEach(function(error) {
                resultsHtml += `<div style="margin-bottom: 5px;">• ${error}</div>`;
            });
            
            resultsHtml += `
                    </div>
                </div>
            `;
        }
        
        resultsHtml += `
                    <div style="text-align: center;">
                        <button onclick="closeResultsModal()" style="
                            background: #0073aa;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 14px;
                        ">סגור</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(resultsHtml);
    }

    /**
     * הצגת שגיאת הזנה
     */
    function showImportError(errorData) {
        var errorHtml = `
            <div id="ltgdai-error-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                direction: rtl;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 500px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="
                            width: 60px;
                            height: 60px;
                            background: #e53935;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0 auto 15px;
                            color: white;
                            font-size: 30px;
                        ">✗</div>
                        <h2 style="color: #e53935; margin: 0;">שגיאה בהזנה</h2>
                    </div>
                    
                    <div style="background: #ffebee; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <p style="margin: 0;">${errorData.message || 'שגיאה לא ידועה'}</p>
                    </div>
                    
                    <div style="text-align: center;">
                        <button onclick="closeErrorModal()" style="
                            background: #e53935;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 14px;
                        ">סגור</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(errorHtml);
    }

    /**
     * סגירת מודל התוצאות
     */
    window.closeResultsModal = function() {
        $('#ltgdai-results-modal').remove();
        // רענון הדף כדי להציג את הפוסטים החדשים
        setTimeout(function() {
            if (confirm('האם ברצונך לרענן את הדף כדי לראות את השינויים?')) {
                window.location.reload();
            }
        }, 100);
    };

   

    /**
     * סגירת מודל השגיאה
     */
    window.closeErrorModal = function() {
        $('#ltgdai-error-modal').remove();
    };
    
    // פונקציה פשוטה ליצירת MD5 כמו ב-PHP
    function simpleMD5(str) {
        var hash = 0;
        if (str.length === 0) return hash.toString();
        for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return Math.abs(hash).toString();
    }
    
    // הוספת ספריית MD5 אם לא קיימת (fallback פשוט)
    if (typeof CryptoJS === 'undefined') {
        window.CryptoJS = {
            MD5: function(string) {
                return {
                    toString: function() {
                        return simpleMD5(string);
                    }
                };
            }
        };
    }
});