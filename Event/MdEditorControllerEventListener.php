<?php
/**
 * MdEditorControllerEventListener
 *
 * baserCMS 4系のイベントシステム（BcControllerEventListener）に基づくイベントリスナー
 * 
 * - データ送信・保存・プレビュー時（initialize）における文章内PHPタグの無害化処理
 * - 固定ページプレビュー時のコア未定義インデックスバグ（contents_tmp不具合）の自動調停
 * - ブログ記事詳細（detail）保存時に専用切り出しアンカーマーカーの付与
 * - 管理画面エディタ表示前（beforeRender）にシールド保護されたPHPタグの自動復元
 * - プラグインアンインストール（無効化、削除）時にエディタタイプをリセット処理
 *
 * @package    MdEditor
 * @subpackage Event
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */
class MdEditorControllerEventListener extends BcControllerEventListener {

    /**
     * フックするイベントの定義
     *
     * @var array
     */
    public $events = array(
        'initialize',
        'beforeRender',
        'shutdown'
    );

    /**
     * コア初期化タイミング（initialize）
     * 無効要求（Ajax）を傍受し、データベースのエディタ設定を（BcCkeditor）に戻す
     *
     * @param  CakeEvent $event
     * @return void
     */
    public function initialize(CakeEvent $event) {
        $controller = $event->subject();

        // 無効ボタン押下時、データベースを直接操作してエディタ設定をBcCkeditorに強制上書きする処理
        if ($controller->name === 'Plugins' && $controller->request->action === 'admin_index') {
            if (!empty($controller->request->query['action']) && $controller->request->query['action'] === 'mdeditor_force_reset') {
                
                $db = ConnectionManager::getDataSource('default');
                $success = false;

                if ($db) {
                    $tableName = $db->fullTableName('site_configs');
                    
                    // SQLでエディタの設定値を正規の「BcCkeditor」に上書き
                    $sql = "UPDATE {$tableName} SET `value` = 'BcCkeditor' WHERE `name` = 'editor'";
                    $db->execute($sql);
                    $success = true;

                    if (function_exists('clearCache')) {
                        clearCache();
                    }
                }
                
                $controller->response->type('json');
                $controller->response->body(json_encode(array('success' => $success)));
                $controller->response->send();
                exit();
            }
        }

        // フォーム送信・保存・プレビュー通信（POST/PUT）時のデータ保護処理
        if ($controller->request->is(array('post', 'put'))) {
            
            $search  = array('<?php', '<? ', '<?\n', '<?\r', '?>');
            $replace = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', "[#PHP_START_SHORT#]\n", "[#PHP_START_SHORT#]\r", '[#PHP_END#]');

            // A. 固定ページ（Page）の保存 ＆ プレビュー調停処理
            if (!empty($controller->request->data['Page'])) {
                $rawText = isset($controller->request->data['Page']['contents']) ? $controller->request->data['Page']['contents'] : '';
                $escapedText = str_replace($search, $replace, $rawText);
                $controller->request->data['Page']['contents'] = $escapedText;
                $controller->request->data['Page']['contents_tmp'] = $escapedText;
                if (isset($_POST['data']['Page'])) {
                    $_POST['data']['Page']['contents'] = $escapedText;
                    $_POST['data']['Page']['contents_tmp'] = $escapedText;
                }
            }

            // B. ブログ詳細（BlogPost: 本文詳細 detail 領域）の調停処理
            if (!empty($controller->request->data['BlogPost']['detail'])) {
                $rawText = trim($controller->request->data['BlogPost']['detail']);
                $cleanText = str_replace(array('<!--MDE_BODY_START-->', '<!--MDE_BODY_END-->'), '', $rawText);
                $cleanText = trim($cleanText);
                $markedText = "<!--MDE_BODY_START-->\n\n" . $cleanText . "\n\n<!--MDE_BODY_END-->";
                $escapedText = str_replace($search, $replace, $markedText);
                $controller->request->data['BlogPost']['detail'] = $escapedText;
                if (isset($_POST['data']['BlogPost']['detail'])) {
                    $_POST['data']['BlogPost']['detail'] = $escapedText;
                }
            }
        }

        // 対象コントローラーに対するカスタムヘルパー（MdEditorHelper）の自動インジェクション
        if (in_array($controller->name, array('Pages', 'PagesAdmin', 'Contents', 'Blog', 'Archives'))) {
            if (!in_array('MdEditor.MdEditor', $controller->helpers)) { $controller->helpers[] = 'MdEditor.MdEditor'; }
        }
    }

    /**
     * コントローラー処理終了・レスポンス生成タイミング
     * 最終出力HTMLに対して、安全なアラート割り込み ＆ トークン引き継ぎ用JavaScriptを強制注入
     *
     * @param  CakeEvent $event
     * @return void
     */
    public function shutdown(CakeEvent $event) {
        $controller = $event->subject();

        if ($controller->name === 'Plugins' && $controller->request->action === 'admin_index') {
            $html = $controller->response->body();
            if (empty($html)) { return; }

            $currentUrl = $controller->request->here;

            // 割り込み用のJavaScript（コアセキュリティを継承）
            $interceptJs = "
<script type=\"text/javascript\">
$(function() {
    // MdEditorの無効ボタンのHTML（aタグ）をhrefから特定
    var \$deleteBtn = $('a.btn-delete[href*=\"/plugins/ajax_delete/MdEditor\"], a.btn-delete[href*=\"/plugins/ajax_delete/MDEditor\"]');

    if (\$deleteBtn.length > 0) {
        
        // 無効ボタンに対するコア側のイベント（トークン付きAjax）を一時的に安全な変数へ退避
        var originalEvents = $._data(\$deleteBtn.get(0), 'events');
        var originalClickHandlers = [];
        
        if (originalEvents && originalEvents.click) {
            $.each(originalEvents.click, function(index, handlerObj) {
                originalClickHandlers.push(handlerObj.handler);
            });
            // コア側のクリックイベントを一旦安全に解除
            \$deleteBtn.off('click');
        }

        // 無効ボタンに独自イベントをバインド
        \$deleteBtn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            // プラグイン専用の確認ダイアログを表示
            var result = window.confirm(
                \"– MdEditorプラグインからのお知らせ –\\n\\n\" +
                \"現在、基本設定＞エディタ設定で「Markdownエディタ」が選択されている可能性があります。\\n\\n\" +
                \"安全のためエディタ設定を標準の「CKEditor」に戻した上で、本プラグインの「無効化」を実行します。\\n\\n\" +
                \"処理を続行しますか？\"
            );
            
            if (result) {
                // 同期（Ajax）でPHP側の低レイヤーSQLリセット処理を最優先実行
                $.ajax({
                    url: '{$currentUrl}',
                    type: 'GET',
                    data: { 'action': 'mdeditor_force_reset' },
                    async: false,
                    dataType: 'json'
                });

                // 退避させていたコア側のAjax削除イベント（セキュリティトークン付き）を実行
                var currentElement = this;
                var currentEvent = e;
                if (originalClickHandlers.length > 0) {
                    $.each(originalClickHandlers, function(index, handler) {
                        handler.call(currentElement, currentEvent);
                    });
                }
            }
            
            return false;
        });
    }
});
</script>
";

            // HTMLの </body> タグの直前に強制ねじ込み
            if (strpos($html, '</body>') !== false) {
                $html = str_replace('</body>', $interceptJs . '</body>', $html);
            } else {
                $html .= $interceptJs;
            }

            $controller->response->body($html);
        }
    }

    /**
     * ビュー描画直前タイミング（beforeRender）でのデータ復元処理
     *
     * @param  CakeEvent $event
     * @return void
     */
    public function beforeRender(CakeEvent $event) {
        $controller = $event->subject();

        if (!empty($controller->viewVars)) {
            if (isset($controller->viewVars['page']['Page']['contents'])) {
                $controller->viewVars['page']['Page']['contents'] = $this->_unescapePhpTags($controller->viewVars['page']['Page']['contents']);
            }
            if (isset($controller->viewVars['contents'])) {
                $controller->viewVars['contents'] = $this->_unescapePhpTags($controller->viewVars['contents']);
            }
            if (isset($controller->request->data['Page']['contents'])) {
                $controller->request->data['Page']['contents'] = $this->_unescapePhpTags($controller->request->data['Page']['contents']);
            }
        }
    }

    /**
     * 独自保護コードからPHPタグへの逆置換を実行する内部メソッド
     *
     * @param  string $text
     * @return string
     */
    protected function _unescapePhpTags($text) {
        if (empty($text) || !is_string($text)) { return $text; }
        
        $search  = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', '[#PHP_END#]');
        $replace = array('<?php', '<?', '?>');
        return str_replace($search, $replace, $text);
    }
}
