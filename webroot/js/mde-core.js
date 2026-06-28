/**
 * mde-core.js
 *
 * EasyMDE/Parsedown出力エリアにおけるフロントエンド拡張スクリプト
 * - Highlight.js を用いたシンタックスハイライトの実行
 * - 共通ラッパーコンテナ、言語名/ファイル名ヘッダー、コピーボタンの動的生成
 * - クリップボードコピー機能（非同期 API）と連動した状態変化制御
 * - テキストノード解析に基づく高精度な行番号（ラインナンバー）の算定と生成
 *
 * @package    MdEditor
 * @subpackage webroot.js
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */
document.addEventListener('DOMContentLoaded', function() {
    
    // 対象となるコードブロック要素（codeタグ）を全取得
    var codeElements = document.querySelectorAll('pre code');
    
    codeElements.forEach(function(code) {
        var parentPre = code.parentNode;
        if (!parentPre) return;

        // 1. Highlight.js によるハイライト処理の実行
        if (typeof hljs !== 'undefined') {
            hljs.highlightElement(code);
        }

        // 2. 共通コンテナ（外枠ラッパー）の動的生成とDOM挿入
        var wrapper = document.createElement('div');
        wrapper.className = 'mde-code-wrapper';
        parentPre.parentNode.insertBefore(wrapper, parentPre);
        wrapper.appendChild(parentPre);

        // 独自CSS調整用の識別クラスをpre要素へ追加
        parentPre.classList.add('mde-pre');

        // 3. クラス名からの言語名（拡張子）の動的抽出
        var langName = 'CODE';
        code.classList.forEach(function(className) {
            if (className.indexOf('language-') === 0) {
                langName = className.replace('language-', '').toUpperCase();
            } else if (className.indexOf('lang-') === 0) {
                langName = className.replace('lang-', '').toUpperCase();
            }
        });

        // プレーンテキスト表記の正規化
        if (langName === 'TEXT' || langName === 'TXT') {
            langName = 'PLANE TEXT';
        }

        // 4. 定義済ファイル名属性（data-filename）の抽出処理
        // pre要素またはcode要素に付与されたカスタムデータ属性からファイル名を優先取得
        var filename = parentPre.getAttribute('data-filename') || code.getAttribute('data-filename');

        // ファイル名が定義されている場合は言語名表記を上書き更新
        if (filename) {
            langName = filename;
        }

        // 5. 言語名/ファイル名表示用ヘッダー（.mde-code-header）の動的生成
        var header = document.createElement('div');
        header.className = 'mde-code-header';
        header.textContent = langName; 
        wrapper.insertBefore(header, parentPre);

        // 6. クリップボードコピーボタン（.mde-copy-btn）の動的生成と実装
        var copyBtn = document.createElement('button');
        copyBtn.className = 'mde-copy-btn';
        copyBtn.type = 'button';
        copyBtn.textContent = 'COPY';
        wrapper.appendChild(copyBtn);

        // コピーボタンに対する非同期クリップボードイベントの登録
        copyBtn.addEventListener('click', function() {
            var rawText = code.innerText;
            
            navigator.clipboard.writeText(rawText).then(function() {
                // 実行直後に成功状態を示すテキスト（COPIED!）へ変更
                copyBtn.textContent = 'COPIED!';
                copyBtn.classList.add('copied');
                
                // 1000ms（1秒）経過後に確定状態（DONE!）として表記を固定
                setTimeout(function() {
                    copyBtn.textContent = 'DONE!';
                }, 1000);
            }).catch(function(err) {
                console.error('Copy failed: ', err);
            });
        });

        // 7. 純粋テキストノードの行数計算と行番号要素の生成
        // HTMLタグの混入による計算誤差を防ぐため、textContentから改行コードで配列化
        var codeText = code.textContent;
        var lines = codeText.split(/\r?\n/);
        
        // 末尾が改行コードのみで終わる場合、最終行（空行）をカウント要素から除外
        if (lines.length > 1 && codeText.endsWith('\n')) {
            lines.pop();
        }

        var lineCount = lines.length;

        // 行番号コンテナ（ln-wrapper）および各行番号のインジェクション処理
        if (lineCount > 0) {
            code.classList.add('ln-padd');

            var lnWrapper = document.createElement('span');
            lnWrapper.className = 'ln-wrapper';

            for (var i = 1; i <= lineCount; i++) {
                var lnNum = document.createElement('span');
                lnNum.className = 'ln-num';
                lnNum.textContent = i;
                lnWrapper.appendChild(lnNum);
            }

            // X軸スクロールと完全に同期させるため、親pre要素の直下（code要素の直前）に挿入
            parentPre.insertBefore(lnWrapper, code);
        }
    });
});
