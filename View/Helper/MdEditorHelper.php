<?php
/**
 * MdEditorHelper
 *
 * EasyMDEの管理画面統合、およびフロントエンド出力時のMarkdown自動変換を制御するメインヘルパー
 * 既存テーマのレイアウト要素（コメント欄やフッターなど）への意図しないパース影響を隔離する
 * カプセル化パース機構を搭載
 *
 * @package    MdEditor
 * @subpackage View.Helper
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */

App::uses('AppHelper', 'View/Helper');

class MdEditorHelper extends AppHelper {

    /**
     * 利用するコアヘルパーの登録
     *
     * @var array
     */
    public $helpers = array('BcBaser', 'Form');

    /**
     * 管理画面エディタ（EasyMDE）初期化エリアの生成と必要なアセットのロード
     *
     * @param  string $fieldId 対象フィールドのDOM要素ID
     * @param  array  $options エディタ拡張用オプション
     * @return string
     */
    public function editor($fieldId, $options = array()) {
        if (isset($options['editorStyles'])) { unset($options['editorStyles']); }
        if (isset($options['type'])) { unset($options['type']); }
        $this->BcBaser->css('MdEditor.easymde.min', array('inline' => false));
        $this->BcBaser->css('/md_editor/css/mde-preview.css', array('inline' => false));
        $this->BcBaser->js('MdEditor.easymde.min', false, array('inline' => false));
        $html = $this->Form->textarea($fieldId, $options);
        $script = $this->_buildMdeScript($this->Form->domId($fieldId));
        return $html . "<script type=\"text/javascript\">{$script}</script>";
    }

    /**
     * CKEditor用メソッドのエイリアス（互換性確保用）
     *
     * @param  string $fieldId
     * @param  array  $options
     * @return string
     */
    public function ckeditor($fieldId, $options = array()) { return $this->editor($fieldId, $options); }

