<?php
namespace App\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Controller\ComponentRegistry;
use Cake\Datasource\ConnectionManager;
use App\Controller\Component\TwitterComponent;

/**
 * TwitterでTweetを検索する
 */
class SearchTweetsShell extends Shell
{
    public function main()
    {
        $this->log('-----------Shell Start>>>>>>>>>>>>>', 'info');
        // 設定読み込み&コンポーネント準備
        Configure::load('/contents/my_dev_twitter_app');
        $this->loadComponents();
        // Twitter検索処理
        $this->searchTweets();
        $this->log('>>>>>>>>>>>Shell End---------------', 'info');
    }

    /**
     * Shellで使用するコンポーネント類はここで読み込む
     * @return void
     */
    private function loadComponents()
    {
        $this->Twitter = new TwitterComponent(new ComponentRegistry());
    }

    /**
     * TwitterSearchAPIで検索処理して、アカウントとツイートをDBに登録
     */
    private function searchTweets()
    {
        // 認証情報の設定
        $this->Twitter->setAuthentication(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $continueFlg = true;    // 検索処理の続行フラグ
        $searchCount = 0;       // 取得した検索結果件数
        $insertDbCount = 0;     // DBにinsertしたTweet件数
        $maxId = null;          // 次回検索時の対象となる一番新しいツイートID

        while ($continueFlg) {
            // 検索ワード作成
            $SEARCH_OPTIONS = array_merge(
                Configure::read('SEARCH_OPTIONS'),
                ['q' => $this->createSearchQuery()]
            );

            // MaxId指定
            if ($maxId !== null) {
                $SEARCH_OPTIONS = array_merge(
                    $SEARCH_OPTIONS,
                    ['max_id' => $maxId]
                );
            }

            // TwitterSearchAPIをコール
            $results = $this->Twitter->searchTweets($SEARCH_OPTIONS);

            // レスポンス確認
            $status = $results['status'];
            if ($status === 'success') {
                $tweets = $results['response'];
                $lastTweetId = $tweets[count($tweets)-1]->id;
                $maxId = $lastTweetId-1;
                $this->log("次の検索でのMaxIdは{$maxId}", 'info');

                foreach ($tweets as $tweet) {
                    //　ツイートを目標数処理したら処理を終了
                    if ($searchCount === SEARCH_COUNT) {
                        $this->log("検索結果が{$searchCount}件に達しました。", 'info');
                        $this->log('Tweet検索処理完了', 'info');
                        $continueFlg = false;
                        break;
                    }

                    $this->log(
                        '- Tweet Info ---------------------------------------------------------------------',
                        'info'
                    );
                    // トランザクション開始
                    $connection = ConnectionManager::get('default');
                    $connection->begin();

                    // アカウント・ツイートのDB登録
                    $insertAccountId = $this->Twitter->saveTwitterAccount(
                        USE_TABLE['accounts'],
                        $tweet->user
                    );
                    if (!empty($insertAccountId)) {
                        $insertTweetId = $this->Twitter->saveTweet(
                            USE_TABLE['tweets'],
                            $insertAccountId,
                            $tweet
                        );
                        if (!empty($insertTweetId)) {
                            $connection->commit();
                            $insertDbCount++;
                        } else {
                            $connection->rollback();
                        }
                    } else {
                        $connection->rollback();
                    }
                    $this->log(
                        '---------------------------------------------------------------------------------',
                        'info'
                    );
                    $searchCount++;
                }
            }

            if ($status === 'error') {
                $errors = $results['response'];
                // 複数エラー：処理終了
                if (count($errors) > 1) {
                    foreach ($errors as $error) {
                        $this->log(
                            "Twitter.error.code: {$error->code}. Twitter.error.message: {$error->message}",
                            'error'
                        );
                    }
                    $continueFlg = false;
                } else {
                    // Code:88の場合：スリープ後、処理を継続
                    $error0 = $errors[0];
                    $this->log(
                        "Twitter.error.code: {$error0->code}. Twitter.error.message: {$error0->message}",
                        'error'
                    );
                    if ($error0->code === ERROR_CODE_RATE_LIMIT_EXCEEDED) {
                        $this->log('APIコール制限の為、'.API_CALL_SLEEP_TIME.'秒間のスリープ後、処理を再開します。', 'info');
                        sleep(API_CALL_SLEEP_TIME);
                        continue;
                    }
                    // その他Codeの場合：処理終了
                    $continueFlg = false;
                }
            }
            
            if ($status === 'empty') {
                $this->log('Twitter Response is empty.', 'info');
                $this->log('Tweet検索処理完了', 'info');
                $continueFlg = false;
            }
        }
    }

    private function createSearchQuery()
    {
        $searchQuery = '';
        foreach (Configure::read('SEARCH_WORDS') as $word) {
            $searchQuery .= "$word exclude:retweets OR ";
            $searchQuery .= "#$word exclude:retweets OR ";
        }
        $searchQuery = preg_replace('/ OR $/', '', $searchQuery);
        return $searchQuery;
    }
}
