<?php
if (!defined('ABSPATH')) exit;

class WACB_First_Setup {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_wizard_assets']);
        add_action('admin_footer', [$this, 'render_wizard_modal']);
    }

    public function enqueue_wizard_assets($hook) {
        if (strpos($hook, 'wacb-settings') === false) return;
        $existing_info = get_option('wacb_additional_info', '');
        if (!empty(trim($existing_info))) return;

        wp_enqueue_style('wacb-wizard-style', WACB_PLUGIN_URL . 'assets/css/setup-wizard.css', [], '1.1.0');
        wp_enqueue_script('wacb-wizard-script', WACB_PLUGIN_URL . 'assets/js/setup-wizard.js', ['jquery'], '1.1.0', true);
        $qa_data = include WACB_PLUGIN_DIR . 'includes/qa-items.php';
        wp_localize_script('wacb-wizard-script', 'WACB_Wizard_Data', $qa_data);
    }

    public function render_wizard_modal() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wacb-settings') return;
        $existing_info = get_option('wacb_additional_info', '');
        if (!empty(trim($existing_info))) return;
        ?>
        <div id="wacb-wizard-overlay">
            <div id="wacb-wizard-modal">
                
                <div class="wacb-wizard-header">
                    <h2>チャットボット精度向上ウィザード</h2>
                    <p>AIの回答精度を高めるために、いくつかの質問にお答えください。<br>回答いただいた内容は「追加学習情報」に自動的に入力されます。</p>
                </div>

                <div id="wacb-wizard-step-intro" class="wacb-wizard-step">
                    <h3>設定を始める前に</h3>
                    <p>より効果的にチャットボットを活用いただくため、まずはこちらの解説動画をご覧ください。<br>
                    動画視聴後、下のボタンから設定へお進みください。</p>
                    
                    <div class="wacb-video-container">
                        <iframe width="560" height="315" src="https://www.youtube.com/embed/yU15jmVGZp8" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    </div>

                    <div class="wacb-wizard-nav">
                        <span></span> <button type="button" class="button button-primary button-hero" id="wacb-btn-intro-next">動画を見ました（設定へ進む）</button>
                    </div>
                </div>
                <div id="wacb-wizard-step-specialty" class="wacb-wizard-step" style="display:none;">
                    <h3>診療科目を選択してください（複数選択可）</h3>
                    <p>該当する診療科目をすべてチェックしてください。選択した科目の質問が自動的に追加されます。</p>
                    
                    <div id="wacb-specialty-checkboxes">
                        </div>

                    <div class="wacb-wizard-nav">
                        <button type="button" class="button" id="wacb-btn-back-intro">戻る</button>
                        <button type="button" class="button button-primary button-hero" id="wacb-btn-start">質問を開始する</button>
                    </div>
                </div>

                <div id="wacb-wizard-step-qa" class="wacb-wizard-step" style="display:none;">
                    <div class="wacb-progress-text">質問 <span id="wacb-current-q">1</span> / <span id="wacb-total-q">10</span></div>
                    <div class="wacb-progress-bar"><div id="wacb-progress-fill"></div></div>
                    <h3 id="wacb-question-text">ここに質問が表示されます</h3>
                    <textarea id="wacb-answer-input" rows="5" placeholder="回答を入力してください..."></textarea>
                    <p class="description">※ 回答しない場合は、空欄のまま「次へ」ボタンを押してください。</p>
                    <div class="wacb-wizard-nav">
                        <button type="button" class="button" id="wacb-btn-prev">前へ</button>
                        <button type="button" class="button button-primary button-hero" id="wacb-btn-next">次へ</button>
                    </div>
                </div>

                <div id="wacb-wizard-step-finish" class="wacb-wizard-step" style="display:none;">
                    <h3>設定が完了しました！</h3>
                    <p>ご回答ありがとうございます。<br>以下のボタンを押すと、内容が設定画面に反映され、自動的に保存されます。</p>
                    <div class="wacb-wizard-nav">
                        <button type="button" class="button" id="wacb-btn-back-review">戻って確認</button>
                        <button type="button" class="button button-primary button-hero" id="wacb-btn-finish">反映して保存</button>
                    </div>
                </div>

                <div id="wacb-wizard-close" title="ウィザードを閉じる">×</div>
            </div>
        </div>
        <?php
    }
}