/**
 * סקריפטים עבור עמוד המיפוי של פלאגין ListToGD - גרסה מתוקנת עם כפתור סיום יחיד
 */
jQuery(document).ready(function($) {
    // משתנים גלובליים
    var customFieldCounter = $('#ltgdai-custom-fields-container .ltgdai-custom-field-row').length;
    
    // משתנים לעיבוד חלק
    var batchImportActive = false;
    var currentPostType = '';
    var batchStats = {
        totalProcessed: 0,
        totalCreated: 0,
        totalFailed: 0,
        totalFiles: 0
    };
    var processStartTime = null;
    var estimatedTotalTime = null;
    var totalItemsToProcess = 0;
    
    // משתנה גלובלי לאיסוף נתוני הדוח במהלך התהליך הנוכחי בלבד!
    var currentSessionReportData = {
        posts: [],
        summary: {
            total_processed: 0,
            successful_posts: 0,
            failed_posts: 0,
            total_original_files: 0,
            successful_files: 0,
            failed_files: 0,
            process_start_time: null,
            process_end_time: null
        },
        session_id: null // מזהה ייחודי לסשן הנוכחי
    };
    
    /**
     * עדכון מראה שורה כאשר משתנה הבחירה של שדה GD
     */
    $(document).on('change', '.ltgdai-gd-select', function() {
        var $select = $(this);
        var $row = $select.closest('.ltgdai-mapping-row');
        var $checkbox = $row.find('input[type="checkbox"]');
        
        if ($select.val() !== '') {
            $row.addClass('ltgdai-mapped-row');
        } else {
            $row.removeClass('ltgdai-mapped-row');
            $checkbox.prop('checked', false);
        }
    });
    
    /**
     * סימון/ביטול סימון כאשר לוחצים על צ'קבוקס
     */
    $(document).on('change', '.ltgdai-field-selected input[type="checkbox"]:not(:disabled)', function() {
        var $checkbox = $(this);
        var $row = $checkbox.closest('.ltgdai-mapping-row');
        var $select = $row.find('.ltgdai-gd-select');
        
        if ($checkbox.prop('checked')) {
            if ($select.val() !== '') {
                $row.addClass('ltgdai-mapped-row');
            }
        } else {
            $row.removeClass('ltgdai-mapped-row');
        }
    });
    
    /**
     * כפתור ביצוע פעולה נבחרת
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
     * בדיקת תקינות טופס לפני שמירה
     */
    $('#ltgdai-mapping-form').on('submit', function(e) {
        if ($('button[name="ltgdai_save_mapping"]').is(':focus') || $('button[name="ltgdai_ready_for_import"]').is(':focus')) {
            var anyMapped = false;
            var hasCustomFields = false;
            
            $('.ltgdai-gd-select').each(function() {
                if ($(this).val() !== '') {
                    anyMapped = true;
                    return false;
                }
            });
            
            $('.ltgdai-custom-field-input').each(function() {
                if ($(this).val().trim() !== '') {
                    hasCustomFields = true;
                    anyMapped = true;
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
     * אפקטים ויזואליים
     */
    $(document).on('mouseenter', '.ltgdai-mapping-row', function() {
        $(this).css('transform', 'translateX(-2px)');
    });
    
    $(document).on('mouseleave', '.ltgdai-mapping-row', function() {
        $(this).css('transform', 'translateX(0)');
    });
    
    /**
     * סנכרון מראה עם מצב טעינת הדף
     */
    function initializeMappingRows() {
        $('.ltgdai-mapping-row').each(function() {
            var $row = $(this);
            var $select = $row.find('.ltgdai-gd-select');
            
            if ($select.val() !== '') {
                $row.addClass('ltgdai-mapped-row');
            } else {
                $row.removeClass('ltgdai-mapped-row');
            }
        });
    }
    
    initializeMappingRows();
    
    /**
     * מקשי קיצור
     */
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            $('button[name="ltgdai_ready_for_import"]').click();
        }
    });
    
    /**
     * שיפור נגישות
     */
    $('.ltgdai-gd-select').on('focus', function() {
        $(this).closest('.ltgdai-mapping-row').addClass('ltgdai-focused');
    });
    
    $('.ltgdai-gd-select').on('blur', function() {
        $(this).closest('.ltgdai-mapping-row').removeClass('ltgdai-focused');
    });
    
    if (!$('#ltgdai-focus-style').length) {
        $('<style id="ltgdai-focus-style">')
            .text('.ltgdai-focused { box-shadow: 0 0 5px rgba(0, 115, 170, 0.3); }')
            .appendTo('head');
    }
    
    /**
     * הוספת כפתור בחירת הכל / ביטול הכל
     */
    function addSelectAllButton() {
        if ($('#ltgdai-select-all-btn').length === 0) {
            var selectAllBtn = '<button type="button" id="ltgdai-select-all-btn" class="button" style="margin-left: 10px;">בחר הכל</button>';
            $('.ltgdai-filter-bar').prepend(selectAllBtn);
        }
    }
    
    addSelectAllButton();
    
    $(document).on('click', '#ltgdai-select-all-btn', function() {
        var allChecked = $('.ltgdai-field-selected input[type="checkbox"]:not(:checked)').length === 0;
        
        if (allChecked) {
            $('.ltgdai-field-selected input[type="checkbox"]').prop('checked', false).trigger('change');
            $(this).text('בחר הכל');
        } else {
            $('.ltgdai-field-selected input[type="checkbox"]').prop('checked', true).trigger('change');
            $(this).text('בטל הכל');
        }
    });
    
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
     * הוספת שדה מותאם אישית
     */
    $('#ltgdai-add-custom-field').on('click', function() {
        customFieldCounter++;
        
        var urlKey = getUrlKeyFromExistingField();
        
        if (!urlKey) {
            alert('שגיאה: לא ניתן לזהות את מפתח ה-URL');
            return;
        }
        
        var gdOptions = '<option value="">בחר שדה</option>';
        $('.ltgdai-gd-select').first().find('option:not(:first)').each(function() {
            gdOptions += '<option value="' + $(this).val() + '">' + $(this).text() + '</option>';
        });
        
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
        
        $('#ltgdai-custom-fields-container').append(newCustomField);
        $('#ltgdai-custom-fields-container .ltgdai-custom-field-input:last').focus();
    });

    function getUrlKeyFromExistingField() {
        var urlKey = '';
        
        var existingField = $('.ltgdai-mapping-row').first().find('input[name*="ltgdai_field_selected["]');
        if (existingField.length > 0) {
            var name = existingField.attr('name');
            var match = name.match(/ltgdai_field_selected\[([^\]]+)\]/);
            if (match) {
                urlKey = match[1];
            }
        }
        
        if (!urlKey) {
            var firstUrl = $('input[name="ltgdai_url_mapping[]"]').first().val();
            if (firstUrl) {
                urlKey = simpleMD5(firstUrl);
            }
        }
        
        return urlKey || 'default';
    }

    // ===========================================
    // פונקציות דוח מעודכנות - רק מהסשן הנוכחי!
    // ===========================================
    
    /**
     * אתחול נתוני הדוח בתחילת התהליך - עם מזהה סשן ייחודי
     */
    function initializeReportData() {
        // יצירת מזהה ייחודי לסשן הזה
        var sessionId = 'import_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        currentSessionReportData = {
            posts: [],
            summary: {
                total_processed: 0,
                successful_posts: 0,
                failed_posts: 0,
                total_original_files: 0,
                successful_files: 0,
                failed_files: 0,
                process_start_time: new Date().toISOString(),
                process_end_time: null
            },
            session_id: sessionId
        };
        
        console.log('החל סשן יבוא חדש:', sessionId);
    }

    /**
     * פונקציה לעדכון נתוני הדוח במהלך התהליך - רק מה שקורה עכשיו!
     */
    function updateReportData(batchResult) {
        console.log('עדכון נתוני דוח לסשן נוכחי:', batchResult);
        
        if (batchResult.created_posts && batchResult.created_posts.length > 0) {
            batchResult.created_posts.forEach(function(post) {
                // הוספת פוסט חדש לסשן הנוכחי
                currentSessionReportData.posts.push({
                    post_id: post.id,
                    title: post.title,
                    creation_status: 'נוצר בהצלחה',
                    post_url: post.post_url || (window.location.origin + '/wp-admin/post.php?post=' + post.id + '&action=edit'),
                    original_files_count: 0, // יתמלא מהשרת
                    uploaded_files_count: post.files_imported || 0,
                    failed_files_count: 0, // יתמלא אם יש כשלים
                    original_files: [],
                    uploaded_files: [],
                    failed_files: [],
                    notes: 'נוצר בסשן הנוכחי',
                    created_at: new Date().toLocaleString('he-IL'),
                    session_id: currentSessionReportData.session_id // חשוב!
                });
            });
        }
        
        // עדכון סיכום הסשן הנוכחי
        currentSessionReportData.summary.total_processed += (batchResult.processed_in_batch || 0);
        currentSessionReportData.summary.successful_posts += (batchResult.created_in_batch || 0);
        currentSessionReportData.summary.failed_posts += (batchResult.failed_in_batch || 0);
        currentSessionReportData.summary.successful_files += (batchResult.files_processed_in_batch || 0);
        
        console.log('נתוני סשן נוכחי עודכנו. סה"כ פוסטים:', currentSessionReportData.posts.length);
    }

    /**
     * סיום איסוף נתוני הדוח לסשן הנוכחי
     */
    function finalizeReportData() {
        currentSessionReportData.summary.process_end_time = new Date().toISOString();
        
        var startTime = new Date(currentSessionReportData.summary.process_start_time);
        var endTime = new Date(currentSessionReportData.summary.process_end_time);
        var duration = Math.round((endTime - startTime) / 1000);
        
        var minutes = Math.floor(duration / 60);
        var seconds = duration % 60;
        
        currentSessionReportData.summary.process_duration = minutes > 0 ? 
            `${minutes} דקות ו-${seconds} שניות` : 
            `${seconds} שניות`;
            
        console.log('סשן הושלם. נתונים סופיים:', currentSessionReportData);
    }

    /**
     * פונקציה מעודכנת להורדת דוח - רק מהסשן הנוכחי!
     */
    window.downloadDetailedReport = function() {
        console.log('מוריד דוח לסשן הנוכחי בלבד...');
        
        // בדיקה שיש נתונים מהסשן הנוכחי
        if (!currentSessionReportData.posts || currentSessionReportData.posts.length === 0) {
            alert('אין נתונים מהסשן הנוכחי. ייתכן שהתהליך לא הושלם או שלא נוצרו פוסטים.');
            return;
        }
        
        console.log('יוצר דוח עבור ' + currentSessionReportData.posts.length + ' פוסטים מהסשן הנוכחי');
        
        // הצגת הודעת טעינה
        var originalText = $('button[onclick="downloadDetailedReport()"]').text();
        $('button[onclick="downloadDetailedReport()"]').text('מוריד דוח...').prop('disabled', true);
        
        // שליחת הנתונים לשרת להשלמת פרטים נוספים (קבצים שנכשלו וכו')
        $.ajax({
            url: (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.ajaxurl : ajaxurl,
            type: 'POST',
            data: {
                action: 'ltgdai_enrich_session_report',
                post_type: currentPostType,
                session_data: JSON.stringify(currentSessionReportData),
                nonce: (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.nonce : ''
            },
            success: function(response) {
                // החזרת הכפתור למצב רגיל
                $('button[onclick="downloadDetailedReport()"]').text(originalText).prop('disabled', false);
                
                if (response.success && response.data.enriched_report) {
                    console.log('נתונים הועשרו מהשרת:', response.data.enriched_report);
                    createExcelFromSessionData(response.data.enriched_report);
                } else {
                    // אם השרת לא עובד, נשתמש בנתונים המקומיים
                    console.log('השרת לא זמין, משתמש בנתונים מקומיים');
                    createExcelFromSessionData(currentSessionReportData);
                }
            },
            error: function() {
                // החזרת הכפתור למצב רגיל
                $('button[onclick="downloadDetailedReport()"]').text(originalText).prop('disabled', false);
                
                // אם יש שגיאה, נשתמש בנתונים המקומיים
                console.log('שגיאה בשרת, משתמש בנתונים מקומיים');
                createExcelFromSessionData(currentSessionReportData);
            }
        });
    };

    /**
     * יצירת קובץ Excel מנתוני הסשן הנוכחי בלבד
     */
    function createExcelFromSessionData(sessionData) {
        console.log('יוצר Excel מנתוני סשן:', sessionData);
        
        // יצירת תוכן CSV
        var csvContent = '';
        
        // כותרת הדוח
        csvContent += `דוח יבוא - ${sessionData.session_id}\n`;
        csvContent += `תאריך: ${new Date().toLocaleString('he-IL')}\n`;
        csvContent += `סוג פוסט: ${currentPostType}\n`;
        csvContent += `מספר פוסטים בסשן: ${sessionData.posts.length}\n\n`;
        
        // כותרות העמודות
        var headers = [
            'מס',
            'מספר פוסט',
            'כותרת הפוסט', 
            'סטטוס יצירה',
            'קישור לעריכה',
            'קבצים שהועלו',
            'קבצים שנכשלו',
            'הערות',
            'זמן יצירה'
        ];
        
        csvContent += headers.join(',') + '\n';
        
        // הוספת הנתונים מהסשן הנוכחי
        sessionData.posts.forEach(function(post, index) {
            var editUrl = `${window.location.origin}/wp-admin/post.php?post=${post.post_id}&action=edit`;
            
            var row = [
                index + 1,
                post.post_id || 'לא נוצר',
                '"' + (post.title || 'ללא כותרת').replace(/"/g, '""') + '"',
                post.creation_status || 'נוצר בסשן הנוכחי',
                editUrl,
                post.uploaded_files_count || 0,
                post.failed_files_count || 0,
                '"' + (post.notes || 'נוצר בהזנה הנוכחית').replace(/"/g, '""') + '"',
                post.created_at || new Date().toLocaleString('he-IL')
            ];
            
            csvContent += row.join(',') + '\n';
        });
        
        // הוספת סיכום
        csvContent += '\n--- סיכום הסשן הנוכחי ---\n';
        csvContent += `זמן התחלה,${sessionData.summary.process_start_time}\n`;
        csvContent += `זמן סיום,${sessionData.summary.process_end_time || 'בתהליך'}\n`;
        csvContent += `משך התהליך,${sessionData.summary.process_duration || 'בתהליך'}\n`;
        csvContent += `סה"כ פוסטים שנוצרו,${sessionData.posts.length}\n`;
        csvContent += `פוסטים שהצליחו,${sessionData.summary.successful_posts}\n`;
        csvContent += `פוסטים שנכשלו,${sessionData.summary.failed_posts}\n`;
        csvContent += `סה"כ קבצים שהועלו,${sessionData.summary.successful_files}\n`;
        
        // הורדת הקובץ עם שם ייחודי לסשן
        var timestamp = new Date().toISOString().slice(0,16).replace('T', '_').replace(/:/g, '-');
        var fileName = `דוח_${currentPostType}_${timestamp}.csv`;
        
        downloadCSV(csvContent, fileName);
        
        console.log('דוח הסשן הנוכחי הורד:', fileName);
    }

    // ===========================================
    // מערכת עיבוד חלקה - החלק החשוב! (מתוקן)
    // ===========================================

    /**
     * כפתור התחלת הזנה עם מערכת חלקה
     */
    $(document).on('click', '#ltgdai-start-import', function(e) {
        e.preventDefault();
        
        console.log('כפתור הזנה נלחץ!');
        
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
        
        if (confirm('האם אתה בטוח שברצונך להתחיל בהזנת הנתונים? התהליך יכול להימשך מספר דקות.')) {
            console.log('משתמש אישר - מתחיל הזנה');
            saveMappingThenStartSmoothImport();
        }
    });

    /**
     * שמירת מיפוי ולאחר מכן התחלת עיבוד חלק
     */
    function saveMappingThenStartSmoothImport() {
        console.log('שומר מיפוי לפני הזנה...');
        
        var formData = $('#ltgdai-mapping-form').serialize();
        formData += '&ltgdai_ready_for_import=1';
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('מיפוי נשמר - מתחיל הזנה');
                startSmoothImportProcess();
            },
            error: function() {
                alert('שגיאה בשמירת המיפוי. נסה שוב.');
            }
        });
    }

    /**
     * התחלת תהליך עיבוד חלק
     */
    function startSmoothImportProcess() {
        console.log('מתחיל תהליך הזנה חלק');
        
        currentPostType = $('input[name="ltgdai_current_tab"]').val();
        
        if (!currentPostType) {
            alert('שגיאה: לא נמצא סוג פוסט נוכחי');
            return;
        }
        
        // אתחול נתוני הדוח לסשן הנוכחי
        initializeReportData();
        
        // איפוס סטטיסטיקות
        batchStats = {
            totalProcessed: 0,
            totalCreated: 0,
            totalFailed: 0,
            totalFiles: 0
        };
        
        // התחלת זמן העיבוד
        processStartTime = Date.now();
        
        // הצגת מודל התקדמות חלק
        showSmoothProgressModal();
        
        // התחלת העיבוד הראשון
        batchImportActive = true;
        startFirstProcessing();
    }

    /**
     * התחלת העיבוד הראשון
     */
    function startFirstProcessing() {
        console.log('שולח בקשה ראשונה לשרת');
        
        var ajaxUrl = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.ajaxurl : ajaxurl;
        var nonce = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.nonce : '';
        
        console.log('Ajax URL:', ajaxUrl);
        console.log('Nonce:', nonce);
        console.log('Post Type:', currentPostType);
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ltgdai_start_batch_import',
                post_type: currentPostType,
                nonce: nonce
            },
            success: function(response) {
                console.log('תשובה מהשרת:', response);
                if (response.success) {
                    handleSmoothResult(response.data);
                } else {
                    hideSmoothProgressModal();
                    showSmoothError(response.data);
                    batchImportActive = false;
                }
            },
            error: function(xhr, status, error) {
                console.log('שגיאה בבקשה:', xhr, status, error);
                hideSmoothProgressModal();
                alert('שגיאה בתקשורת עם השרת: ' + error);
                batchImportActive = false;
            }
        });
    }

    /**
     * המשך לעיבוד הבא
     */
    function continueNextProcessing() {
        if (!batchImportActive) {
            return;
        }
        
        var ajaxUrl = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.ajaxurl : ajaxurl;
        var nonce = (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.nonce : '';
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ltgdai_continue_batch_import',
                post_type: currentPostType,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    handleSmoothResult(response.data);
                } else {
                    hideSmoothProgressModal();
                    showSmoothError(response.data);
                    batchImportActive = false;
                }
            },
            error: function(xhr, status, error) {
                hideSmoothProgressModal();
                alert('שגיאה בהמשך העיבוד: ' + error);
                batchImportActive = false;
            }
        });
    }

    /**
     * טיפול בתוצאת עיבוד - חישוב זמן חכם
     */
    function handleSmoothResult(data) {
        console.log('טיפול בתוצאה:', data);
        
        // עדכון נתוני הדוח עם התוצאות החדשות
        updateReportData(data);
        
        // עדכון סטטיסטיקות כלליות
        batchStats.totalProcessed += data.processed_in_batch || 0;
        batchStats.totalCreated += data.created_in_batch || 0;
        batchStats.totalFailed += data.failed_in_batch || 0;
        batchStats.totalFiles += data.files_processed_in_batch || 0;
        
        // חישוב זמן משוער בפעם הראשונה
        if (!estimatedTotalTime && data.total_batches && data.current_batch > 1) {
            var currentTime = Date.now();
            var elapsedTime = currentTime - processStartTime;
            var avgTimePerBatch = elapsedTime / (data.current_batch - 1);
            estimatedTotalTime = avgTimePerBatch * data.total_batches;
            totalItemsToProcess = data.total_batches * 5; // בערך 5 פריטים לקבוצה
        }
        
        // עדכון המודל עם חישובי זמן
        updateSmoothProgressModal(data);
        
        if (data.completed) {
            // התהליך הושלם
            batchImportActive = false;
            finalizeReportData(); // סיום איסוף נתוני הדוח
            setTimeout(function() {
                hideSmoothProgressModal();
                showSmoothCompleted(data);
            }, 1000);
        } else {
            // המשך לעיבוד הבא עם השהיה קצרה
            setTimeout(function() {
                continueNextProcessing();
            }, 1000);
        }
    }

    /**
     * הצגת מודל התקדמות חלק
     */
    function showSmoothProgressModal() {
        $('#ltgdai-smooth-modal').remove();
        
        var progressModal = `
            <div id="ltgdai-smooth-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                direction: rtl;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    text-align: center;
                    min-width: 400px;
                    max-width: 600px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
                ">
                    <h2 style="margin: 0 0 20px; color: #0073aa;">מבצע הזנה </h2>
                    
                    <!-- פס התקדמות -->
                    <div style="
                        width: 100%;
                        height: 20px;
                        background: #f0f0f0;
                        border-radius: 10px;
                        margin: 20px 0;
                        overflow: hidden;
                    ">
                        <div id="ltgdai-smooth-progress-bar" style="
                            height: 100%;
                            background: linear-gradient(90deg, #0073aa, #00a0d2);
                            width: 0%;
                            transition: width 1s ease;
                            border-radius: 10px;
                        "></div>
                    </div>
                    
                    <div id="ltgdai-smooth-progress-text" style="margin: 10px 0; font-weight: bold; color: #333;">
                        מתחיל עיבוד...
                    </div>
                    
                    <!-- מידע מפורט -->
                    <div style="
                        background: #f9f9f9;
                        padding: 15px;
                        border-radius: 8px;
                        margin: 20px 0;
                        text-align: right;
                    ">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <strong>זמן משוער:</strong>
                                <span id="ltgdai-estimated-time">מחשב...</span>
                            </div>
                            <div>
                                <strong>התקדמות כללית:</strong>
                                <span id="ltgdai-smooth-progress-percent">0%</span>
                            </div>
                            <div>
                                <strong>פוסטים נוצרו:</strong>
                                <span id="ltgdai-smooth-total-created">0</span>
                            </div>
                            <div>
                                <strong>קבצים הועלו:</strong>
                                <span id="ltgdai-smooth-total-files">0</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- רשימת פוסטים שנוצרו -->
                    <div id="ltgdai-smooth-recent-posts" style="
                        max-height: 150px;
                        overflow-y: auto;
                        background: #f5f5f5;
                        padding: 10px;
                        border-radius: 6px;
                        margin: 15px 0;
                        text-align: right;
                        display: none;
                    ">
                        <strong>פוסטים שנוצרו לאחרונה:</strong>
                        <div id="ltgdai-smooth-posts-list"></div>
                    </div>
              
                </div>
            </div>
        `;
        
        $('body').append(progressModal);
    }

    /**
     * עדכון מודל ההתקדמות עם חישובי זמן חכמים
     */
    function updateSmoothProgressModal(data) {
        // עדכון אחוזי התקדמות
        var progress = data.progress || 0;
        $('#ltgdai-smooth-progress-percent').text(progress + '%');
        $('#ltgdai-smooth-progress-bar').css('width', progress + '%');
        
        // עדכון סטטיסטיקות
        $('#ltgdai-smooth-total-created').text(batchStats.totalCreated);
        $('#ltgdai-smooth-total-files').text(batchStats.totalFiles);
        
        // חישוב וטקסט זמן משוער
        var timeText = calculateTimeEstimate(data);
        $('#ltgdai-estimated-time').text(timeText);
        
        // עדכון טקסט התקדמות
        if (progress < 20) {
            $('#ltgdai-smooth-progress-text').text('מתחיל עיבוד הנתונים...');
        } else if (progress < 50) {
            $('#ltgdai-smooth-progress-text').text('מעבד נתונים ומעלה קבצים...');
        } else if (progress < 80) {
            $('#ltgdai-smooth-progress-text').text('ממשיך ליצור פוסטים...');
        } else if (progress < 95) {
            $('#ltgdai-smooth-progress-text').text('כמעט סיים...');
        } else {
            $('#ltgdai-smooth-progress-text').text('משלים תהליך...');
        }
        
        // הוספת פוסטים שנוצרו לרשימה
        if (data.created_posts && data.created_posts.length > 0) {
            $('#ltgdai-smooth-recent-posts').show();
            var postsList = $('#ltgdai-smooth-posts-list');
            
            data.created_posts.forEach(function(post) {
                var postItem = `
                    <div style="padding: 5px; border-bottom: 1px solid #ddd; font-size: 12px;">
                        <strong>${post.title}</strong>
                        ${post.files_imported > 0 ? ' (+ ' + post.files_imported + ' קבצים)' : ''}
                    </div>
                `;
                postsList.append(postItem);
            });
            
            // גלילה למטה
            $('#ltgdai-smooth-recent-posts').scrollTop($('#ltgdai-smooth-recent-posts')[0].scrollHeight);
        }
    }

    /**
     * חישוב זמן משוער חכם
     */
    function calculateTimeEstimate(data) {
        if (!processStartTime) {
            return 'מחשב...';
        }
        
        var currentTime = Date.now();
        var elapsedTime = currentTime - processStartTime;
        var progress = data.progress || 0;
        
        if (progress < 5) {
            return 'מחשב...';
        }
        
        // חישוב זמן משוער לפי התקדמות נוכחית
        var estimatedTotalMs = (elapsedTime / progress) * 100;
        var remainingMs = estimatedTotalMs - elapsedTime;
        
        if (remainingMs < 0) {
            return 'כמעט סיים';
        }
        
        var remainingMinutes = Math.ceil(remainingMs / 60000);
        
        if (remainingMinutes < 1) {
            return 'פחות מדקה';
        } else if (remainingMinutes === 1) {
            return 'כדקה';
        } else if (remainingMinutes < 5) {
            return remainingMinutes + ' דקות';
        } else {
            return 'כ-' + remainingMinutes + ' דקות';
        }
    }

    /**
     * הסתרת מודל ההתקדמות
     */
    function hideSmoothProgressModal() {
        $('#ltgdai-smooth-modal').remove();
    }

    /**
     * הצגת הודעת השלמה - עם כפתור סיום יחיד שמוחק את הלשונית!
     */
    function showSmoothCompleted(data) {
        var finalStats = data.stats || {};
        
        // חישוב זמן כולל שחלף
        var totalElapsedMs = Date.now() - processStartTime;
        var minutes = Math.floor(totalElapsedMs / 60000);
        var seconds = Math.floor((totalElapsedMs % 60000) / 1000);
        var timeElapsedText = minutes > 0 ? 
            `${minutes} דקות ו-${seconds} שניות` : 
            `${seconds} שניות`;
        
        var completedModal = `
            <div id="ltgdai-smooth-completed-modal" style="
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
                    border-radius: 12px;
                    max-width: 600px;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
                ">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="
                            width: 80px;
                            height: 80px;
                            background: #46b450;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0 auto 15px;
                            color: white;
                            font-size: 40px;
                        ">✓</div>
                        <h2 style="color: #46b450; margin: 0;">הזנה הושלמה בהצלחה!</h2>
                        <p style="color: #666; margin: 10px 0 0;">התהליך לקח ${timeElapsedText}</p>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px;">סיכום סופי:</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="text-align: center; background: white; padding: 10px; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                                    ${finalStats.processed || batchStats.totalProcessed}
                                </div>
                                <div style="color: #666;">פריטים עובדו</div>
                            </div>
                            <div style="text-align: center; background: white; padding: 10px; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: bold; color: #46b450;">
                                    ${finalStats.created || batchStats.totalCreated}
                                </div>
                                <div style="color: #666;">פוסטים נוצרו</div>
                            </div>
                            <div style="text-align: center; background: white; padding: 10px; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: bold; color: #ff6900;">
                                    ${finalStats.files || batchStats.totalFiles}
                                </div>
                                <div style="color: #666;">קבצים הועלו</div>
                            </div>
                            <div style="text-align: center; background: white; padding: 10px; border-radius: 6px;">
                                <div style="font-size: 24px; font-weight: bold; color: ${(finalStats.failed || batchStats.totalFailed) > 0 ? '#e53935' : '#46b450'};">
                                    ${finalStats.failed || batchStats.totalFailed}
                                </div>
                                <div style="color: #666;">כשלים</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- כפתור הורדת דוח -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <button onclick="downloadDetailedReport()" style="
                            background: #0073aa;
                            color: white;
                            border: none;
                            padding: 12px 30px;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 16px;
                            font-weight: bold;
                            margin-right: 10px;
                        ">הורד דוח מפורט</button>
                    </div>
                    
                    <div style="text-align: center;">
                        <button onclick="finishAndDeleteTab()" style="
                            background: #46b450;
                            color: white;
                            border: none;
                            padding: 15px 40px;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 18px;
                            font-weight: bold;
                        ">סיום</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(completedModal);
    }

    /**
     * הצגת שגיאת עיבוד
     */
    function showSmoothError(errorData) {
        var errorModal = `
            <div id="ltgdai-smooth-error-modal" style="
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
                        <h2 style="color: #e53935; margin: 0;">שגיאה בעיבוד</h2>
                    </div>
                    
                    <div style="background: #ffebee; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <p style="margin: 0;">${errorData.message || 'שגיאה לא ידועה'}</p>
                    </div>
                    
                    <div style="text-align: center;">
                        <button onclick="closeSmoothErrorModal()" style="
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
        
        $('body').append(errorModal);
    }

    /**
     * פונקציה חדשה לסיום ומחיקת לשונית - זו הפונקציה החשובה!
     */
    window.finishAndDeleteTab = function() {
        // סגירת המודל
        $('#ltgdai-smooth-completed-modal').remove();
        
        // קריאה לשרת למחיקת הלשונית
        $.ajax({
            url: (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.ajaxurl : ajaxurl,
            type: 'POST',
            data: {
                action: 'ltgdai_delete_completed_tab',
                post_type: currentPostType,
                nonce: (typeof ltgdai_ajax !== 'undefined') ? ltgdai_ajax.nonce : ''
            },
            success: function(response) {
                if (response.success) {
                    // מחיקה הצליחה - בדיקה אם יש עוד לשוניות
                    if (response.data.has_remaining_tabs) {
                        // יש עוד לשוניות - מעבר ללשונית הראשונה שנותרה
                        window.location.href = response.data.next_tab_url;
                    } else {
                        // אין עוד לשוניות - חזרה לעמוד היבוא
                        window.location.href = response.data.import_page_url;
                    }
                } else {
                    // שגיאה במחיקה - סתם רענון דף
                    alert('הושלם בהצלחה! מרענן את הדף...');
                    window.location.reload();
                }
            },
            error: function() {
                // שגיאה בתקשורת - סתם רענון דף
                alert('הושלם בהצלחה! מרענן את הדף...');
                window.location.reload();
            }
        });
    };

    /**
     * פונקציות עזר לסגירת מודלים
     */
    window.closeSmoothErrorModal = function() {
        $('#ltgdai-smooth-error-modal').remove();
    };

    // ביטול תהליך אם המשתמש סוגר את הדף
    $(window).on('beforeunload', function() {
        if (batchImportActive) {
            return 'תהליך ההזנה עדיין פעיל. האם אתה בטוח שברצונך לסגור את הדף?';
        }
    });

    // פונקציות עזר כלליות
    function simpleMD5(str) {
        var hash = 0;
        if (str.length === 0) return hash.toString();
        for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString();
    }
    
    // פונקציה לhורדת CSV
    function downloadCSV(content, filename) {
        var blob = new Blob(['\ufeff' + content], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        if (link.download !== undefined) {
            var url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
    
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