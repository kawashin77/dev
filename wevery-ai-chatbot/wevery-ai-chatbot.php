<?php
/**
 * Plugin Name: Wevery! AIチャットボット
 * ... (ヘッダー情報はそのまま)
 */

if (!defined('ABSPATH')) exit;

define('WACB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WACB_PLUGIN_URL', plugin_dir_url(__FILE__));

// 1. 設定ページの読み込み
require_once WACB_PLUGIN_DIR . 'includes/class-settings-page.php';
// ★ 追加: 初回セットアップウィザード
require_once WACB_PLUGIN_DIR . 'includes/class-first-setup.php';
// 2. APIハンドラの読み込み
require_once WACB_PLUGIN_DIR . 'includes/class-api-handler.php';
// 3. フロントエンド（UI）の読み込み
require_once WACB_PLUGIN_DIR . 'includes/class-frontend-chatbot.php';

// 各クラスの初期化
if (is_admin()) {
    new WACB_Settings_Page();
    new WACB_First_Setup(); // ★ 追加
}
new WACB_API_Handler();
new WACB_Frontend_Chatbot();

/**
 * ★ 新規追加: ログ保存用のカスタム投稿タイプ 'wacb_log' を登録
 */
function wacb_register_log_post_type() {
    register_post_type('wacb_log', [
        'public' => false,
        'show_ui' => false,
        'label' => 'Chatbot Log',
        'supports' => ['title', 'content'],
        'show_in_rest' => false,
    ]);
}
add_action('init', 'wacb_register_log_post_type');