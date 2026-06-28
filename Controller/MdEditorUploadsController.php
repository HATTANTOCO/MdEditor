<?php
/**
 * MdEditorUploadsController
 *
 * EasyMDEからの画像ドラッグ＆ドロップおよびペーストによる非同期アップロードを制御するコントローラー
 * 管理画面プレフィックス（admin）のルーティング規約、セッション認証、およびユーザーグループ制限に基づく
 * アクセス認可セキュリティチェックを搭載
 *
 * @package    MdEditor
 * @subpackage Controller
 * @author     HATTA
 * @license    MIT License
 * @link       https://hattantoco.com
 */

App::uses('AppController', 'Controller');

class MdEditorUploadsController extends AppController {

    /**
     * 利用するモデルの定義
     *
     * @var array
     */
    public $uses = array();

    /**
     * 認証フィルターおよびセキュリティ設定の初期化
     *
     * @return void
     */
    public function beforeFilter() {
        parent::beforeFilter();
        
        // 外部エンドポイント通信用：未ログイン時自動リダイレクトの除外設定
        if (isset($this->Auth)) {
            $this->Auth->allow('admin_upload');
        }
        
        // CSRF/フォーム改ざんチェックの免除アクションを登録
        if (isset($this->Security)) {
            $this->Security->unlockedActions = array('admin_upload');
            $this->Security->csrfCheck = false;
            $this->Security->validatePost = false;
        }
    }

    /**
     * 画像非同期アップロード実行エンドポイント
     * - ルーティングプレフィックス、セッション、所属ユーザーグループによる厳格な認可制御
     * - ファイルタイプ、保存ディレクトリ、一意のファイル名生成、データ移動、およびレスポンスJSONの返却
     *
     * @return void
     */
    public function admin_upload() {
        $this->autoRender = false;

        // 1. adminプレフィックス経由のアクセスであることを確認
        if (empty($this->request->params['prefix']) || $this->request->params['prefix'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['message' => 'Access denied: Invalid routing']);
            return;
        }

        // 管理システム（admin）のログインユーザーセッションを取得
        App::uses('BcUtil', 'Lib');
        $loginUser = BcUtil::loginUser('admin');
        
        // 未ログインアクセスの遮断処理
        if (empty($loginUser)) {
            http_response_code(403);
            echo json_encode(['message' => 'Access denied: Authentication required']);
            return;
        }

        // 2. アップロードを許可するユーザーグループIDを定義
        // ※ 1: システム管理者。サイト運営者、ブログ運用者を許可する場合はユーザーグループIDを追記
        $allowedGroups = [
            1
        ];

        // 所属グループIDの有無、および認可リストへの包含チェック
        if (!isset($loginUser['user_group_id']) || !in_array((int)$loginUser['user_group_id'], $allowedGroups)) {
            http_response_code(403);
            echo json_encode(['message' => 'アクセス拒否: お使いのユーザーグループにはファイルのアップロード権限がありません。']);
            return;
        }

        // 3. 画像の受信および保存処理
        if ($this->request->is('post') && !empty($_FILES['image'])) {
            $file = $_FILES['image'];

            // ファイルタイプ（MIMEタイプ）のホワイトリストチェック
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['message' => 'Invalid file type']);
                return;
            }

            // 保存先ディレクトリ（ROOT/files/MdEditor/）の確保
            $uploadDir = ROOT . DS . 'files' . DS . 'MdEditor' . DS;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // 一意のファイル名（タイムスタンプ + ユニークID）の生成
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFileName = date('YmdHis') . '_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFileName;

            // アップロード一時ファイルの実ディレクトリ移動と成功レスポンスの出力
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $filePath = $this->request->webroot . 'files/MdEditor/' . $newFileName;
                echo json_encode(['data' => ['filePath' => $filePath]]);
                return;
            }
        }

        http_response_code(400);
        echo json_encode(['message' => 'Upload failed']);
    }
}
