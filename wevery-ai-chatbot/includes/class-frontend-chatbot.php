<?php
if (!defined('ABSPATH')) exit;

class WACB_Frontend_Chatbot {

    public function __construct() {
        // 設定が有効 かつ 管理画面ではない場合のみ、フックを登録
        if (get_option('wacb_enable_chatbot') == 1 && !is_admin()) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('wp_footer', [$this, 'render_chatbot_ui']);
        }
    }

    /**
     * CSSとJSを読み込む
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'wacb-chatbot-style',
            WACB_PLUGIN_URL . 'assets/css/chatbot.css',
            [],
            '1.0.6' // ★ バージョン更新
        );
        
        // 5つのカラー設定を取得
        $color_icon     = get_option('wacb_color_icon', '#0073aa');
        $color_header   = get_option('wacb_color_header', '#0073aa');
        $color_send_btn = get_option('wacb_color_send_btn', '#0073aa');
        $color_user_bg  = get_option('wacb_color_user_bg', '#0073aa');
        $color_bot_bg   = get_option('wacb_color_bot_bg', '#e9e9eb');

        // 5つのCSSカスタムプロパティを定義
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

    /**
     * チャットボットのHTMLをフッターに出力
     */
    public function render_chatbot_ui() {
        $icon_text_enable = get_option('wacb_icon_text_enable', 0);
        $icon_text = get_option('wacb_icon_text', 'AIチャットに質問');
        $icon_image = get_option('wacb_icon_image', '');

        // クラス名の生成
        $icon_class = 'wacb-chat-icon';
        if ($icon_text_enable) {
            $icon_class .= ' has-text';
        }
        // ★ 修正: 画像がある場合は専用クラスを追加
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
            <?php $chat_title = get_option('wacb_chat_title', 'AIチャットボット'); ?>
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

            <div id="wacb-privacy-link">
                利用は<a href="#" id="wacb-open-tac">規約</a>に同意したものとみなされます。<br>
                個人情報は入力しないでください。
            </div>
        </div>

        <?php require_once WACB_PLUGIN_DIR . 'includes/chatbot-tac.php'; ?>

        <?php
    }
}