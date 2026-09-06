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
            $this->BcBaser->css('MdEditor.mde-preview', array('inline' => false));
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
            $this->BcBaser->css('MdEditor.atom-one-light.min', array('inline' => false));
            $this->BcBaser->css('MdEditor.mde-add', array('inline' => false));
            $this->BcBaser->js('MdEditor.highlight.min', false, array('defer' => 'defer', 'inline' => false));
            $this->BcBaser->js('MdEditor.mde-core', false, array('defer' => 'defer', 'inline' => false));

            // A. 固定ページ本文のパース処理（raw-code スライス・PHP/JS動的実行仕様）
            if ($this->request->params['controller'] === 'pages' && isset($this->_View->Blocks)) {
                // PHP 8.0対応：引数へのnull侵入による型エラーを防止するため文字列型へ強制キャスト
                $rawMarkdown = (string)$this->_View->Blocks->get('content');
                if ($rawMarkdown !== '') {
                    $search  = array('[#PHP_START_LONG#]', '[#PHP_START_SHORT#]', '[#PHP_END#]');
                    $replace = array('<?php', '<?', '?>');
                    $restoredMarkdown = str_replace($search, $replace, $rawMarkdown);
                    $cleanMarkdown = $restoredMarkdown;

                    // raw-code 構文（プレ・パーススライス処理）
                    // Parsedownやエディタ専用CSSの干渉外へ隔離する
                    if (strpos($cleanMarkdown, ':::raw-code') !== false) {
                        
                        $parts = preg_split('/:::\s*raw\-code\s*(.*?)\s*:::/is', $cleanMarkdown, -1, PREG_SPLIT_DELIM_CAPTURE);
                        
                        $finalHtmlResult = '';
                        foreach ($parts as $index => $content) {
                            if ($content === '') continue;

                            if ($index % 2 !== 0) {
                                // raw-code領域
                                // Parsedownの通過を完全にバイパスさせ、ラッパー要素（div等）を付与せず生のまま結合する
                                $finalHtmlResult .= $content;
                            } else {
                                // Markdown領域
                                // 事前に生HTMLブロックが排除されているためパニックを起こさず、見出し等のMarkdown記法を正常にパースする
                                $parsedPart = $this->_toHtml($content, true);
                                if ($parsedPart !== '') {
                                    $finalHtmlResult .= '<div class="mde-parsed-body mde-hybrid-mode">' . $parsedPart . '</div>';
                                }
                            }
                        }
                        
                        $wrappedPageHtml = $finalHtmlResult;
                    } else {
                        // 通常パースモード：独自の除外マークアップが含まれない場合は、全体を単一コンテナで内包
                        $parsedHtml = $this->_toHtml($cleanMarkdown, true);
                        $wrappedPageHtml = '<div class="mde-parsed-body">' . $parsedHtml . '</div>';
                    }

                    // インラインPHPコードの動的評価・実行処理（サンドボックス擬似実行）
                    // 本文、スライスエリア、マージされたコードエリアのいずれかにPHPタグまたはその残骸を検知した場合にevalを起動
                    $hasPhpCode = (strpos($wrappedPageHtml, '<?php') !== false || 
                                   strpos($wrappedPageHtml, '<?') !== false || 
                                   strpos($cleanMarkdown, '<?php') !== false ||
                                   strpos($rawMarkdown, '[#PHP_START_LONG#]') !== false);

                    if ($hasPhpCode) {
                        $renderPhpInline = function($htmlStr) {
                            ob_start();
                            try {
                                eval('?>' . $htmlStr);
                                return ob_get_clean();
                            } catch (Exception $e) {
                                ob_end_clean();
                                return $htmlStr . '<p style="color:red; background:#fee; padding:10px;">PHP実行エラー: ' . h($e->getMessage()) . '</p>';
                            }
                        };

                        // 実行コンテキストのバインディング
                        // クロージャ内部の「$this」を現在のViewクラス（$this->_View）へ明示的に結合し、コア関数の正常な実行を可能にする
                        $boundClosure = $renderPhpInline->bindTo($this->_View, $this->_View);
                        if ($boundClosure) {
                            $wrappedPageHtml = $boundClosure($wrappedPageHtml);
                        }
                    }
                                       
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
                            $cleanMarkdown = $restoredMarkdown;

                            $isolatedMarkdown = "\n\n" . trim($cleanMarkdown) . "\n\n";
                            $parsedBodyHtml = $this->_toHtml($isolatedMarkdown, true);

                            // パース直後のHTMLを、画面へ再結合する直前で安全にクリーニング
                            $safeBodyHtml = $this->_sanitizeHtml($parsedBodyHtml);

                            // 元の画面全体のHTMLの「本文（Markdown）があった場所」だけを、専用の防衛コンテナで包んで置換
                            $beforeBody = substr($finalHtml, 0, $startPos);
                            $afterBody  = substr($finalHtml, $endPos + strlen($endMarker));

                            // $parsedBodyHtml の代わりに、安全になった $safeBodyHtml を包みます
                            $wrappedBodyHtml = '<div class="mde-parsed-body">' . $safeBodyHtml . '</div>';

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
     * - Fetch APIを用いた画像非同期アップロードハンドラーの実装
     * - CSRFトークンへの対応と二重初期化の防止
     *
     * @param  string $domId 対象テキストエリアのDOM要素ID
     * @return string
     */
    protected function _buildMdeScript($domId) {
        $toolbarJs = $this->_buildToolbarJs(); 
        $uploadUrl = $this->BcBaser->getUrl('/admin/md_editor/md_editor_uploads/upload');
        
        $jsonDomId = json_encode($domId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsonUploadUrl = json_encode($uploadUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        return "
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof EasyMDE !== 'undefined') {
                    var targetElement = document.getElementById(" . $jsonDomId . ");
                    
                    // 要素の存在チェックと二重初期化防止
                    if (targetElement && !targetElement.classList.contains('easymde-initialized')) {
                        
                        // 管理画面用のCSRFトークンをフォーム内から取得
                        var csrfToken = '';
                        var csrfInput = document.querySelector('input[name=\"_csrfToken\"]') || document.querySelector('input[name=\"data[_Token][key]\"]');
                        if (csrfInput) {
                            csrfToken = csrfInput.value;
                        }

                        var easyMDE = new EasyMDE({
                            element: targetElement,
                            autoDownloadFontAwesome: true,
                            spellChecker: false,
                            forceSync: true, // 元のテキストエリア（textarea）に値を同期
                            status: ['autosave', 'lines', 'words', 'cursor', 'upload-image'],
                            minHeight: '350px',
                            maxHeight: '550px',
                            tabSize: 4,
                            uploadImage: true,
                            imageUploadFunction: function(file, onSuccess, onError) {
                                var formData = new FormData();
                                formData.append('image', file);
                                
                                var headers = {};
                                if (csrfToken) {
                                    headers['X-CSRF-Token'] = csrfToken;
                                }

                                fetch(" . $jsonUploadUrl . ", {
                                    method: 'POST',
                                    headers: headers,
                                    body: formData
                                })
                                .then(function(response) {
                                    if (!response.ok) {
                                        throw new Error('Server error (' + response.status + ')');
                                    }
                                    return response.json();
                                })
                                .then(function(res) {
                                    var filePath = res && (res.filename || res.url || (res.data && res.data.filePath));
                                    
                                    if (filePath) {
                                        onSuccess(filePath);
                                    } else {
                                        onError(res.message || 'Upload failed');
                                    }
                                })
                                .catch(function(error) {
                                    onError(error.message || 'Server error');
                                });
                            },
                            toolbar: " . $toolbarJs . "
                        });
                        
                        // 初期化済みマークを付与
                        targetElement.classList.add('easymde-initialized');

                        // ガイドメッセージを非表示
                        if (easyMDE.gui && easyMDE.gui.statusbar) {
                            var uploadStatusEl = easyMDE.gui.statusbar.querySelector('.upload-image');
                            if (uploadStatusEl) {
                                uploadStatusEl.style.display = 'none';
                            }
                        }
                    }
                }
            });
        ";
    }

    /**
     * ツールバー設定（setting.php）のJavaScriptオブジェクト（JSON）変換
     * - 文字列項目と自作カスタムボタン用多次元配列オブジェクトの振り分け・動的組み立て
     * - カーソル位置（getCursor）へのテンプレートテキスト挿入アクションの定義
     * - 【追加】固定ページ以外の編集画面（ブログ等）における html-raw ボタンの動的排除
     *
     * @return string
     */
    protected function _buildToolbarJs() {
        $configToolbar = Configure::read('MdEditor.toolbar');
        if (empty($configToolbar) || !is_array($configToolbar)) { return '["bold", "italic", "heading", "|", "quote", "image", "preview", "side-by-side", "fullscreen"]'; }
        
        // 【追加】現在のリクエストが「固定ページ（pages）」以外の場合、raw-code ボタンをツールバーから完全排除
        if (isset($this->request->params['controller']) && $this->request->params['controller'] !== 'pages') {
            foreach ($configToolbar as $key => $item) {
                if (is_array($item) && isset($item['name']) && $item['name'] === 'raw-code') {
                    unset($configToolbar[$key]);
                    break;
                }
            }
        }

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
     *
     * @param  string  $text   アンカー等の隔離マークを含んだMarkdown文字列
     * @param  boolean $forcePage 強制パース執行フラグ
     * @return string  パース完了後のHTML文字列
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

    /**
     * （DOMDocument安全隔離型）HTMLサニタイザー
     */
    protected function _sanitizeHtml($html) {
        if ($html === '') return '';

        // アンカーや生PHPタグを消去から守るための一次退避（シールド）
        $shieldSearch  = array('<!--MDE_BODY_START-->', '<!--MDE_BODY_END-->', '<?php', '<? ', '?>');
        $shieldReplace = array('[#MDE_SHIELD_START#]', '[#MDE_SHIELD_END#]', '[#PHP_DOM_LONG#]', '[#PHP_DOM_SHORT#]', '[#PHP_DOM_END#]');
        $shieldedHtml = str_replace($shieldSearch, $shieldReplace, $html);

        // 許可する安全なHTMLタグのホワイトリスト
        $allowedTags = '<div><span><p><br><hr><h1><h2><h3><h4><h5><h6><a><img><strong><em><b><i><ul><ol><li><pre><code><blockquote><table><thead><tbody><tr><th><td><iframe><style>';
        $cleaned = strip_tags($shieldedHtml, $allowedTags);

        // DOMで危険な属性（onclick, javascript:等）を除去
        $dom = new DOMDocument();
        $htmlWithMeta = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $cleaned . '</body></html>';
        @$dom->loadHTML($htmlWithMeta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $iframes = $dom->getElementsByTagName('iframe');

        foreach ($iframes as $iframe) {
            if (!$iframe->hasAttribute('sandbox')) {
                $iframe->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-forms');
            }
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*');

        foreach ($nodes as $node) {
            if (!$node->hasAttributes()) { continue; }
            $attributes = array();
            foreach ($node->attributes as $attr) { $attributes[] = $attr->name; }
            foreach ($attributes as $attrName) {
                if (strpos(strtolower($attrName), 'on') === 0) {
                    $node->removeAttribute($attrName);
                    continue;
                }
                if (in_array(strtolower($attrName), array('href', 'src'))) {
                    $attrValue = trim($node->getAttribute($attrName));
                    if (preg_match('/^(javascript|data|vbscript):/i', $attrValue)) {
                        $node->removeAttribute($attrName);
                    }
                }
            }
        }

        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        $result = '';
        if ($bodyNode) {
            foreach ($bodyNode->childNodes as $child) { $result .= $dom->saveHTML($child); }
        }

        // アンカーとPHPタグを復元
        return str_replace($shieldReplace, $shieldSearch, $result);
    }
}
