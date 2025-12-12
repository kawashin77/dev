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
     * 設定値が空の場合、ページタイトルで検索してURLを返すヘルパー
     * @param string $option_key 設定オプション名
     * @param array $search_titles 検索するページタイトルの候補配列
     * @return string URL
     */
    private function get_auto_url($option_key, $search_titles = []) {
        // 1. 設定値を確認
        $url = get_option($option_key);
        if (!empty($url)) {
            return esc_url($url);
        }

        // 2. 設定がない場合、タイトルで固定ページを検索
        foreach ($search_titles as $title) {
            $page = get_page_by_title($title, OBJECT, 'page');
            if ($page && $page->post_status === 'publish') {
                return get_permalink($page->ID);
            }
        }

        // 3. それでもなければトップページ
        return home_url('/');
    }

    /**
     * /chat エンドポイントの処理
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
        $debug_info = [];

        // デフォルトの「見つからない」メッセージ
        $default_not_found_reply = get_option(
            'wacb_not_found_message', 
            '申し訳ありません。ご質問いただいた内容に関する情報は、ウェブサイト上では見つかりませんでした。詳細については、お電話にてお問い合わせください。'
        );

        // ★ URLの準備（設定値がない場合は指定の固定IDへ）
        
        // 1. 診療時間・曜日 (空なら ?p=22)
        $schedule_url_opt = get_option('wacb_schedule_url');
        $schedule_url = !empty($schedule_url_opt) ? esc_url($schedule_url_opt) : home_url('/?p=22');

        // 2. アクセス・場所 (空なら ?p=23)
        $access_url_opt = get_option('wacb_access_url');
        $access_url = !empty($access_url_opt) ? esc_url($access_url_opt) : home_url('/?p=23');

        // 3. 院長・医師 (空なら ?p=2)
        $greeting_url_opt = get_option('wacb_greeting_url');
        $greeting_url = !empty($greeting_url_opt) ? esc_url($greeting_url_opt) : home_url('/?p=2');
        
        // 4. WEB問診 (設定なければ空文字)
        $webform_url_opt = get_option('wacb_webform_url');
        $webform_url  = !empty($webform_url_opt) ? esc_url($webform_url_opt) : '';


        // --- 1. 意図分類 ---
        $prompt_intent_tpl = WACB_PLUGIN_DIR . 'prompt-intent.php';
        if (!file_exists($prompt_intent_tpl)) {
            return new WP_Error('no_prompt_i', '意図分類用プロンプトが見つかりません', ['status' => 500]);
        }
        
        ob_start();
        include $prompt_intent_tpl;
        $prompt_intent = ob_get_clean();
        $prompt_intent = str_replace('{{USER_QUESTION}}', $user_question, $prompt_intent);

        // AI呼び出し
        $raw_intent_code = self::callGemini($prompt_intent, $api_key);
        $raw_intent_code = trim($raw_intent_code, " \n\r\t\v\0\"'「」");

        self::log_data('chat_intent.log', "UserQ: [$user_question] -> Intent: [$raw_intent_code]");
        $debug_info['Intent (AI 1)'] = esc_html($raw_intent_code);

        // --- 2. 処理分岐 ---
        $intent = 'UNKNOWN';
        $search_keyword = '';
        $search_post_types_safe = ['post', 'column', 'toppage'];
        $is_rag_query = false; 
        $query_args = []; 

        $parts = explode(':', $raw_intent_code, 2);
        if (count($parts) === 2) {
            $intent = trim($parts[0]);
            $search_keyword = trim($parts[1]);
        } else {
            $intent = 'FAILED';
            $search_keyword = $raw_intent_code;
        }

        // 「該当なし」キーワードの場合は即時終了させる
        if ($search_keyword === '該当なし') {
            $debug_info['Action'] = 'Blocked by Intent (Nonsense input)';
            $final_reply = $default_not_found_reply;
            $intent = 'BLOCKED'; 
            $is_rag_query = false;
        }

        switch ($intent) {
            case 'HELLO':
                $debug_info['Action'] = 'Fixed Reply (Hello)';
                $final_reply = 'こんにちは、ご質問などご要件がございましたらお申し付けください。';
                break;

           case 'WEBFORM':
                // URL設定がある場合: 固定回答
                if (!empty($webform_url)) {
                    $debug_info['Action'] = 'Fixed Reply (Webform)';
                    $post_id = url_to_postid($webform_url);
                    $title = ($post_id > 0) ? get_the_title($post_id) : 'ウェブ問診ページ';
                    $final_reply = sprintf(
                        '「%s」については、「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページをご参照ください。',
                        $user_question_clean, $webform_url, esc_html($title)
                    );
                } else {
                    // URL設定なし: SEARCH意図に切り替えて検索を実行
                    $intent = 'SEARCH';
                    $is_rag_query = true;
                    $debug_info['Action'] = 'Switched to SEARCH (Webform empty)';
                    $debug_info['Search Mode'] = 'Custom Multi-Query (Page, Column, Toppage)';
                    
                    // キーワードが空の場合、質問文をキーワードにする
                    if (empty($search_keyword)) {
                        $search_keyword = $user_question;
                    }
                }
                break;

            case 'ACCESS': 
                $debug_info['Action'] = 'RAG (Access)';
                $is_rag_query = true; 
                $query_args = [
                    'post_type' => $search_post_types_safe, 
                    's' => $search_keyword,
                    'posts_per_page' => 5, 
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;

            case 'RESERVATION':
                $debug_info['Action'] = 'RAG (Reservation)';
                $is_rag_query = true; 
                 $query_args = [
                    'post_type' => $search_post_types_safe, 
                    's' => $search_keyword,
                    'posts_per_page' => 5, 
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;
            
            case 'SCHEDULE_REGULAR': 
                $debug_info['Action'] = 'RAG (Regular Schedule)';
                $is_rag_query = true;
                 $query_args = [
                    'post_type' => $search_post_types_safe, 
                    's' => $search_keyword,
                    'posts_per_page' => 5, 
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break; 

            case 'SCHEDULE_TEMPORARY':
                $debug_info['Action'] = 'RAG (Temporary Schedule)';
                $is_rag_query = true;
                $debug_info['Search Mode'] = 'Recent 10 Posts (No Keyword)';
                $query_args = [
                    'post_type' => ['post'], 
                    'posts_per_page' => 10, 
                    'post_status' => ['publish', 'future'], 
                    'orderby' => 'date', 
                    'order' => 'DESC',
                ];
                break;
            
            case 'GREETING': 
                 $debug_info['Action'] = 'RAG (Greeting)';
                 $is_rag_query = true; 
                 $query_args = [
                    'post_type' => $search_post_types_safe, 
                    's' => $search_keyword,
                    'posts_per_page' => 5, 
                    'post_status' => ['publish', 'future'], 
                ];
                $debug_info['Search Mode'] = 'WP Standard Search (Safe)';
                break;

            case 'SEARCH':
                $debug_info['Action'] = 'RAG (General)';
                $is_rag_query = true;
                $debug_info['Search Mode'] = 'Custom Multi-Query (Page, Column, Toppage)'; 
                break;
            
            case 'BLOCKED': 
                break;

            default: 
                $debug_info['Action'] = 'Intent Classification Failed';
                $debug_message = "Intent classification failed. AI returned: [" . esc_html($raw_intent_code) . "]";
                self::log_data('chat_error.log', $debug_message);
                $final_reply = $default_not_found_reply;
                if ( current_user_can('manage_options') && get_option('wacb_enable_debug_mode') == 1 ) {
                    $final_reply .= '<br><br><small>(Debug: ' . $debug_message . ')</small>';
                }
        }

        // --- 3. RAG処理 ---
        if ($is_rag_query) {
            
            if (empty($search_keyword) && $intent !== 'FAILED' && $intent !== 'SCHEDULE_TEMPORARY') { 
                $search_keyword = $user_question;
                if (!isset($query_args['s'])) { $query_args['s'] = ''; } 
                $query_args['s'] = $search_keyword; 
                $debug_info['Search Fallback'] = 'AI(1) failed. Using raw user question as keyword.';
            }

            $reference_data = "";
            $reservation_info = get_option('wacb_reservation_info', '');
            if (!empty($reservation_info)) {
                $reference_data .= "--- 予約・連絡先情報 (最優先) ---\n" . $reservation_info . "\n\n";
            }
            $additional_info = get_option('wacb_additional_info', '');
            if (empty(trim($additional_info))) {
                $additional_info = '（追加学習情報は設定されていません）';
            }
            
            $timezone_string = wp_timezone_string();
            date_default_timezone_set($timezone_string);
            $today_date_str = date('Y年n月j日') . '（' . ['日','月','火','水','木','金','土'][date('w')] . '）';
            $debug_info['Today (Server Time)'] = $today_date_str;

            $promptTpl = WACB_PLUGIN_DIR . 'prompt-chatbot.php';
            if (!file_exists($promptTpl)) {
                 return new WP_Error('no_prompt_c', 'チャットボット用プロンプトが見つかりません', ['status' => 500]);
            }
            ob_start();
            include $promptTpl;
            $prompt_template = ob_get_clean(); 
            
            $chat_title = get_option('wacb_chat_title', 'AIチャットボット');
            
            // 変数置換（自動補完されたURLを使用）
            $replacements = [
                '{{RESPONDER_NAME}}' => $chat_title,
                '{{USER_QUESTION}}' => $user_question,
                '{{REFERENCE_DATA}}' => $reference_data,
                '{{TODAY_DATE}}' => $today_date_str,
                '{{SCHEDULE_URL}}' => $schedule_url,
                '{{ACCESS_URL}}' => $access_url,    
                '{{GREETING_URL}}' => $greeting_url,
                '{{RESERVATION_INFO}}' => esc_textarea(get_option('wacb_reservation_info', '')),
                '{{WEBFORM_URL}}' => $webform_url,   
                '{{ADDITIONAL_INFO}}' => esc_textarea($additional_info),
            ];
            $prompt_1 = strtr($prompt_template, $replacements);
            
            $raw_response = '';
            $is_not_found = false;

            // SEARCH意図は強制DB検索
            if ($intent === 'SEARCH' || $intent === 'SCHEDULE_TEMPORARY') { 
                $debug_info['Action'] .= ' -> Forcing DB Search (Skipped Add. Info Check)';
                $is_not_found = true; 
            } else {
                self::log_data('chat_prompt_debug.log', "--- PROMPT 1 ---\n" . $prompt_1 . "\n\n");
                $raw_response = self::callGemini($prompt_1, $api_key); 
                self::log_data('chat_response_debug.log', "--- RESPONSE 1 ---\n" . $raw_response . "\n\n");
                $debug_info['AI (2) - Add. Info Only'] = esc_html($raw_response);

                $is_not_found = (strpos($raw_response, '該当なし') !== false || 
                                 strpos($raw_response, '見つかりませんでした') !== false || 
                                 strpos($raw_response, '記載がありませんでした') !== false || 
                                 strpos($raw_response, '回答を生成できませんでした') !== false || 
                                 strpos($raw_response, 'No response') !== false ||
                                 strpos($raw_response, '診療日や時間に関する情報は') !== false 
                                );
            }

            if ($is_not_found) {
                $debug_info['Action'] .= ' -> Fell back to DB Search';

                // --- SEARCH意図の特別検索 ---
                if ($intent === 'SEARCH') {
                    $debug_info['Search Keyword'] = esc_html($search_keyword);
                    $reference_data .= "--- サイト内検索結果 (検索キーワード: " . esc_html($search_keyword) . ") ---\n";
                    
                    $found_count = 0;
                    $seen_post_ids = []; 
                    $total_results_limit = 15; 
                    $total_results_current = 0;

                    if (mb_strlen($search_keyword, 'UTF-8') > 1) {
                        $target_post_types = ['page', 'column', 'toppage'];
                        
                        foreach ($target_post_types as $p_type) {
                            $sub_query = new WP_Query([
                                'post_type' => $p_type,
                                's' => $search_keyword, 
                                'posts_per_page' => 5, 
                                'post_status' => ['publish', 'future'],
                            ]);

                            if ( ! $sub_query->have_posts() ) {
                                $keywords = preg_split('/[\s,]+/', trim($search_keyword));
                                if (count($keywords) > 1) {
                                    $first_word = $keywords[0];
                                    $sub_query = new WP_Query([
                                        'post_type' => $p_type,
                                        's' => $first_word, 
                                        'posts_per_page' => 5,
                                        'post_status' => ['publish', 'future'],
                                    ]);
                                }
                            }

                            if ($sub_query->have_posts()) {
                                while ($sub_query->have_posts()) {
                                    $sub_query->the_post();
                                    $current_id = get_the_ID();
                                    if (in_array($current_id, $seen_post_ids)) continue;
                                    if ($total_results_current >= $total_results_limit) break 2;

                                    $seen_post_ids[] = $current_id;
                                    $found_count++; 
                                    $total_results_current++; 

                                    if ($p_type === 'toppage') {
                                        $content = wp_strip_all_tags(get_the_content());
                                        $excerpt = mb_substr($content, 0, 300);
                                        if (mb_strlen($content) > 300) { $excerpt .= '...'; }
                                    } else {
                                        $excerpt = '';
                                    }

                                    $reference_data .= "--- \n";
                                    $reference_data .= "タイプ: {$p_type}\n";
                                    $reference_data .= "タイトル: " . get_the_title() . "\n";
                                    $reference_data .= "URL: " . get_permalink() . "\n";
                                    if ($excerpt) $reference_data .= "内容抜粋: " . $excerpt . "\n";
                                }
                            }
                            wp_reset_postdata();
                            if ($total_results_current >= $total_results_limit) break;
                        }
                    } else {
                        $debug_info['Search Skipped'] = 'Keyword too short';
                    }

                    if ($found_count === 0) $reference_data .= "該当するページは見つかりませんでした。\n\n";
                    $debug_info['Found Posts'] = $found_count . ' (Total)';

                } else {
                    // --- SEARCH以外の標準検索 ---
                    $debug_info['Action'] .= ' -> (Safe Mode)';
                    $query = new WP_Query($query_args);
                    $debug_info['Found Posts'] = $query->post_count;

                    $found_toppage = false;
                    if ($query->have_posts()) {
                        foreach ($query->posts as $post_obj) {
                            if ($post_obj->post_type === 'toppage') {
                                $debug_info['Action'] .= ' -> Found toppage.';
                                $final_reply = sprintf('「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」に記載しています。', esc_url(home_url()), esc_html(get_the_title($post_obj->ID)));
                                $found_toppage = true;
                                break; 
                            }
                        }
                    }
                    
                    if ( ! $found_toppage ) {
                        if ($query->have_posts()) {
                            $reference_data .= "--- サイト内検索結果 ---\n";
                            while ($query->have_posts()) {
                                $query->the_post();
                                $reference_data .= "--- \nタイプ: ".get_post_type()."\nタイトル: ".get_the_title()."\nURL: ".get_permalink()."\n";
                            }
                            wp_reset_postdata();
                        } else {
                            $reference_data .= "該当するページは見つかりませんでした。\n\n";
                        }
                    }
                }

                if (empty($final_reply)) {
                    // AI(2) 再実行
                    $replacements['{{REFERENCE_DATA}}'] = $reference_data; 
                    $prompt_2 = strtr($prompt_template, $replacements);
                    
                    self::log_data('chat_prompt_debug.log', "--- PROMPT 2 ---\n" . $prompt_2 . "\n\n");
                    $raw_response = self::callGemini($prompt_2, $api_key);
                    self::log_data('chat_response_debug.log', "--- RESPONSE 2 ---\n" . $raw_response . "\n\n");
                    $debug_info['AI (2) - DB Search'] = esc_html($raw_response);
                    
                    // 「該当なし」の場合、自動補完されたURLへ誘導
                    if (strpos($raw_response, '該当なし') !== false || strpos($raw_response, 'No response') !== false) {
                         $fallback_url = ''; 
                         $fallback_title = '';

                         if ($intent === 'SCHEDULE_TEMPORARY' || $intent === 'SCHEDULE_REGULAR') {
                             $fallback_url = $schedule_url; $fallback_title = '診療時間';
                         } else if ($intent === 'ACCESS') {
                             $fallback_url = $access_url; $fallback_title = 'アクセス';
                         } else if ($intent === 'GREETING') {
                             $fallback_url = $greeting_url; $fallback_title = '院長挨拶';
                         } else if ($intent === 'RESERVATION') {
                             $fallback_url = home_url(); $fallback_title = 'トップページ'; 
                         }

                         if (!empty($fallback_url) && $fallback_url !== home_url('/')) {
                             // IDからタイトル再取得を試みる
                             $pid = url_to_postid($fallback_url);
                             $title = ($pid > 0) ? get_the_title($pid) : $fallback_title;
                             
                             $fmt = ($intent === 'SCHEDULE_TEMPORARY' || $intent === 'SCHEDULE_REGULAR') 
                                ? '診療日や時間に関する情報は「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページまたはお知らせにてご確認ください。'
                                : '関連する情報は見つかりませんでしたが、「<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>」のページをご参照ください。';
                             $final_reply = sprintf($fmt, esc_url($fallback_url), esc_html($title));
                         } else {
                             $final_reply = $default_not_found_reply;
                         }
                    } else {
                        // 整形
                        $final_reply = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $raw_response);
                        $final_reply = make_clickable($final_reply);
                        $final_reply = preg_replace('/<a href="(.*?)"(?![^>]*target=)/', '<a href="$1" target="_blank" rel="noopener noreferrer"', $final_reply);
                        $final_reply = preg_replace('/(\d{2,4}-\d{2,4}-\d{3,4})/', '<a href="tel:$1">$1</a>', $final_reply);
                        $final_reply = str_replace('\\n', "\n", $final_reply);
                        $final_reply = nl2br($final_reply, false);
                        $final_reply = strip_tags($final_reply, '<a><br>');
                    }
                }
            } else {
                // 追加情報のみで回答
                $final_reply = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $raw_response);
                $final_reply = make_clickable($final_reply);
                $final_reply = preg_replace('/<a href="(.*?)"(?![^>]*target=)/', '<a href="$1" target="_blank" rel="noopener noreferrer"', $final_reply);
                $final_reply = preg_replace('/(\d{2,4}-\d{2,4}-\d{3,4})/', '<a href="tel:$1">$1</a>', $final_reply);
                $final_reply = str_replace('\\n', "\n", $final_reply);
                $final_reply = nl2br($final_reply, false);
                $final_reply = strip_tags($final_reply, '<a><br>');
            }
        }
        
        $log_reply = $final_reply;
        
        if ( current_user_can('manage_options') && get_option('wacb_enable_debug_mode') == 1 && !empty($debug_info) ) {
            $debug_reply = " (Debug: \n";
            foreach ($debug_info as $key => $value) {
                if ($key === 'Generated SQL') $value = substr($value, 0, 500) . '...';
                $debug_reply .= "{$key} = {$value}\n";
            }
            $debug_reply .= ")";
            $final_reply .= nl2br(esc_html($debug_reply), false);
        }

        self::save_log($user_question, $log_reply);
        return rest_ensure_response(['reply' => $final_reply]);
    }

    private static function callGemini($prompt, $apiKey) {
        if (empty($prompt) || strlen($prompt) < 10) {
            self::log_data('response_error.log', "Prompt is too short or empty.");
            return 'No response (prompt is empty)';
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
        $url_with_key = add_query_arg('key', $apiKey, $url);
        $response = wp_remote_post($url_with_key, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['contents' => [[ 'parts' => [['text' => $prompt]] ]]]),
            'timeout' => 600, 
        ]);
        if (is_wp_error($response)) {
            self::log_data('response_error.log', print_r($response, true));
            return 'No response (WP_Error)';
        }
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($json === null) return 'No response (invalid JSON)';
        return $json['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';
    }

    private static function log_data($filename, $content) {
        $upload_dir = wp_upload_dir();
        $logDir = $upload_dir['basedir'] . '/wevery-ai-chatbot/log/';
        if (!file_exists($logDir)) wp_mkdir_p($logDir);
        file_put_contents($logDir . $filename, $content . "\n\n---\n\n", FILE_APPEND | LOCK_EX);
    }
    
    private static function save_log($question, $answer) {
        if (!post_type_exists('wacb_log')) return;
        wp_insert_post(['post_type' => 'wacb_log', 'post_title' => $question, 'post_content' => $answer, 'post_status' => 'publish']);
        self::trim_logs(100);
    }

    private static function trim_logs($limit = 100) {
        $query = new WP_Query(['post_type' => 'wacb_log', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids', 'post_status' => 'publish']);
        if (count($query->posts) > $limit) {
            foreach (array_slice($query->posts, 0, count($query->posts) - $limit) as $id) wp_delete_post($id, true);
        }
    }
}