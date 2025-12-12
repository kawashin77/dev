<?php
if (!defined('ABSPATH')) exit;

// ★ 修正: DBから設定値を取得。なければデフォルトを表示。
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
?>

<div id="wacb-tac-modal" style="display: none;">
    <div class="wacb-tac-overlay"></div>
    <div class="wacb-tac-content">
        <span id="wacb-tac-close-btn">&times;</span>
        
        <div class="wacb-tac-body">
            <?php echo wp_kses_post($terms_content); ?>
        </div>

        <div class="wacb-tac-footer">
            <button id="wacb-tac-agree-close">閉じる</button>
        </div>
    </div>
</div>