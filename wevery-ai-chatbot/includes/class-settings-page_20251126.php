<?php
if (!defined('ABSPATH')) exit;

class WACB_Settings_Page {

    private $menu_slug = 'wacb-settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_menu() {
        $hook_suffix = add_menu_page(
            'AIチャットボット設定',
            '簡易AIチャット',
            'manage_options',
            $this->menu_slug,
            [$this, 'render_page'],
            'dashicons-format-chat'
        );
        add_action('admin_footer-' . $hook_suffix, [$this, 'add_inline_admin_script_and_style']);
        add_action('admin_footer-' . $hook_suffix, [$this, 'render_admin_chatbot_ui']);
    }
    
    public function enqueue_admin_scripts($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_' . $this->menu_slug) {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'wacb-chatbot-style',
            WACB_PLUGIN_URL . 'assets/css/chatbot.css',
            [],
            '1.0.7' // ★重要: バージョンを 1.0.7 に更新して最新のCSSを適用
        );
        
        $color_icon     = get_option('wacb_color_icon', '#0073aa');
        $color_header   = get_option('wacb_color_header', '#0073aa');
        $color_send_btn = get_option('wacb_color_send_btn', '#0073aa');
        $color_user_bg  = get_option('wacb_color_user_bg', '#0073aa');
        $color_bot_bg   = get_option('wacb_color_bot_bg', '#e9e9eb');

        $dynamic_css = ":root {
            --wacb-color-icon: " . esc_attr($color_icon) . ";
            --wacb-color-header: " . esc_attr($color_header) . ";
            --wacb-color-send-btn: " . esc_attr($color_send_btn) . ";
            --wacb-color-user-bg: " . esc_attr($color_user_bg) . ";
            --wacb-color-bot-bg: " . esc_attr($color_bot_bg) . ";
        }";
        
        wp_add_inline_style('wacb-chatbot-style', $dynamic_css);
        
        wp_enqueue_script(
            'wacb-chatbot-script',
            WACB_PLUGIN_URL . 'assets/js/chatbot.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wacb-chatbot-script', 'WACB_Chatbot', [
            'apiEndpoint' => rest_url('wevery-chatbot/v1/chat'),
            'nonce'       => wp_create_nonce('wp_rest')
        ]);
    }
    
    public function add_inline_admin_script_and_style() {
        ?>
        <style>
            .tab-content { display: none; }
            .wacb-log-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .wacb-log-table th, .wacb-log-table td { border: 1px solid #ccd0d4; padding: 8px 12px; text-align: left; vertical-align: top; }
            .wacb-log-table th { background: #f0f0f1; font-weight: 600; }
            .wacb-log-table td:nth-child(1) { width: 15%; }
            .wacb-log-table td:nth-child(2) { width: 30%; }
            .wacb-log-table td:nth-child(3) { width: 55%; word-break: break-word; }
            
            /* ★修正: プレビュー画像のスタイル (丸抜き解除、サイズ調整) */
            #wacb-icon-preview-wrapper { margin-top: 10px; }
            #wacb-icon-preview-img { 
                max-width: 150px; /* 幅を広げる */
                max-height: 80px; 
                border-radius: 0; /* 丸を解除 */
                border: 1px solid #ddd; 
                display: block; 
                object-fit: contain;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wacb-color-picker').wpColorPicker();

                var $tabs = $('.nav-tab-wrapper a.nav-tab');
                var $content = $('.tab-content');
                
                var hash = window.location.hash;
                if (hash && $tabs.filter('[href="' + hash + '"]').length) {
                    $tabs.removeClass('nav-tab-active');
                    $content.hide();
                    $tabs.filter('[href="' + hash + '"]').addClass('nav-tab-active');
                    $(hash).show();
                    setTimeout(function() {
                        var $tabWrapper = $('.nav-tab-wrapper');
                        if ($tabWrapper.length) {
                            $('html, body').animate({ scrollTop: $tabWrapper.offset().top - 40 }, 0); 
                        } else {
                            $('html, body').animate({ scrollTop: 0 }, 0);
                        }
                    }, 1);
                } else {
                    $content.hide().first().show();
                    $tabs.first().addClass('nav-tab-active');
                }

                $tabs.on('click', function(e) {
                    e.preventDefault(); 
                    var target = $(this).attr('href');
                    $tabs.removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $content.hide();
                    $(target).show();
                    if (history.replaceState) {
                        history.replaceState(null, null, target);
                    }
                });

                var frame;
                $('#wacb-upload-icon-btn').on('click', function(e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: 'チャットボットのアイコン画像を選択',
                        button: { text: 'この画像を使用' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#wacb_icon_image').val(attachment.url);
                        $('#wacb-icon-preview-wrapper').html('<img id="wacb-icon-preview-img" src="' + attachment.url + '" />');
                        $('#wacb-remove-icon-btn').show();
                    });
                    frame.open();
                });

                $('#wacb-remove-icon-btn').on('click', function(e) {
                    e.preventDefault();
                    $('#wacb_icon_image').val('');
                    $('#wacb-icon-preview-wrapper').html('');
                    $(this).hide();
                });
            });
        </script>
        <?php
    }

    // ... (render_admin_chatbot_ui などの残りのメソッドは変更なし) ...
    
    public function render_admin_chatbot_ui() {
        $chat_title = get_option('wacb_chat_title', 'AIチャットボット');
        $icon_text_enable = get_option('wacb_icon_text_enable', 0);
        $icon_text = get_option('wacb_icon_text', 'AIチャットに質問');
        $icon_class = $icon_text_enable ? 'wacb-chat-icon has-text' : 'wacb-chat-icon';
        $icon_image = get_option('wacb_icon_image', '');
        
        // クラス名の生成
        if (!empty($icon_image)) {
            $icon_class .= ' has-custom-image';
        }
        ?>
        <div id="wacb-chat-icon" class="<?php echo esc_attr($icon_class); ?>">
            <?php if (!empty($icon_image)): ?>
                <img src="<?php echo esc_url($icon_image); ?>" alt="Chat Icon" class="wacb-custom-icon-img" />
            <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="30px" height="30px"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 12H5v-2h14v2zm0-3H5V9h14v2zm0-3H5V6h14v2z"/></svg>
            <?php endif; ?>
            
            <?php if ($icon_text_enable): ?>
                <span class="wacb-icon-text"><?php echo esc_html($icon_text); ?></span>
            <?php endif; ?>
        </div>

        <div id="wacb-chat-window" style="display: none;">
            <div id="wacb-chat-header">
                <span><?php echo esc_html($chat_title); ?></span>
                <span id="wacb-close-btn">&times;</span>
            </div>
            <div id="wacb-chat-log">
                <div class="wacb-message wacb-bot">
                    <?php 
                    $start_message = get_option('wacb_start_message', 'ご質問がございましたら、お気軽にご入力ください。'); 
                    ?>
                    <p><?php echo esc_html($start_message); ?></p>
                </div>
            </div>
            <div id="wacb-chat-input">
                <input type="text" id="wacb-user-message" placeholder="質問を入力してください..." />
                <button id="wacb-send-btn">送信</button>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('wacb_settings_group', 'wacb_enable_chatbot');
        register_setting('wacb_settings_group', 'wacb_chat_title');
        register_setting('wacb_settings_group', 'wacb_icon_text');
        register_setting('wacb_settings_group', 'wacb_icon_text_enable');
        register_setting('wacb_settings_group', 'wacb_start_message'); 
        register_setting('wacb_settings_group', 'wacb_not_found_message');
        register_setting('wacb_settings_group', 'wacb_icon_image'); 
        register_setting('wacb_settings_group', 'wacb_color_icon');
        register_setting('wacb_settings_group', 'wacb_color_header');
        register_setting('wacb_settings_group', 'wacb_color_send_btn');
        register_setting('wacb_settings_group', 'wacb_color_user_bg');
        register_setting('wacb_settings_group', 'wacb_color_bot_bg');
        register_setting('wacb_settings_group', 'wacb_terms_content');
        register_setting('wacb_settings_group', 'wacb_schedule_url');
        register_setting('wacb_settings_group', 'wacb_access_url');
        register_setting('wacb_settings_group', 'wacb_greeting_url');
        register_setting('wacb_settings_group', 'wacb_reservation_info');
        register_setting('wacb_settings_group', 'wacb_webform_url');
        register_setting('wacb_settings_group', 'wacb_additional_info');
    }

    public function render_page() {
        $chat_title = get_option('wacb_chat_title', 'AIチャットボット');
        $icon_text = get_option('wacb_icon_text', 'AIチャットに質問');
        $icon_text_enable = get_option('wacb_icon_text_enable', 0);
        $icon_image = get_option('wacb_icon_image', '');
        $start_message = get_option('wacb_start_message', 'ご質問がございましたら、お気軽にご入力ください。');
        $not_found_message = get_option('wacb_not_found_message', '申し訳ありません。ご質問いただいた内容に関する情報は、ウェブサイト上では見つかりませんでした。詳細については、お電話にてお問い合わせください。');
        $color_icon     = get_option('wacb_color_icon', '#0073aa');
        $color_header   = get_option('wacb_color_header', '#0073aa');
        $color_send_btn = get_option('wacb_color_send_btn', '#0073aa');
        $color_user_bg  = get_option('wacb_color_user_bg', '#0073aa');
        $color_bot_bg   = get_option('wacb_color_bot_bg', '#e9e9eb');
        
        $default_terms = <<<EOD
<h2>AIチャットボット利用規約</h2>
<p>本機能（以下「本サービス」）を利用する際は、以下の内容に同意したものとみなされます。</p>
<h3>1. 医療情報に関する免責</h3>
<p>本サービスはAIによる自動回答システムです。<strong>医師による診断ではありません。</strong></p>
<ul>
    <li>緊急の症状がある場合は、直ちに医療機関へご連絡ください。</li>
    <li>回答の正確性は保証されません。正確な診療時間等は直接お電話にてご確認ください。</li>
</ul>
<h3>2. 個人情報の入力禁止</h3>
<p>チャット欄には、<strong>お名前、電話番号、詳細な病歴などの個人情報は絶対に入力しないでください。</strong></p>
<ul>
    <li>入力内容はAIの回答生成および品質向上のために利用されます。</li>
    <li>システム改善のため、入力履歴は匿名化された状態で記録されます。</li>
</ul>
<h3>3. 免責事項</h3>
<p>当院は、本サービスの利用に起因する損害について一切の責任を負いません。利用者の責任においてご利用ください。</p>
EOD;
        $terms_content = get_option('wacb_terms_content', $default_terms);

        $schedule_url = get_option('wacb_schedule_url', '');
        $access_url = get_option('wacb_access_url', '');
        $greeting_url = get_option('wacb_greeting_url', '');
        $reservation_info = get_option('wacb_reservation_info', '');
        $webform_url = get_option('wacb_webform_url', '');
        $additional_info = get_option('wacb_additional_info', '');
        ?>
        <div class="wrap">
            <h1>Wevery! AIチャットボット設定</h1>
            
            <p>右下部のアイコンからチャットボットをお試しできます。（※このプレビューは「チャットボットの表示」設定がOFFでも表示されます）</p>

            <nav class="nav-tab-wrapper">
                <a href="#tab-basic" class="nav-tab">基本設定</a>
                <a href="#tab-accuracy" class="nav-tab">回答の精度を高める</a>
                <a href="#tab-history" class="nav-tab">質問履歴</a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields('wacb_settings_group'); ?>
                <?php do_settings_sections('wacb_settings_group'); ?>

                <div id="tab-basic" class="tab-content">
                    <h2>基本設定</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">チャットボットの表示</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wacb_enable_chatbot" value="1" <?php checked(get_option('wacb_enable_chatbot'), 1); ?> />
                                    サイト上にチャットボットを表示する
                                </label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">チャットのタイトル</th>
                            <td>
                                <input type="text" name="wacb_chat_title" value="<?php echo esc_attr($chat_title); ?>" class="regular-text" />
                                <p class="description">チャットウィンドウのヘッダーに表示される名前です。(AIの返答者名としても利用されます)</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">アイコン表示テキスト</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wacb_icon_text_enable" value="1" <?php checked($icon_text_enable, 1); ?> />
                                    アイコンの横にテキストを表示する
                                </label>
                                <br>
                                <input type="text" name="wacb_icon_text" value="<?php echo esc_attr($icon_text); ?>" class="regular-text" />
                                <p class="description">（例: AIチャットに質問, ご質問はこちら）</p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">アイコンを画像にする</th>
                            <td>
                                <input type="text" name="wacb_icon_image" id="wacb_icon_image" value="<?php echo esc_attr($icon_image); ?>" class="regular-text" readonly />
                                <button type="button" class="button" id="wacb-upload-icon-btn">画像を選択</button>
                                <button type="button" class="button" id="wacb-remove-icon-btn" style="<?php echo empty($icon_image) ? 'display:none;' : ''; ?>">削除</button>
                                <p class="description">画像を設定すると、デフォルトのSVGアイコンの代わりに表示されます。</p>
                                <div id="wacb-icon-preview-wrapper">
                                    <?php if (!empty($icon_image)): ?>
                                        <img id="wacb-icon-preview-img" src="<?php echo esc_attr($icon_image); ?>" />
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">スタート時の文章</th>
                            <td>
                                <textarea name="wacb_start_message" rows="3" class="large-text"><?php echo esc_textarea($start_message); ?></textarea>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">回答が見つからなかった時の文章</th>
                            <td>
                                <textarea name="wacb_not_found_message" rows="3" class="large-text"><?php echo esc_textarea($not_found_message); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <h2>カラー設定</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">アイコン背景色</th>
                            <td>
                                <input type="text" name="wacb_color_icon" value="<?php echo esc_attr($color_icon); ?>" class="wacb-color-picker" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">ヘッダー背景色</th>
                            <td>
                                <input type="text" name="wacb_color_header" value="<?php echo esc_attr($color_header); ?>" class="wacb-color-picker" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">送信ボタン背景色</th>
                            <td>
                                <input type="text" name="wacb_color_send_btn" value="<?php echo esc_attr($color_send_btn); ?>" class="wacb-color-picker" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">質問 (ユーザー) の吹き出し</th>
                            <td>
                                <input type="text" name="wacb_color_user_bg" value="<?php echo esc_attr($color_user_bg); ?>" class="wacb-color-picker" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">回答 (ボット) の吹き出し</th>
                            <td>
                                <input type="text" name="wacb_color_bot_bg" value="<?php echo esc_attr($color_bot_bg); ?>" class="wacb-color-picker" />
                            </td>
                        </tr>
                    </table>

                    <h2>チャットボット利用規約</h2>
                    <p>チャットウィンドウの「規約」リンクをクリックした際に表示される文章です。</p>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">規約本文</th>
                            <td>
                                <textarea name="wacb_terms_content" rows="15" class="large-text code"><?php echo esc_textarea($terms_content); ?></textarea>
                            </td>
                        </tr>
                    </table>
                    
                </div> <div id="tab-accuracy" class="tab-content">
                    <h2>重要ページの固定回答設定</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">診療時間・曜日のページ</th>
                            <td>
                                <label>URL: <input type="url" name="wacb_schedule_url" value="<?php echo esc_attr($schedule_url); ?>" class="regular-text" /></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">アクセス・場所のページ</th>
                            <td>
                                <label>URL: <input type="url" name="wacb_access_url" value="<?php echo esc_attr($access_url); ?>" class="regular-text" /></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">院長・医師のページ</th>
                            <td>
                                <label>URL: <input type="url" name="wacb_greeting_url" value="<?php echo esc_attr($greeting_url); ?>" class="regular-text" /></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">予約・連絡先</th>
                            <td>
                                <textarea name="wacb_reservation_info" rows="5" class="large-text"><?php echo esc_textarea($reservation_info); ?></textarea>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">ウェブ問診ページ</th>
                            <td>
                                <label>URL: <input type="url" name="wacb_webform_url" value="<?php echo esc_attr($webform_url); ?>" class="regular-text" /></label>
                            </td>
                        </tr>
                    </table>
                    <h2>追加学習情報 (RAG)</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">追加学習情報</th>
                            <td>
                                <textarea name="wacb_additional_info" rows="10" class="large-text"><?php echo esc_textarea($additional_info); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div> <div id="tab-history" class="tab-content">
                    <h2>質問履歴</h2>
                    <?php $this->render_history_tab(); ?>
                </div> <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    private function render_history_tab() {
        $query = new WP_Query([
            'post_type' => 'wacb_log',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC', 
            'post_status' => 'publish',
        ]);

        echo '<table class="wacb-log-table">';
        echo '<thead><tr><th>時間</th><th>質問</th><th>回答</th></tr></thead>';
        echo '<tbody>';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                echo '<tr>';
                echo '<td>' . esc_html(get_the_date('Y-m-d H:i:s')) . '</td>';
                echo '<td>' . esc_html(get_the_title()) . '</td>';
                echo '<td>' . wp_kses_post(get_the_content()) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">ログはまだありません。</td></tr>';
        }
        
        echo '</tbody></table>';
        wp_reset_postdata();
    }
}