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
     * コントローラー処理終了・レスポンス生成タイミング（shutdown）
     * 最終出力HTMLに対して、安全なアラート割り込み ＆ 最優先順序シャッフルJavaScriptを動的に一括流し込み
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

            // JavaScriptの出力生成
            $interceptJs = "";
            $interceptJs .= "\n<script type=\"text/javascript\">\n";
            $interceptJs .= "document.addEventListener('DOMContentLoaded', function() {\n";
            $interceptJs .= "    // MdEditorの削除ボタンの要素を取得\n";
            $interceptJs .= "    var deleteBtn = document.querySelector('a.btn-delete[href*=\"/plugins/ajax_delete/MdEditor\"], a.btn-delete[href*=\"/plugins/ajax_delete/MDEditor\"]');\n";
            $interceptJs .= "\n";
            $interceptJs .= "    if (deleteBtn) {\n";
            $interceptJs .= "        // 後続のイベントハンドラより前に処理を実行するため、イベントキャプチャ（true）を使用\n";
            $interceptJs .= "        deleteBtn.addEventListener('click', function(e) {\n";
            $interceptJs .= "            var currentElement = this;\n";
            $interceptJs .= "            \n";
            $interceptJs .= "            // 二重実行防止のフラグがあれば処理をスキップ\n";
            $interceptJs .= "            if (currentElement.dataset.mdResetPassed) return;\n";
            $interceptJs .= "            \n";
            $interceptJs .= "            // コアや他プラグインによる既存のイベント発火を一時停止\n";
            $interceptJs .= "            e.stopImmediatePropagation();\n";
            $interceptJs .= "            e.preventDefault();\n";
            $interceptJs .= "            \n";
            $interceptJs .= "            var isConfirm = confirm(\n";
            $interceptJs .= "                \"– MdEditorからの確認 –\\n\\n\" +\n";
            $interceptJs .= "                \"現在、基本設定＞エディタ設定で「Markdownエディタ」が選択されている可能性があります。\\n\\n\" +\n";
            $interceptJs .= "                \"安全のためエディタ設定を標準の「CKEditor」に戻した上で、本プラグインの「無効化」を実行します。\\n\\n\" +\n";
            $interceptJs .= "                \"処理を続行しますか？\"\n";
            $interceptJs .= "            );\n";
            $interceptJs .= "            \n";
            $interceptJs .= "            if (isConfirm) {\n";
            $interceptJs .= "                // 非同期通信でエディタ設定のリセット処理を実行\n";
            $interceptJs .= "                fetch('{$currentUrl}?action=mdeditor_force_reset', {\n";
            $interceptJs .= "                    method: 'GET',\n";
            $interceptJs .= "                    credentials: 'same-origin'\n";
            $interceptJs .= "                })\n";
            $interceptJs .= "                .then(function() {\n";
            $interceptJs .= "                    // リセット完了後、フラグを付与して本来の削除イベントを再実行\n";
            $interceptJs .= "                    currentElement.dataset.mdResetPassed = 'true';\n";
            $interceptJs .= "                    currentElement.click();\n";
            $interceptJs .= "                })\n";
            $interceptJs .= "                .catch(function(error) {\n";
            $interceptJs .= "                    console.error('[MdEditor] Reset Error:', error);\n";
            $interceptJs .= "                    currentElement.dataset.mdResetPassed = 'true';\n";
            $interceptJs .= "                    currentElement.click();\n";
            $interceptJs .= "                });\n";
            $interceptJs .= "            }\n";
            $interceptJs .= "        }, true);\n";
            $interceptJs .= "    }\n";
            $interceptJs .= "});\n";
            $interceptJs .= "</script>\n";

            // HTMLの </body> タグの直前に強制挿入
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