    /**
     * 画面出力直前の最終バッファ制御（レイアウト確定タイミング）
     * - 管理画面（admin）：フォーム表示前の無害化独自コード復元処理
     * - フロント画面（非admin）：各コンポーネントへの干渉を防ぐカプセル化パース処理
     *
     * @param  string|null $viewFile ビューファイル名
     * @return void
     */
    public function beforeLayout($viewFile = null) {
        // --- 1. 管理画面（admin）：エディタ表示時のPHPタグ復元 ---
        if (isset($this->request->params['admin']) && $this->request->params['admin']) {
            $this->BcBaser->css('MdEditor.easymde.min', array('inline' => false));
            $this->BcBaser->css('/md_editor/css/mde-preview.css', array('inline' => false));
            $this->BcBaser->js('MdEditor.easymde.min', false, array('inline' => false));
            $this->BcBaser->scriptBlock($this->_buildMdeScript('PageContents'), array('inline' => false));
            
            if (isset($this->_View->Blocks)) {
                // PHP 8.0対応：引数へのnull侵入による型エラー（TypeError）を防止するため、文字列型（string）へ強制キャスト
                $rawContent = (string)$this->_View->Blocks->get('content');
                if ($rawContent !== '') {
                    $search  = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', '[#PHP_END#]');
                    $replace = array('<?php', '<?', '?>');
                    $restoredContent = str_replace($search, $replace, $rawContent);
                    $this->_View->Blocks->set('content', $restoredContent);
                }
            }
            return;
        }
        
        // --- 2. フロント公開画面（非admin）：カプセル化パース処理 ---
        if (empty($this->request->params['admin'])) {
            $this->BcBaser->css('/md_editor/css/atom-one-light.min.css', array('inline' => false));
            $this->BcBaser->css('/md_editor/css/mde-add.css', array('inline' => false));
            $this->BcBaser->js('/md_editor/js/highlight.min.js', false, array('defer' => 'defer', 'inline' => false));
            $this->BcBaser->js('/md_editor/js/mde-core.js', false, array('defer' => 'defer', 'inline' => false));

            // A. 固定ページ本文の強制パース
            if ($this->request->params['controller'] === 'pages' && isset($this->_View->Blocks)) {
                // PHP 8.0対応：引数へのnull侵入による型エラー（TypeError）を防止するため、文字列型（string）へ強制キャスト
                $rawMarkdown = (string)$this->_View->Blocks->get('content');
                if ($rawMarkdown !== '') {
                    $search  = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', '[#PHP_END#]');
                    $replace = array('<?php', '<?', '?>');
                    $restoredMarkdown = str_replace($search, $replace, $rawMarkdown);

                    $cleanMarkdown = str_replace(array('<br />', '<br>'), "\n", $restoredMarkdown);
                    $parsedHtml = $this->_toHtml($cleanMarkdown, true);
                    
                    $wrappedPageHtml = '<div class="mde-parsed-body">' . $parsedHtml . '</div>';
                    $this->_View->Blocks->set('content', $wrappedPageHtml);
                }
            }

            // B. ブログ詳細ページ（archives）：特定インデックス検索によるカプセル化パース処理
            if ($this->request->params['controller'] === 'blog' && $this->request->params['action'] === 'archives' && isset($this->_View->Blocks)) {
                
                $isSinglePage = false;
                if (isset($this->request->params['pass']) && !in_array($this->request->params['pass'], array('category', 'tag', 'author', 'date'))) {
                    $isSinglePage = true;
                }

                if ($isSinglePage) {
                    // PHP 8.0対応：strpos/substr等の引数へのnull侵入による型エラー（TypeError）を防止するため、文字列型（string）へ強制キャスト
                    $finalHtml = (string)$this->_View->Blocks->get('content');
                    
                    if ($finalHtml !== '') {
                        $startMarker = '<!--MDE_BODY_START-->';
                        $endMarker   = '<!--MDE_BODY_END-->';

                        $startPos = strpos($finalHtml, $startMarker);
                        $endPos   = strpos($finalHtml, $endMarker);

                        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
                            $bodyStartPos = $startPos + strlen($startMarker);
                            $bodyLength   = $endPos - $bodyStartPos;

                            $rawMarkdown = substr($finalHtml, $bodyStartPos, $bodyLength);

                            $search  = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', '[#PHP_END#]');
                            $replace = array('<?php', '<?', '?>');
                            $restoredMarkdown = str_replace($search, $replace, $rawMarkdown);
                            $cleanMarkdown = str_replace(array('<br />', '<br>'), "\n", $restoredMarkdown);

                            $isolatedMarkdown = "\n\n" . trim($cleanMarkdown) . "\n\n";
                            $parsedBodyHtml = $this->_toHtml($isolatedMarkdown, true);

                            // 元の画面全体のHTMLの「本文（Markdown）があった場所」だけを、専用の防衛コンテナで包んで置換
                            $beforeBody = substr($finalHtml, 0, $startPos);
                            $afterBody  = substr($finalHtml, $endPos + strlen($endMarker));
                            
                            // パース後のHTMLを「class="mde-parsed-body"」を持ったdivで完全にカプセル化（隔離）
                            $wrappedBodyHtml = '<div class="mde-parsed-body">' . $parsedBodyHtml . '</div>';
                            
                            // 綺麗に組み替えたHTMLをバッファへ上書き復元
                            $this->_View->Blocks->set('content', $beforeBody . $wrappedBodyHtml . $afterBody);
                        }
                    }
                }
            }
        }
    }

    /**
     * EasyMDEのコンフィグレーションおよびイベント初期化スクリプトの動的生成
     * - Ajaxを用いた画像非同期アップロードハンドラーの実装
     * - テキスト変更イベント（change）を監視したシームレスなフォームデータリアルタイム同期
     *
     * @param  string $domId 対象テキストエリアのDOM要素ID
     * @return string
     */
    protected function _buildMdeScript($domId) {
        $toolbarJs = $this->_buildToolbarJs();
        $uploadUrl = $this->BcBaser->getUrl('/admin/md_editor/md_editor_uploads/upload');
        
        return "
            jQuery(function($) {
                if (typeof EasyMDE !== 'undefined') {
                    var targetElement = document.getElementById('" . $domId . "');
                    if (targetElement && !targetElement.classList.contains('easymde-initialized')) {
                        var easyMDE = new EasyMDE({
                            element: targetElement,
                            autoDownloadFontAwesome: true,
                            spellChecker: false,
                            forceSync: true,
                            status: ['autosave', 'lines', 'words', 'cursor'],
                            minHeight: '350px',
                            maxHeight: '550px',
                            tabSize: 4,
                            uploadImage: true,
                            imageUploadFunction: function(file, onSuccess, onError) {
                                var formData = new FormData();
                                formData.append('image', file);
                                $.ajax({
                                    url: '" . $uploadUrl . "',
                                    type: 'POST',
                                    data: formData,
                                    processData: false,
                                    contentType: false,
                                    dataType: 'json',
                                    success: function(res) {
                                        if (res && res.data && res.data.filePath) {
                                            onSuccess(res.data.filePath);
                                        } else {
                                            onError(res.message || 'Upload failed');
                                        }
                                    },
                                    error: function() {
                                        onError('Server error');
                                    }
                                });
                            },
                            toolbar: " . $toolbarJs . "
                        });
                        targetElement.classList.add('easymde-initialized');
                        
                        // エディタ（CodeMirror）のリアルタイム変更イベントをテキストエリア（value）に同期
                        easyMDE.codemirror.on('change', function() { 
                            targetElement.value = easyMDE.value(); 
                        });
                    }
                }
            });
        ";
    }

    /**
     * ツールバー設定（setting.php）のJavaScriptオブジェクト（JSON）変換
     * - 文字列項目と自作カスタムボタン用多次元配列オブジェクトの振り分け・動的組み立て
     * - カーソル位置（getCursor）へのテンプレートテキスト挿入アクションの定義
     *
     * @return string
     */
    protected function _buildToolbarJs() {
        $configToolbar = Configure::read('MdEditor.toolbar');
        if (empty($configToolbar) || !is_array($configToolbar)) { return '["bold", "italic", "heading", "|", "quote", "image", "preview", "side-by-side", "fullscreen"]'; }
        $jsItems = array();
        foreach ($configToolbar as $item) {
            if (is_string($item)) { $jsItems[] = '"' . $item . '"'; }
            elseif (is_array($item)) {
                $name = isset($item['name']) ? $item['name'] : 'custom'; 
                $className = isset($item['className']) ? $item['className'] : 'fa fa-star'; 
                $title = isset($item['title']) ? $item['title'] : 'Custom'; 
                $inserted = isset($item['defaultText']) ? $item['defaultText'] : '';
                $escapedText = str_replace(array("\r\n", "\r", "\n"), array("\\n", "\\n", "\\n"), addslashes($inserted));
                $jsItems[] = "{\n name: '" . addslashes($name) . "', className: '" . addslashes($className) . "', title: '" . addslashes($title) . "', action: function(editor) { editor.codemirror.getDoc().replaceRange('" . $escapedText . "', editor.codemirror.getDoc().getCursor()); }\n}";
            }
        }
        return "[\n" . implode(",\n", $jsItems) . "\n]";
    }

    /**
     * Vendor/CustomParsedown を用いた Markdown HTML 変換の内部実行
     * - 改行の自動変換（setBreaksEnabled）およびHTMLタグのエスケープ制御（setSafeMode）の定義
     *
     * @param  string  $text Markdown文字列
     * @param  boolean $forcePage 強制変換フラグ
     * @return string
     */
    protected function _toHtml($text, $forcePage = false) {
        if (empty($text) || !is_string($text)) { return $text; }
        if (!$forcePage) {
            if (strpos($text, '<p>') !== false || strpos($text, '<h1>') !== false || strpos($text, '<h2>') !== false) { return $text; }
        }
        $parsedownPath = dirname(dirname(dirname(__FILE__))) . DS . 'Vendor' . DS . 'CustomParsedown.php';
        if (file_exists($parsedownPath)) { require_once $parsedownPath; }
        if (!class_exists('CustomParsedown')) { return $text; }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = str_replace('　', '  ', $text);
        $parsedown = new CustomParsedown();
        $parsedown->setBreaksEnabled(true);
        $parsedown->setSafeMode(false);
        return $parsedown->text($text);
    }
}
