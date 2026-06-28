<?php
$title = 'Markdownエディタ';
$description = 'EasyMDEを使ったMarkdownエディタ。baserCMS4系プラグインです。';
$author = 'HATTA';
$url = 'https://hattantoco.com';

// setting.php をシステムへ引き渡す
$settingPath = dirname(__FILE__) . DS . 'Config' . DS . 'setting.php';

if (file_exists($settingPath)) {
    include $settingPath;
    }
