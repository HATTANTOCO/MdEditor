<?php
/**
 * CustomParsedown
 *
 * Parsedown (v1.8.0) 拡張パースエンジン
 * - コードブロックにおけるファイル名属性（data-filename）の動的抽出・拡張機能
 * - :::info / :::is-info 構文による補足情報ボックス（InfoBox）のカスタムブロック機能
 *
 * @package    MdEditor
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */

// 基底クラス（Parsedown.php）のインクルード
$parsedownPath = dirname(__FILE__) . DS . 'Parsedown.php';
if (file_exists($parsedownPath)) { 
    require_once $parsedownPath; 
}

class CustomParsedown extends Parsedown {

    /**
     * コンストラクタ / カスタムブロック構文の登録
     */
    public function __construct() {
        // コロン「:」から始まるブロック構造をInfoBox解析メソッドにフック
        $this->BlockTypes[':'][] = 'InfoBox';
    }

    /**
     * 1. コードブロック（Fenced Code Blocks）の解析拡張
     * - ```php:filename.php 形式から言語名とファイル名を分離抽出
     * - pre要素に対して「mde-pre」クラスおよび「data-filename」属性を動的に注入
     *
     * @param  array $Line
     * @return array|null
     */
    protected function blockFencedCode($Line) {
        $filename = '';
        
        // コードブロックの開始文字（` または ~）を検証
        $openerChar = substr($Line['text'], 0, 1);
        if ($openerChar === '`' || $openerChar === '~') {
            
            // 言語名およびファイル名（マルチバイト文字列対応）の正規表現パターンマッチング
            if (preg_match('/^([' . $openerChar . ']{3,})[ ]*([^\s]+)?[ ]*$/', $Line['text'], $matches)) {
                
                // コロン区切りのファイル名指定が存在する場合の分離処理
                if (isset($matches[2]) && strpos($matches[2], ':') !== false) {
                    $parts = explode(':', $matches[2], 2);
                    $lang  = isset($parts[0]) ? $parts[0] : '';
                    $filename = isset($parts[1]) ? $parts[1] : '';

                    // 基底クラス（Parsedown）のパーサー衝突を避けるため、行データを純粋な言語名のみに補正
                    $Line['text'] = preg_replace('/:.*$/', '', $Line['text']);
                }
            }
        }

        // 基底クラス（Parsedown）の標準コードブロック解析を実行
        $Block = parent::blockFencedCode($Line);

        // 生成されたDOM構造体（AST）への属性インジェクション
        if (isset($Block['element']['element']['attributes']['class'])) {
            $classAttr = $Block['element']['element']['attributes']['class'];
            $pureLang  = str_replace('language-', '', $classAttr);

            // プラグイン専用CSS（mde-add.css）との適合クラスを付与
            $Block['element']['attributes']['class'] = 'mde-pre language-' . $pureLang;

            // 抽出されたファイル名をデータ属性（data-filename）としてpre要素にバインド
            if ($filename !== '') {
                $Block['element']['attributes']['data-filename'] = $filename;
            }
        }

        return $Block;
    }

    /**
     * 2. 補足情報ボックス（:::info）の開始条件判定
     *
     * @param  array $Line
     * @param  array|null $Block
     * @return array|null
     */
    protected function blockInfoBox($Line, $Block = null) {
        if (preg_match('/^:::\s*is-?info|^:::\s*info/i', $Line['text'])) {
            $Block = array(
                'char' => $Line['text'],
                'element' => array(
                    'name'    => 'div',
                    'handler' => 'lines',
                    'attributes' => array('class' => 'mde-info-box'),
                    'text'    => array(),
                ),
            );
            return $Block;
        }
    }

    /**
     * 補足情報ボックス（:::info）の内部コンテンツ蓄積処理
     *
     * @param  array $Line
     * @param  array $Block
     * @return array
     */
    protected function blockInfoBoxContinue($Line, $Block) {
        if (isset($Block['complete'])) { 
            return $Block; 
        }
        
        // 閉じシールド（:::）を検知した場合にブロック解析を正常終了
        if (preg_match('/^:::\s*$/', $Line['text'])) {
            $Block['complete'] = true;
            return $Block;
        }
        
        $Block['element']['text'][] = $Line['text'];
        return $Block;
    }

    /**
     * 補足情報ボックス（:::info）の確定コールバック
     *
     * @param  array $Block
     * @return array
     */
    protected function blockInfoBoxComplete($Block) { 
        return $Block; 
    }
}
