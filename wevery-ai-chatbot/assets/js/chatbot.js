jQuery(document).ready(function ($) {

    const $chatIcon = $('#wacb-chat-icon');
    const $chatWindow = $('#wacb-chat-window');
    const $closeBtn = $('#wacb-close-btn');
    const $chatLog = $('#wacb-chat-log');
    const $sendBtn = $('#wacb-send-btn');
    const $userInput = $('#wacb-user-message');

    // 1. チャットウィンドウの開閉
    $chatIcon.on('click', function () {
        $chatWindow.slideDown(300);
        $(this).hide();
    });
    $closeBtn.on('click', function () {
        $chatWindow.slideUp(300, function() {
            $chatIcon.show();
        });
    });

    // 2. メッセージ送信処理
    async function sendMessage() {
        const message = $userInput.val().trim();
        if (message === '') return;

        // ユーザーのメッセージをログに追加
        appendMessage('user', message);
        $userInput.val('');
        $sendBtn.prop('disabled', true);

        // スピナー（読み込み中）を表示
        // ★ 修正: 初期テキストを . 1つに変更
        const $loading = appendMessage('loading', 'AIが回答を考えています.');

        // ★★★ START: ローディングアニメーション修正 ★★★
        let dotCount = 1;
        const $loadingText = $loading.find('p'); // pタグを取得
        // $loadingText.text('AIが回答を考えています'); // 初期テキストはappendMessageで設定済み

        const loadingInterval = setInterval(() => {
            dotCount++; // 1秒ごとに . を増やす
            if (dotCount > 10) {
                dotCount = 1; // 10個を超えたら1個に戻る
            }
            let dots = '.'.repeat(dotCount);
            $loadingText.text('AIが回答を考えています' + dots);
        }, 1000); // 1秒ごとに更新
        // ★★★ END: ローディングアニメーション修正 ★★★

        try {
            const response = await fetch(WACB_Chatbot.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WACB_Chatbot.nonce
                },
                body: JSON.stringify({ message: message })
            });

            // ★ 修正: サーバーからのHTTPエラーを詳細にデバッグ
            if (!response.ok) {
                const status = response.status;
                const statusText = response.statusText;
                let errorBody = '';
                try {
                    // サーバーがPHPエラーなどを返した場合、本文を取得
                    errorBody = await response.text();
                } catch (e) {
                    // a
                }
                
                // ブラウザのコンソールに詳細を記録
                console.error('WACB Debug: API request failed.', {status, statusText, body: errorBody});
                
                // ★ 修正: ユーザーに見せるエラーメッセージを具体的にする
                throw new Error(`API接続エラー (HTTP ${status}: ${statusText})。サーバーエラー（500）の場合、PHPの構文エラーの可能性があります。`);
            }

            // レスポンスがOK (200) だが、JSONとして不正な場合
            let data = {};
            try {
                 data = await response.json();
            } catch (e) {
                console.error('WACB Debug: JSON parse failed.', e);
                throw new Error('APIからの応答がJSONではありません。 (E: JSON_Parse)');
            }
            
            // ローディングを削除
            $loading.remove();
            
            // AIの回答を追加
            if (data.reply) {
                appendMessage('bot', data.reply);
            } else {
                appendMessage('bot', '申し訳ありません、回答を取得できませんでした。');
            }

        } catch (error) {
            $loading.remove();
            // ★ 修正: throw された詳細なエラーがここに表示される
            appendMessage('bot', 'エラーが発生しました: ' + error.message);
        } finally {
            // ★★★ START: インターバル停止追加 ★★★
            clearInterval(loadingInterval);
            // ★★★ END: インターバル停止追加 ★★★
            $sendBtn.prop('disabled', false);
            $userInput.focus();
        }
    }

    // 3. ログにメッセージを追加するヘルパー関数
    function appendMessage(sender, text) {
        const senderClass = (sender === 'user') ? 'wacb-user' : (sender === 'loading' ? 'wacb-bot wacb-loading' : 'wacb-bot');
        
        // PHPがHTML (<a href> や <br>) を生成するため、JSでの自動変換は行わない
        
        // $message の <p> タグに、エスケープせずにHTMLをそのまま設定する
        const $message = $(`
            <div class="wacb-message ${senderClass}">
                <p></p>
            </div>
        `);
        $message.find('p').html(text); // .text() ではなく .html() を使う

        $chatLog.append($message);
        
        // ログを一番下にスクロール
        $chatLog.scrollTop($chatLog[0].scrollHeight);
        
        return $message; // ローディング削除用に要素を返す
    }

    // 4. イベントリスナー
    $sendBtn.on('click', sendMessage);
    
    // $userInput.on('keypress', function (e) {
    //     if (e.which === 13) { // Enterキー
    //         sendMessage();
    //     }
    // });

    // ==================================================
    // 5. ★ 新規追加: 規約モーダルの制御
    // ==================================================
    const $tacModal = $('#wacb-tac-modal');
    const $tacOpenLink = $('#wacb-open-tac');
    const $tacCloseBtn = $('#wacb-tac-close-btn');
    const $tacAgreeBtn = $('#wacb-tac-agree-close');
    const $tacOverlay = $('.wacb-tac-overlay');

    // 規約リンククリックでモーダルを開く
    $tacOpenLink.on('click', function(e) {
        e.preventDefault(); // リンクのデフォルト動作（#へのジャンプ）を無効化
        $tacModal.fadeIn(200);
    });

    // モーダルを閉じる関数
    function closeTacModal() {
        $tacModal.fadeOut(200);
    }

    // 閉じるボタン、同意ボタン、背景クリックで閉じる
    $tacCloseBtn.on('click', closeTacModal);
    $tacAgreeBtn.on('click', closeTacModal);
    $tacOverlay.on('click', closeTacModal);

});