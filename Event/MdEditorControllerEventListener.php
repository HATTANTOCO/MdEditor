<?php
/**
 * MdEditorControllerEventListener
 *
 * baserCMS 4系のイベントシステム（BcControllerEventListener）に基づくイベントリスナー。
 * - データ送信・保存・プレビュー時（initialize）における文章内PHPタグの動的無害化処理
 * - 固定ページプレビュー時のコア未定義インデックスバグ（contents_tmp不具合）の自動調停
 * - ブログ記事詳細（detail）保存時における、専用切り出しアンカーマーカーの常時付与
 * - 管理画面エディタ表示前（beforeRender）における、シールド保護されたPHPタグの自動復元
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
        'beforeRender'
    );

    /**
     * コア初期化タイミング（initialize）でのデータ調停およびフィルタリング
     *
     * @param  CakeEvent $event
     * @return void
     */
    public function initialize(CakeEvent $event) {
        $controller = $event->subject();

        // フォーム送信・保存・プレビュー通信（POST/PUT）時のデータ保護処理
        if ($controller->request->is(array('post', 'put'))) {
            
            // PHPタグ無害化変換用マッピング配列
            $search  = array('<?php', '<? ', '<?\n', '<?\r', '?>');
            $replace = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', "[#PHP_START_SHORT#]\n", "[#PHP_START_SHORT#]\r", '[#PHP_END#]');

            // --- A. 固定ページ（Page）の保存 ＆ プレビュー調停処理 ---
            if (!empty($controller->request->data['Page'])) {
                $rawText = isset($controller->request->data['Page']['contents']) ? $controller->request->data['Page']['contents'] : '';
                
                // PHP構文エラーによるシステム停止を防ぐため、実行用PHPタグを独自コードへ変換保護
                $escapedText = str_replace($search, $replace, $rawText);
                
                // ページプレビュー時のコア内部（PagesController）での Undefined index エラーを回避するため、
                // プラグイン側で先回りして「contents_tmp」を生成しバッファへ強制挿入
                $controller->request->data['Page']['contents'] = $escapedText;
                $controller->request->data['Page']['contents_tmp'] = $escapedText;
                
                if (isset($_POST['data']['Page'])) {
                    $_POST['data']['Page']['contents'] = $escapedText;
                    $_POST['data']['Page']['contents_tmp'] = $escapedText;
                }
            }

            // --- B. ブログ詳細（BlogPost: 本文詳細 detail 領域）の調停処理 ---
            if (!empty($controller->request->data['BlogPost']['detail'])) {
                $rawText = trim($controller->request->data['BlogPost']['detail']);
                
                // 高速な文字列置換による、既存マーカーの重複防止クレンジング
                $cleanText = str_replace(array('<!--MDE_BODY_START-->', '<!--MDE_BODY_END-->'), '', $rawText);
                $cleanText = trim($cleanText);

                // フロントでのスライスパース処理用アンカーマーカーを完全な独立行としてドッキング
                $markedText = "<!--MDE_BODY_START-->\n\n" . $cleanText . "\n\n<!--MDE_BODY_END-->";

                // マーカー付与済みのテキストに対してPHPタグ無害化シールドを適用
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
     * ビュー描画直前タイミング（beforeRender）でのデータ復元処理
     *
     * @param  CakeEvent $event
     * @return void
     */
    public function beforeRender(CakeEvent $event) {
        $controller = $event->subject();

        // エディタ表示画面（管理画面等）への受け渡し直前に、保護コードを元のPHPタグ形式へ復元
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
