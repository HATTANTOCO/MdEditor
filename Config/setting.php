<?php
/**
 * MdEditor プラグイン設定ファイル
 * 
 * - baserCMSコアのエディタ管理システム（BcApp）へのエディタ登録
 * - バックグラウンド処理およびパース用イベントリスナー（BcEvent）の常時登録
 * - EasyMDE（Markdownエディタ）のツールバー表示およびカスタムボタン拡張設定
 *
 * @package    MdEditor
 * @subpackage Config
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */

// 1. 「Markdownエディタ」をエディタ選択に登録
$config['BcApp'] = array(
    'editors' => array(
        'MdEditor.MdEditor' => 'Markdownエディタ'
    )
);

// 2. イベントリスナーの登録
$config['BcEvent'] = array(
    'MdEditor' => array(
        'MdEditorControllerEventListener'
    )
);

/**
 * 3. ユーザー専用カスタマイズ領域
 * ツールバーのボタン構成設定
 * - 配列内の構成要素および並び順を変更することで、EasyMDEのツールバーを変更
 */
$config['MdEditor']['toolbar'] = array(
    "bold",
    "italic",
    "strikethrough",
    "heading",
    "|",
    "quote",
    "code",
    "table",
    "horizontal-rule",
    "|",
    "unordered-list",
    "ordered-list",
    "|",
    "link",
    "image",
    "|",
    
    // カスタムアクションボタン：補足情報ボックス（info-box）の挿入
    // - ツールバーにFontAwesomeのアイコンを追加し、指定の独自Markdown構文をエディタに挿入
    array(
        'name' => 'info-box',
        'className' => 'fa fa-info-circle',
        'title' => '補足情報（info）の枠を挿入',
        'defaultText' => ":::info\nここに補足情報を記入\n:::\n"
    ),
    "|",
    
    "preview",
    "side-by-side",
    "fullscreen",
    "|",
    "guide"
);
