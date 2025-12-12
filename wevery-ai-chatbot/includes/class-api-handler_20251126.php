<?php
if (!defined('ABSPATH')) exit;

class WACB_API_Handler {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * REST APIのエンドポイントを登録
     */
    public function register_routes() {
        register_rest_route('wevery-chatbot/v1', '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_chat'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * /chat エンドポイントの処理
     * (★ 最終版: 「固定URL」変数をプロンプトに追加)
     */
    public function handle_chat($request) {
        $params = $request->get_json_params();
        $user_question = sanitize_text_field($params['message'] ?? '');
        $user_question_clean = esc_html($user_question);

        if (empty($user_question)) {
            return new WP_Error('no_message', '質問がありません', ['status' => 400]);
        }

        $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $final_reply = '';
        
        // デバッグ情報を格納する配列
        $debug_info = [];

        // DBから「見つからない」メッセージを取得
        $default_not_found_reply = get_option(
            'wacb_not_found_message', 
            '申し訳ありません。ご質問いただいた内容に関する情報は、ウェブサイト上では見つかりませんでした。詳細については、お電話にてお問い合わせください。'
        );


        // --- STEP 1: AIによる「意図」の分類 ---
        $prompt_intent_tpl = WACB_PLUGIN_DIR . 'prompt-intent.php';
        if (!file_exists($prompt_intent_tpl)) {
            return new WP_Error('no_prompt_i', '意図分類用プロンプトが見つかりません', ['status' => 500]);
        }
        
        ob_start();
        include $prompt_intent_tpl;
        $prompt_intent = ob_get_clean();
        $prompt_intent = str_replace('{{USER_QUESTION}}', $user_question, $prompt_intent);

        // ★ 修正: 正常な callGemini を使用
        $raw_intent_code = self::callGemini($prompt_intent, $api_key);
        $raw_intent_code = trim($raw_intent_code, " \n\r\t\v\0\"'「」");

        self::log_data('chat_intent.log', "UserQ: [$user_question] -> Intent: [$raw_intent_code]");
        $debug_info['Intent (AI 1)'] = esc_html($raw_intent_code); // デバッグ記録

        // --- STEP 2: 意図に応じた処理の分岐 ---

        $intent = 'UNKNOWN';
        $search_keyword = '';
        
        // ★ 修正: 502エラー回避のため、'page' を $search_post_types から除外
        $search_post_types_safe = ['post', 'column', 'toppage'];
        
        $is_rag_query = false; // RAG実行フラグ
        $query_args = []; // WP_Query の引数
        // $is_title_search = true; // (★ 修正: 独自フィルターを使わないため、このフラグは不要)

        $parts = explode(':', $raw_intent_code, 2);
        if (count($parts) === 2) {
            $intent = trim($parts[0]);
            $search_keyword = trim($parts[1]);
        } else {
            $intent = 'FAILED';
            $search_keyword = $raw_intent_code;
        }

        switch ($intent) {
            // --- A: 固定マッピングによる即時回答 ---
            case 'WEBFORM':
                $debug_info['Action'] = 'Fixed Reply (Webform)';
                $url = get_option('wacb_webform_url');
                if (!empty($url)) {
                    $post_id = url_to_postid($url);
                    $title = ($post_id > 0) ? get_the_title($post_id) : 'ウェブ問診ページ';
                    $final_reply = sprintf(
                        '「%s」については、「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページをご参照ください。',
                        $user_question_clean, esc_url($url), esc_html($title)
                    );
                } else {
                    $final_reply = '申し訳ありません。関連するページが設定されていません。お電話にてお問い合わせください。';
                }
                break;

            case 'RESERVATION':
                 // ★ 修正: 14:41版の「予約」ロジック修正
                $debug_info['Action'] = 'RAG (Reservation)';
                $is_rag_query = true; 
                 $query_args = [
                    'post_type' => $search_post_types_safe, // ★ 502回避
                    's' => $search_keyword,
                    'posts_per_page' => 10,
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;


            // --- B: RAG(検索)による回答 ---
            
            case 'SCHEDULE_REGULAR': 
                $debug_info['Action'] = 'RAG (Regular Schedule)';
                $is_rag_query = true;
                 $query_args = [
                    'post_type' => $search_post_types_safe, // ★ 502回避
                    's' => $search_keyword,
                    'posts_per_page' => 10,
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break; 

            case 'SCHEDULE_TEMPORARY':
                $debug_info['Action'] = 'RAG (Temporary Schedule)';
                $is_rag_query = true;
                
                // ★★★★★ ご提案の「最新10件」ロジック (14:31) ★★★★★
                $debug_info['Search Mode'] = 'Recent 10 Posts (No Keyword)';
                
                $query_args = [
                    'post_type' => ['post'], // ★ 「columnは除外」
                    'posts_per_page' => 10, // ★ 10個程度
                    'post_status' => ['publish', 'future'], 
                    'orderby' => 'date', // ★ 最近の公開
                    'order' => 'DESC',
                    // 's' (キーワード) は使わない
                ];
                break;
            
            case 'ACCESS': 
                $debug_info['Action'] = 'RAG (Access)';
                $is_rag_query = true; // ★ 修正: RAGを実行
                $query_args = [
                    'post_type' => $search_post_types_safe, // ★ 502回避
                    's' => $search_keyword,
                    'posts_per_page' => 10,
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;
            
            case 'GREETING': 
                 $debug_info['Action'] = 'RAG (Greeting)';
                 $is_rag_query = true; // ★ 修正: RAGを実行
                 $query_args = [
                    'post_type' => $search_post_types_safe, // ★ 502回避
                    's' => $search_keyword,
                    'posts_per_page' => 10,
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;

            case 'SEARCH':
                $debug_info['Action'] = 'RAG (General)';
                $is_rag_query = true;
                 // ★ 修正: SEARCH の場合は $query_args を使わず、
                 //    この後の $is_rag_query ブロック内で $intent === 'SEARCH' で分岐する
                 $query_args = [
                    'post_type' => $search_post_types_safe, // ★ 502回避
                    's' => $search_keyword,
                    'posts_per_page' => 10,
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)'; // これは AI(2) をスキップする分岐で上書きされる
                break;

            // --- D: AIが意図を分類できなかった場合 ---
            default: // (FAILED や UNKNOWN)
                $debug_info['Action'] = 'Intent Classification Failed';
                $debug_message = "Intent classification failed. AI returned: [" . esc_html($raw_intent_code) . "]";
                self::log_data('chat_error.log', $debug_message);
                
                if ( current_user_can('manage_options') ) {
                    $final_reply = '申し訳ありません。ご質問の意図を理解できませんでした。(Debug: ' . $debug_message . ')';
                } else {
                    $final_reply = '申し訳ありません。ご質問の意図を理解できませんでした。お手数ですが、別の言葉でお試しください。';
                }
        }

        // --- RAG処理の実行 ---
        if ($is_rag_query) {
            
            // ★ 修正: AI(1)が失敗した場合のフォールバック (「院長の専門は？」対策)
            if (empty($search_keyword) && $intent !== 'FAILED' && $intent !== 'SCHEDULE_TEMPORARY') { // ★ 「最新10件」ロジックはキーワード不要
                $search_keyword = $user_question;
                // ★ 修正: $query_args['s'] が未定義の場合があるため、ここでセットする
                if (!isset($query_args['s'])) { $query_args['s'] = ''; } 
                $query_args['s'] = $search_keyword; 
                $debug_info['Search Fallback'] = 'AI(1) failed. Using raw user question as keyword.';
            }

            // --- 1. 「追加情報」の準備 ---
            $reference_data = "";
            $reservation_info = get_option('wacb_reservation_info', '');
            if (!empty($reservation_info)) {
                $reference_data .= "--- 予約・連絡先情報 (最優先) ---\n" . $reservation_info . "\n\n";
            }
            // ★★★★★ 15:10 修正 ★★★★★
            // 「追加学習情報」を $reference_data から分離
            $additional_info = get_option('wacb_additional_info', '');
            // if (!empty($additional_info)) {
            //     $reference_data .= "--- 管理画面の追加情報 (補足) ---\n" . $additional_info . "\n\n";
            // }
            
            // ★ 14:39 修正: 本日の日付を渡す
            $timezone_string = wp_timezone_string();
            date_default_timezone_set($timezone_string);
            $week = ['日', '月', '火', '水', '木', '金', '土'];
            $day_of_week = $week[date('w')];
            $today_date_str = date('Y年n月j日') . '（' . $day_of_week . '）';
            $debug_info['Today (Server Time)'] = $today_date_str;


            // AIに渡すプロンプトを作成
            $promptTpl = WACB_PLUGIN_DIR . 'prompt-chatbot.php';
            if (!file_exists($promptTpl)) {
                 return new WP_Error('no_prompt_c', 'チャットボット用プロンプトが見つかりません', ['status' => 500]);
            }
            ob_start();
            include $promptTpl;
            // ★★★★★ バグ修正 (14:11) ★★★★★
            $prompt_template = ob_get_clean(); // オリジナルのテンプレートを保持
            
            $chat_title = get_option('wacb_chat_title', 'AIチャットボット');
            
            // 1回目のAI呼び出し用のプロンプトを作成
            $prompt_1 = str_replace('{{RESPONDER_NAME}}', $chat_title, $prompt_template);
            $prompt_1 = str_replace('{{USER_QUESTION}}', $user_question, $prompt_1);
            $prompt_1 = str_replace('{{REFERENCE_DATA}}', $reference_data, $prompt_1);
            $prompt_1 = str_replace('{{TODAY_DATE}}', $today_date_str, $prompt_1); // ★ 修正: 日付を渡す
            
            // ★★★★★ 15:10 修正 ★★★★★
            // 「重要ページの固定回答設定」のURLを変数としてプロンプトに渡す
            $prompt_1 = str_replace('{{SCHEDULE_URL}}', esc_url(get_option('wacb_schedule_url', home_url('/'))), $prompt_1);
            $prompt_1 = str_replace('{{ACCESS_URL}}', esc_url(get_option('wacb_access_url', home_url('/'))), $prompt_1);
            $prompt_1 = str_replace('{{GREETING_URL}}', esc_url(get_option('wacb_greeting_url', home_url('/'))), $prompt_1);
            $prompt_1 = str_replace('{{RESERVATION_INFO}}', esc_textarea(get_option('wacb_reservation_info', '')), $prompt_1);
            $prompt_1 = str_replace('{{WEBFORM_URL}}', esc_url(get_option('wacb_webform_url', home_url('/'))), $prompt_1);
            // 「追加学習情報」を個別の変数として渡す
            $prompt_1 = str_replace('{{ADDITIONAL_INFO}}', esc_textarea($additional_info), $prompt_1);
            // ★★★★★ 修正ここまで ★★★★★
            

            // ★ 修正: 正常な callGemini を使用
            // $raw_response = self::callGemini($prompt_1, $api_key); // ★ 一旦コメントアウト (Mod 5)
            
            $raw_response = '';
            $is_not_found = false;

            // ★★★ START: SEARCH意図の強制DB検索 (Mod 5) ★★★
            // もし意図が 'SEARCH' または 'SCHEDULE_TEMPORARY' の場合、ADDITIONAL_INFO のみでのAI(2)呼び出しをスキップし、
            // 常に「該当なし」として扱い、強制的にDB検索(AI(3))を実行させる。
            if ($intent === 'SEARCH' || $intent === 'SCHEDULE_TEMPORARY') { // ★★★ ここを修正
                $debug_info['Action'] .= ' -> Forcing DB Search (Skipped Add. Info Check)';
                $is_not_found = true; // 強制的に「見つからなかった」ことにして、DB検索ブロックを実行させる
            
            } else {
                // SEARCH 以外の意図 (SCHEDULE_*, ACCESS, GREETING, RESERVATION) の場合のみ AI(2) を実行
                self::log_data('chat_prompt_debug.log', "--- PROMPT FOR 1ST AI (Additional Info Only) ---\n" . $prompt_1 . "\n\n");
                $raw_response = self::callGemini($prompt_1, $api_key); 
                
                self::log_data('chat_response_debug.log', "--- RAW RESPONSE FROM 1ST AI ---\n" . $raw_response . "\n\n");
                $debug_info['AI (2) - Add. Info Only'] = esc_html($raw_response);

                // --- 2. AIが「追加情報」だけでは「該当なし」と答えた場合 ---
                $is_not_found = (strpos($raw_response, '該当なし') !== false || 
                                 strpos($raw_response, '見つかりませんでした') !== false || 
                                 strpos($raw_response, '記載がありませんでした') !== false || 
                                 strpos($raw_response, '回答を生成できませんでした') !== false || 
                                 strpos($raw_response, 'No response') !== false ||
                                 strpos($raw_response, '診療日や時間に関する情報は') !== false 
                                );
            }
            // ★★★ END: SEARCH意図の強制DB検索 (Mod 5) ★★★
            

            if ($is_not_found) {
                
                $debug_info['Action'] .= ' -> Fell back to DB Search';

                // ★★★★★ START: SEARCH意図の特別処理 (Mod 1, 3, 6) ★★★★★
                if ($intent === 'SEARCH') {
                    
                    // (Mod 7: 以前の toppage 固定回答チェックは削除済み)
                
                    $debug_info['Search Mode'] = 'Custom Multi-Query (Search Intent)';
                    $debug_info['Search Keyword'] = esc_html($search_keyword);

                    // $reference_data に「追加」する
                    $reference_data .= "--- サイト内検索結果 (";
                    if (!empty($search_keyword)) {
                        $reference_data .= "検索キーワード: " . esc_html($search_keyword);
                    } else {
                        $reference_data .= "キーワードなし";
                    }
                    $reference_data .= ") ---\n";
                    
                    $found_count = 0; // 見つかった件数をカウント
                    $found_in_page = false; // ★★★ (Mod 7) pageで見つかったかのフラグ

                    // ★★★ (Mod 7) 検索順序を page -> toppage -> column に変更 ★★★

                   // 3. column (5件、タイトルのみ)

                    // ★★★ START: 複数語フォールバック (Mod 9) ★★★
                    $query_column = new WP_Query([
                        'post_type' => 'column',
                        's' => $search_keyword, // まずは全キーワードで検索
                        'posts_per_page' => 5,
                        'post_status' => ['publish', 'future'],
                    ]);

                    // もし全キーワードでヒットせず、キーワードが複数語だった場合
                    if ( ! $query_column->have_posts() ) {
                        $keywords = preg_split('/[\s,]+/', trim($search_keyword));
                        if (count($keywords) > 1) {
                            $first_word = $keywords[0];
                            $debug_info['Search Fallback (Column)'] = 'Full search failed. Retrying with: ' . esc_html($first_word);
                            
                            // 先頭の1語で再検索
                            $query_column = new WP_Query([
                                'post_type' => 'column',
                                's' => $first_word, 
                                'posts_per_page' => 5,
                                'post_status' => ['publish', 'future'],
                            ]);
                        }
                    }
                    // ★★★ END: 複数語フォールバック (Mod 9) ★★★

                    if ($query_column->have_posts()) {
                        $found_count += $query_column->post_count;
                        while ($query_column->have_posts()) {
                            $query_column->the_post();
                            $reference_data .= "--- \n";
                            $reference_data .= "タイプ: column\n";
                            $reference_data .= "タイトル: " . get_the_title() . "\n";
                            $reference_data .= "URL: " . get_permalink() . "\n";
                            // 内容抜粋はなし
                        }
                    }
                    wp_reset_postdata();


                    // 2. toppage (10件、タイトル+序文300文字)
                    // ★★★ (Mod 7) pageで見つからなかった場合のみ、toppageを参照する ★★★
                    if ( ! $found_in_page ) {
                        // ★ 修正: toppageはキーワード検索('s')の対象から外し、常に内容を取得
                        $query_toppage = new WP_Query([
                            'post_type' => 'toppage',
                            // 's' => $search_keyword, // (Mod 3) 削除済み
                            'posts_per_page' => 10,
                            'post_status' => ['publish', 'future'],
                        ]);
                        if ($query_toppage->have_posts()) {
                            $found_count += $query_toppage->post_count;
                            while ($query_toppage->have_posts()) {
                                $query_toppage->the_post();
                                $content = wp_strip_all_tags(get_the_content());
                                // ★ 修正: 300文字で抜粋
                                $excerpt = mb_substr($content, 0, 300);
                                if (mb_strlen($content) > 300) { $excerpt .= '...'; }
                                
                                $reference_data .= "--- \n";
                                $reference_data .= "タイプ: toppage\n";
                                $reference_data .= "タイトル: " . get_the_title() . "\n";
                                $reference_data .= "URL: " . home_url('/') . "\n"; // toppage はトップページURL
                                $reference_data .= "内容抜粋(300文字): " . $excerpt . "\n";
                            }
                        }
                        wp_reset_postdata();
                    } // ★★★ (Mod 7) if ( ! $found_in_page ) の閉じ


                    // 3. column (5件、タイトルのみ)
                    
                    // ★★★ START: OR検索を有効化 ★★★
                    add_filter('posts_search', [$this, 'wacb_custom_or_search'], 10, 2);
                    $query_column = new WP_Query([
                        'post_type' => 'column',
                        's' => $search_keyword,
                        'posts_per_page' => 5,
                        'post_status' => ['publish', 'future'],
                    ]);
                    remove_filter('posts_search', [$this, 'wacb_custom_or_search'], 10);
                    // ★★★ END: OR検索を無効化 ★★★
                    if ($query_column->have_posts()) {
                        $found_count += $query_column->post_count;
                        while ($query_column->have_posts()) {
                            $query_column->the_post();
                            $reference_data .= "--- \n";
                            $reference_data .= "タイプ: column\n";
                            $reference_data .= "タイトル: " . get_the_title() . "\n";
                            $reference_data .= "URL: " . get_permalink() . "\n";
                            // 内容抜粋はなし
                        }
                    }
                    wp_reset_postdata();

                    if ($found_count === 0) {
                         $reference_data .= "該当するページは見つかりませんでした。\n\n";
                    }
                    
                    $debug_info['Found Posts'] = $found_count . ' (Custom Search)';
                    
                    // (Mod 7: 以前の toppage 固定回答チェックの } は削除済み)

                // ★★★★★ END: SEARCH意図の特別処理 ★★★★★
                
                } else {
                    // --- (SEARCH以外の意図) ここからDB検索 (RAG) ---
                    $debug_info['Action'] .= ' -> (Safe Mode)';
                    $debug_info['Search Keyword'] = esc_html($query_args['s'] ?? 'N/A (Recent Posts)'); // 's' をデバッグ
                    $debug_info['Search PostTypes'] = esc_html(implode(', ', $query_args['post_type']));
                    
                    // WordPress標準検索 (高速なAND検索) を実行する
                    $query = new WP_Query($query_args);
                    
                    $debug_info['Generated SQL'] = esc_html($query->request);
                    $debug_info['Found Posts'] = $query->post_count;

                    // ★★★★★ ユーザー要求（14:44）による 'toppage' の特別処理 ★★★★★
                    $found_toppage = false;
                    if ($query->have_posts()) {
                        foreach ($query->posts as $post_obj) {
                            if ($post_obj->post_type === 'toppage') {
                                $debug_info['Action'] .= ' -> Found toppage. Creating fixed reply.';
                                $title = get_the_title($post_obj->ID);
                                $url = home_url(); // トップページのURL
                                
                                $final_reply = sprintf(
                                    '「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」に記載しています。',
                                    esc_url($url),
                                    esc_html($title) // 「トップページのページタイトル」
                                );
                                
                                $found_toppage = true;
                                break; // 1件でも見つかったらループ終了
                            }
                        }
                    }
                    // ★★★ 処理ここまで ★★★
                    
                    // ★ 修正: toppage が見つからなかった場合のみ、通常のRAG（AI(2)呼び出し）を実行
                    if ( ! $found_toppage ) {

                        if ($query->have_posts()) {
                            $reference_data .= "--- サイト内検索結果 ("; // $reference_data に「追加」する
                            if (isset($query_args['s'])) {
                                $reference_data .= "検索キーワード: " . esc_html($query_args['s']);
                            } else {
                                $reference_data .= "最新の投稿"; // ★ 「最新10件」ロジック用
                            }
                            $reference_data .= ") ---\n";
                            
                            while ($query->have_posts()) {
                                $query->the_post();
                                $title = get_the_title();
                                $url = get_permalink();
                                $content = wp_strip_all_tags(get_the_content());
                                // ★ 修正: 300文字で抜粋 (Mod 1)
                                $excerpt = mb_substr($content, 0, 300);
                                if (mb_strlen($content) > 300) { $excerpt .= '...'; }

                                $reference_data .= "--- \n";
                                $reference_data .= "タイプ: " . get_post_type() . "\n";
                                $reference_data .= "タイトル: " . $title . "\n";
                                $reference_data .= "URL: " . $url . "\n";
                                $reference_data .= "内容抜粋(300文字): " . $excerpt . "\n";
                            }
                            wp_reset_postdata();
                        } else {
                            $reference_data .= "--- サイト内検索結果 ---\n該当するページは見つかりませんでした。\n\n";
                        }

                        // ★ 修正: 「固定URL」へのフォールバックを、DB検索「後」に行う
                        if ( ! $query->have_posts() && 
                             ($intent === 'SCHEDULE_TEMPORARY' || $intent === 'SCHEDULE_REGULAR' || $intent === 'ACCESS' || $intent === 'GREETING' || $intent === 'RESERVATION') // ★ 修正: RESERVATION も追加 
                           ) {
                            
                            $fallback_url = '';
                            $fallback_title = '';
                            
                            // ★★★★★ ユーザー要求（14:43）に基づき、文言を修正 ★★★★★
                            if ($intent === 'SCHEDULE_TEMPORARY' || $intent === 'SCHEDULE_REGULAR') {
                                $fallback_url = get_option('wacb_schedule_url');
                                $fallback_title = '診療時間';
                                
                                if (!empty($fallback_url)) {
                                    $debug_info['Fallback'] = 'No posts found in DB, falling back to Fixed URL (Schedule)';
                                    $post_id = url_to_postid($fallback_url);
                                    $title = ($post_id > 0) ? get_the_title($post_id) : $fallback_title;
                                    // ★ 14:43 ご要望の文言
                                    $final_reply = sprintf(
                                        '診療日や時間に関する情報は「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページまたはお知らせにてご確認ください。',
                                        esc_url($fallback_url), esc_html($title)
                                    );
                                } else {
                                    // ★ 14:52 修正: URLがない場合でも、お知らせ（トップページ）への誘導を試みる
                                    $debug_info['Fallback'] = 'No posts found AND No Schedule URL. Falling back to Homepage.';
                                    $final_reply = sprintf(
                                        '診療日や時間に関する情報は、<a href="%s" target="_blank" rel="noopener noreferrer">トップページのお知らせ</a>にてご確認ください。',
                                         esc_url(home_url('/'))
                                    );
                                }

                            } else if ($intent === 'ACCESS') {
                                $fallback_url = get_option('wacb_access_url');
                                $fallback_title = 'アクセス';
                            } else if ($intent === 'GREETING') {
                                $fallback_url = get_option('wacb_greeting_url');
                                $fallback_title = '院長挨拶';
                            } else if ($intent === 'RESERVATION') {
                                // ★ 修正: 「予約」がDB検索にも失敗した場合
                                $fallback_url = home_url(); // トップページ
                                $fallback_title = 'トップページ'; 
                            }

                            // ★ 修正: SCHEDULE 以外のフォールバック
                            if (empty($final_reply) && !empty($fallback_url)) {
                                $debug_info['Fallback'] = 'No posts found in DB, falling back to Fixed URL.';
                                $post_id = url_to_postid($fallback_url);
                                $title = ($post_id > 0) ? get_the_title($post_id) : $fallback_title;
                                $final_reply = sprintf(
                                    '関連する情報は見つかりませんでしたが、「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページをご参照ください。',
                                    esc_url($fallback_url), esc_html($title)
                                );
                            } else if (empty($final_reply)) {
                                // 固定URLも設定されていない
                                 $final_reply = $default_not_found_reply;
                            }
                            // ★★★★★ 修正ここまで ★★★★★

                        } 
                        
                    } // ★ 修正: if ( ! $found_toppage ) の閉じ
                
                } // ★★★★★ END: if ($intent === 'SEARCH') の else ★★★★★


                // ★ final_reply がまだ設定されていない（＝フォールバックしなかった）場合のみ、AIに再度聞く
                // (このロジックは SEARCH の場合も、それ以外の場合も共通で実行される)
                if (empty($final_reply)) {
                    // --- 3. DB検索結果を「追加」して、AIに「再度」聞く ---
                    
                    // ★★★★★ バグ修正 (14:11) ★★★★★
                    // 1回目のプロンプト($prompt_1)を使い回さず、
                    // オリジナルの $prompt_template から2回目のプロンプトを生成する
                    
                    $prompt_2 = str_replace('{{RESPONDER_NAME}}', $chat_title, $prompt_template);
                    $prompt_2 = str_replace('{{USER_QUESTION}}', $user_question, $prompt_2);
                    $prompt_2 = str_replace('{{REFERENCE_DATA}}', $reference_data, $prompt_2); // ★ SEARCHで作った $reference_data が使われる
                    $prompt_2 = str_replace('{{TODAY_DATE}}', $today_date_str, $prompt_2); // ★ 修正: 日付を渡す
                    
                    // ★★★★★ 15:10 修正 ★★★★★
                    $prompt_2 = str_replace('{{SCHEDULE_URL}}', esc_url(get_option('wacb_schedule_url', home_url('/'))), $prompt_2);
                    $prompt_2 = str_replace('{{ACCESS_URL}}', esc_url(get_option('wacb_access_url', home_url('/'))), $prompt_2);
                    $prompt_2 = str_replace('{{GREETING_URL}}', esc_url(get_option('wacb_greeting_url', home_url('/'))), $prompt_2);
                    $prompt_2 = str_replace('{{RESERVATION_INFO}}', esc_textarea(get_option('wacb_reservation_info', '')), $prompt_2);
                    $prompt_2 = str_replace('{{WEBFORM_URL}}', esc_url(get_option('wacb_webform_url', home_url('/'))), $prompt_2);
                    // 「追加学習情報」を個別の変数として渡す
                    $prompt_2 = str_replace('{{ADDITIONAL_INFO}}', esc_textarea($additional_info), $prompt_2);
                    // ★★★★★ 修正ここまで ★★★★★
                    
                    self::log_data('chat_prompt_debug.log', "--- PROMPT FOR 2ND AI (DB Search Included) ---\n" . $prompt_2 . "\n\n");
                    // ★ 修正: 正常な callGemini を使用
                    $raw_response = self::callGemini($prompt_2, $api_key);
                    self::log_data('chat_response_debug.log', "--- RAW RESPONSE FROM 2ND AI (DB Search) ---\n" . $raw_response . "\n\n");

                    $debug_info['AI (2) - DB Search'] = esc_html($raw_response);
                    
                    if (strpos($raw_response, '該当なし') !== false || strpos($raw_response, '回答を生成できませんでした') !== false || strpos($raw_response, 'No response') !== false) {
                         $final_reply = $default_not_found_reply;
                    } else {
                        // 回答をHTMLに変換
                        $final_reply = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $raw_response);
                        $final_reply = nl2br($final_reply, false);
                        $final_reply = strip_tags($final_reply, '<a><br>'); // ★★★ $final_reply に修正 (Mod 4) ★★★
                    }
                }

            } else {
                // --- 2. AIが「追加情報」だけで回答できた場合 ---
                $debug_info['Action'] .= ' -> Answered from Additional Info (DB Search Skipped)';
                
                // 回答をHTMLに変換
                $final_reply = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $raw_response);
                $final_reply = nl2br($final_reply, false);
                $final_reply = strip_tags($final_reply, '<a><br>');
            }
        }
        
        // ★ 修正: デバッグ情報をログに保存する「前」のクリーンな回答を保持
        $log_reply = $final_reply;
        
        // 最終的な回答にデバッグ情報を付加 (管理者のみ「かつ」デバッグモードON の場合)
        if ( current_user_can('manage_options') && get_option('wacb_enable_debug_mode') == 1 && !empty($debug_info) ) {
            $debug_reply = " (Debug: \n";
            foreach ($debug_info as $key => $value) {
                if ($key === 'Generated SQL') {
                    $value = substr($value, 0, 500) . '... (truncated)';
                }
                $debug_reply .= "{$key} = {$value}\n";
            }
            $debug_reply .= ")";
            // <br>に変換
            $final_reply .= nl2br(esc_html($debug_reply), false);
        }

        // 最終的な回答をログに保存 (★ $log_reply (クリーンな回答) を使用)
        self::save_log($user_question, $log_reply);
        
        return rest_ensure_response(['reply' => $final_reply]);
    }

    
    /* (★ 以下のDB検索用フックは、502エラーの原因だったため「不使用」) */


    /**
     * WP_Query の検索をタイトルのみに限定するフック (現在不使用)
     */
    public function wacb_search_by_title_only($search, $query) {
        global $wpdb;
        $s = $query->get('s');
        if (empty($s)) { return $search; }
        $search_terms = preg_split('/[\s,t]+/', trim($s));
        $search = '';
        $search_or = '';
        foreach ($search_terms as $term) {
            $term = $wpdb->esc_like(trim($term));
            if (empty($term)) continue;
            $search .= $search_or . "(" . $wpdb->posts . ".post_title LIKE '%" . $term . "%')";
            $search_or = ' OR ';
        }
        if (!empty($search)) { $search = " AND ({$search}) "; }
        return $search;
    }

    /**
     * WP_Query の検索を「タイトル OR 本文」の OR 検索に置換する (現在不使用)
     */
    public function wacb_custom_or_search($search, $query) {
        global $wpdb;
        $s = $query->get('s');
        if (empty($s)) { return $search; }
        // ★ 修正: 't' の typo を削除
        $keywords = preg_split('/[\s,]+/', trim($s));
        if (empty($keywords)) { return $search; }
        $searches = [];
        foreach ($keywords as $keyword) {
            if (empty($keyword)) continue;
            $keyword = $wpdb->esc_like($keyword);
            // ★ 修正: $wpd->posts を $wpdb->posts に修正
            $searches[] = "(" . $wpdb->posts . ".post_title LIKE '%" . $keyword . "%' 
                           OR " . $wpdb->posts . ".post_content LIKE '%" . $keyword . "%')";
        }
        if (!empty($searches)) { $search = ' AND (' . implode(' OR ', $searches) . ') ';
        }
        return $search;
    }


    /**
     * Gemini APIを呼び出す共通関数
     * (★ 修正: 正常動作する ok_class-api-handler.php の関数 を移植)
     */
    private static function callGemini($prompt, $apiKey) {
        // モデル名を 'gemini-2.0-flash' に設定
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
        
        // add_query_arg を使って安全にAPIキーをURLに追加
        $url_with_key = add_query_arg('key', $apiKey, $url);

        $response = wp_remote_post($url_with_key, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'contents' => [[ 'parts' => [['text' => $prompt]] ]]
            ]),
            'timeout' => 600, // ★ 正常なプラグインに合わせて600秒
        ]);

        if (is_wp_error($response)) {
            // エラーログ
            self::log_data('response_error.log', print_r($response, true));
            // WP_Error の詳細メッセージを返す
            $error_message = $response->get_error_message();
            return 'No response (WP_Error: ' . $error_message . ')';
        }

        $body = wp_remote_retrieve_body($response);
        // S(uccess ログ
        self::log_data('response.log', $body ?: '[EMPTY RESPONSE]');

        $json = json_decode($body, true);
        if ($json === null) {
            self::log_data('response_error.log', "JSON decode failed:\n" . $body);
            return 'No response (invalid JSON)';
        }

        // ★ 修正: 正常なプラグインの戻り値ロジック
        return $json['candidates'][0]['content']['parts'][0]['text'] ?? 'No response (no text part)';
    }

