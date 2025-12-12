jQuery(document).ready(function($) {
    if (typeof WACB_Wizard_Data === 'undefined') return;

    const $overlay = $('#wacb-wizard-overlay');
    const $specialtyContainer = $('#wacb-specialty-checkboxes');
    
    // 各ステップ
    const $stepIntro = $('#wacb-wizard-step-intro'); // ★追加
    const $stepSpecialty = $('#wacb-wizard-step-specialty');
    const $stepQa = $('#wacb-wizard-step-qa');
    const $stepFinish = $('#wacb-wizard-step-finish');

    const $questionText = $('#wacb-question-text');
    const $answerInput = $('#wacb-answer-input');
    const $currentQ = $('#wacb-current-q');
    const $totalQ = $('#wacb-total-q');
    const $progressFill = $('#wacb-progress-fill');

    let questionQueue = []; 
    let currentIndex = 0;

    // 0. ★追加: 動画画面の「次へ」ボタン
    $('#wacb-btn-intro-next').on('click', function() {
        $stepIntro.hide();
        $stepSpecialty.fadeIn();
    });

    // 0. ★追加: 診療科選択の「戻る」ボタン
    $('#wacb-btn-back-intro').on('click', function() {
        $stepSpecialty.hide();
        $stepIntro.fadeIn();
    });

    // 1. チェックボックスリストの生成
    const specialties = WACB_Wizard_Data.specialties;
    for (const key in specialties) {
        if (specialties.hasOwnProperty(key)) {
            const $label = $('<label class="wacb-checkbox-item"></label>');
            const $input = $('<input type="checkbox" name="wacb_specialties[]">').val(key);
            $label.append($input).append(' ' + specialties[key].label);
            $specialtyContainer.append($label);
        }
    }

    // 2. 「質問を開始する」ボタン
    $('#wacb-btn-start').on('click', function() {
        const selectedKeys = [];
        $('input[name="wacb_specialties[]"]:checked').each(function() {
            selectedKeys.push($(this).val());
        });

        if (selectedKeys.length === 0) {
            alert('少なくとも1つの診療科目を選択してください。');
            return;
        }

        // 質問キューの作成
        questionQueue = [];
        
        // (A) 共通質問
        if (WACB_Wizard_Data.common && WACB_Wizard_Data.common.questions) {
            WACB_Wizard_Data.common.questions.forEach(q => {
                questionQueue.push({ q: q, a: '' });
            });
        }
        
        // (B) 選択された診療科の質問
        selectedKeys.forEach(function(key) {
            if (specialties[key] && specialties[key].questions) {
                specialties[key].questions.forEach(q => {
                    questionQueue.push({ q: q, a: '' });
                });
            }
        });

        // 画面切り替え
        currentIndex = 0;
        updateQuestionDisplay();
        $stepSpecialty.hide();
        $stepQa.fadeIn();
    });

    // 3. 質問画面の更新関数
    function updateQuestionDisplay() {
        const currentData = questionQueue[currentIndex];
        $questionText.text(currentData.q);
        $answerInput.val(currentData.a); 
        $answerInput.focus();

        const currentNum = currentIndex + 1;
        const totalNum = questionQueue.length;
        $currentQ.text(currentNum);
        $totalQ.text(totalNum);
        
        const percent = (currentNum / totalNum) * 100;
        $progressFill.css('width', percent + '%');
    }

    // 4. 「次へ」ボタン
    $('#wacb-btn-next').on('click', function() {
        questionQueue[currentIndex].a = $answerInput.val().trim();
        if (currentIndex < questionQueue.length - 1) {
            currentIndex++;
            updateQuestionDisplay();
        } else {
            $stepQa.hide();
            $stepFinish.fadeIn();
        }
    });

    // 5. 「前へ」ボタン
    $('#wacb-btn-prev').on('click', function() {
        questionQueue[currentIndex].a = $answerInput.val().trim();
        if (currentIndex > 0) {
            currentIndex--;
            updateQuestionDisplay();
        } else {
            $stepQa.hide();
            $stepSpecialty.fadeIn();
        }
    });

    // 6. 「戻って確認」ボタン
    $('#wacb-btn-back-review').on('click', function() {
        $stepFinish.hide();
        $stepQa.fadeIn();
        currentIndex = questionQueue.length - 1;
        updateQuestionDisplay();
    });

    // 7. 「反映して保存」ボタン
    $('#wacb-btn-finish').on('click', function() {
        let finalText = "";
        
        questionQueue.forEach(item => {
            if (item.a !== "") {
                // シンプルな形式に変更済み
                finalText += "Q. " + item.q + "\n";
                finalText += "A. " + item.a + "\n\n";
            }
        });

        const $targetTextarea = $('textarea[name="wacb_additional_info"]');
        if ($targetTextarea.length) {
            $targetTextarea.val(finalText);
            $overlay.fadeOut();
            $('#submit').click();
        } else {
            alert('エラー: 保存先のフィールドが見つかりませんでした。');
        }
    });

    // 8. 閉じるボタン
    $('#wacb-wizard-close').on('click', function() {
        if (confirm('ウィザードを閉じますか？ 入力内容は破棄されます。')) {
            $overlay.fadeOut();
        }
    });
});