	/**
     * ログを書き込む共通関数
     * (保存先: wp-content/uploads/wevery-ai-chatbot/log/)
     */
    private static function log_data($filename, $content) {
        // ★ 修正: 保存先をプラグインディレクトリではなく、Uploadsディレクトリに変更
        $upload_dir = wp_upload_dir();
        // パス: .../wp-content/uploads/wevery-ai-chatbot/log/
        $logDir = $upload_dir['basedir'] . '/wevery-ai-chatbot/log/';
        $filepath = $logDir . $filename;

        // ディレクトリが存在しない場合は作成
        if (!file_exists($logDir)) {
            if (!wp_mkdir_p($logDir)) { 
                // 作成に失敗した場合はWordPressのエラーログに記録して終了
                error_log("[WACB] Failed to create log directory: {$logDir}");
                return; 
            }
        }

        // ログの内容を整形
        $charCount = mb_strlen($content, 'UTF-8');
        $logEntry = $content . "\n\n---\nNumber of characters: {$charCount} 文字\n\n";

        // ファイルに追記保存
        $result = file_put_contents($filepath, $logEntry, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[WACB] Failed to write to log file: {$filepath}");
        }
    }
    
    /**
     * ログを 'wacb_log' CPT に 保存する
     */
    private static function save_log($question, $answer) {
        if (!post_type_exists('wacb_log')) {
            self::log_data('chat_error.log', "Log CPT 'wacb_log' does not exist. Cannot save log.");
            return;
        }
        wp_insert_post([
            'post_type' => 'wacb_log',
            'post_title' => $question,
            'post_content' => $answer, 
            'post_status' => 'publish',
        ]);
        self::trim_logs(100);
    }

    /**
     * 古いログを削除して件数を制限する
     */
    private static function trim_logs($limit = 100) {
        $query = new WP_Query([
            'post_type' => 'wacb_log',
            'posts_per_page' => -1, 
            'orderby' => 'date',
            'order' => 'ASC', 
            'fields' => 'ids', 
            'post_status' => 'publish',
        ]);
        $log_ids = $query->posts;
        $total_count = count($log_ids);
        if ($total_count > $limit) {
            $ids_to_delete = array_slice($log_ids, 0, $total_count - $limit);
            foreach ($ids_to_delete as $id) {
                wp_delete_post($id, true); 
            }
        }
    }
}