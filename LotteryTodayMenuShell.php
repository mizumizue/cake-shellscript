<?php
namespace App\Shell;

use Cake\I18n\Time;
use Cake\Http\Client;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;

/**
 * WunderlistAPIを使用して今日の献立を決める
 * LotteryTodayMenuShell
 */
class LotteryTodayMenuShell extends Shell
{
    public function main()
    {
        // 設定読み込み
        Configure::load('/contents/my_dev_wunderlist_app');
        // HTTPクライアントの用意
        $httpClient = new Client([
            'headers' => [
                'X-Access-Token' => ACCESS_TOKEN,
                'X-Client-ID' => CLIENT_ID,
            ]
        ]);

        //　完了済Taskリスト取得
        $completedTaskList = $this->getTaskList($httpClient, MENU_LIST_ID, $completed = 'true');
        if ($completedTaskList) {
            foreach ($completedTaskList as $task) {
                $this->log("完了済タスク処理Start >>>>>>>>>>>>>> Id:{$task['id']} Title:{$task['title']}", LOG_INFO);
                // Status変更
                $task['completed'] = false;
                $task['starred'] = false;
                $task['due_date'] = '';
                $isSuccessSetStatus = $this->setTaskStatus($httpClient, $task);
                if (!$isSuccessSetStatus) {
                    $this->log(">>>>>>>>>>>>>> 完了済タスク処理 Status変更失敗 End", LOG_WARNING);
                    continue;
                }
                // HistoryへInsert
                $insertData = [
                    'title' => $task['title'],
                    'due_date' => Time::now()->startOfDay()->addDay(-1)->format('Y-m-d')
                ];
                $isSuccessInsertHistory = $this->insertTaskToHistory($httpClient, $insertData);
                if (!$isSuccessInsertHistory) {
                    $this->log(">>>>>>>>>>>>>> 完了済タスク処理 Insert失敗 End", LOG_WARNING);
                    continue;
                }
                $this->log(">>>>>>>>>>>>>> 完了済タスク処理End", LOG_INFO);
            }
        }

        $this->log('抽選処理　>>>>>>>>>>>>>>>>>>> Start', LOG_INFO);
        // Task履歴リスト取得
        $uncompletedTaskList = $this->getTaskList($httpClient, MENU_LIST_ID);
        if (!$uncompletedTaskList) {
            $this->log('未完了Taskリスト取得失敗により、処理を終了します。', LOG_ERR);
            $this->log("抽選処理　>>>>>>>>>>>>>>>>>>> End", LOG_WARNING);
            return;
        }
        // 最新5件取得
        $historyTaskList = $this->getTaskList($httpClient, MENU_HISTORY_LIST_ID);
        $latestTaskTitleList = $this->getLatestTaskTitleList($historyTaskList, $latestNum = 5) ?? [];
        $latestTaskTitleList = array_unique($latestTaskTitleList);
        $lotteryTaskList = [];    // 抽選対象のタスクリスト
        foreach ($uncompletedTaskList as $task) {
            if (!in_array($task['title'], $latestTaskTitleList)) {
                $lotteryTaskList[] = $task;
            }
        }
        // 今日のMenuのIdを取得
        $todayTaskkey = array_rand($lotteryTaskList);
        $todayTask = $lotteryTaskList[$todayTaskkey];
        if (!$todayTask) {
            $this->log('本日のメニュー取得失敗により、処理を終了します。', LOG_ERR);
            $this->log("抽選処理　>>>>>>>>>>>>>>>>>>> End", LOG_WARNING);
            return;
        }
        $this->log(
            "抽選処理　>>>>>>>>>>>>>>>>>>> End 本日のメニュー：「Id:{$todayTask['id']} Title:{$todayTask['title']}」",
            LOG_INFO
        );

        // 全Taskの詳細取得＆ステータス変更
        foreach ($uncompletedTaskList as $task) {
            $this->log("タスク毎の処理　Start >>>>>>>>>>> taskId:{$task['id']}", LOG_DEBUG);
            // 本日のメニューの処理
            if ($task['id'] === $todayTask['id']) {
                $task['starred'] = true;
                $task['due_date'] = Time::now()->startOfDay()->format('Y-m-d');
                $this->setTaskStatus($httpClient, $task);
                $this->setTaskComment($httpClient, $task['id'], $comment = "本日の献立にこちらは如何でしょうか？");
            } else {
                // ステータス設定
                if ($task['starred'] || isset($task['due_date'])) {
                    $task['starred'] = false;
                    $task['due_date'] = '';
                    $isSuccessSetStatus = $this->setTaskStatus($httpClient, $task);
                    $this->log("タスクステータス変更 結果(0:失敗、1:成功):{$isSuccessSetStatus}", LOG_DEBUG);
                }
            }
            $this->log('タスク毎の処理　>>>>>>>>>>>>>>>>>>> End', LOG_DEBUG);
        }

        // タスクの並び替え
        $taskOrder = $this->getTaskOrder($httpClient, MENU_LIST_ID);
        $taskOrderRevision = $taskOrder['revision'];
        $taskOrderValues = $taskOrder['values'];
        $todayTaskKey = array_search($todayTask['id'], $taskOrderValues);
        unset($taskOrderValues[$todayTaskKey]);
        array_unshift($taskOrderValues, $todayTask['id']);
        $isSuccessUpdateTasksOrder = $this->updateTasksOrder(
            $httpClient,
            MENU_LIST_ID,
            $taskOrderValues,
            $taskOrderRevision
        );
        $this->log("タスクの並びかえ結果(0:失敗 1:成功): {$isSuccessUpdateTasksOrder}", LOG_INFO);
    }

    /**
     *　リスト内のタスク一覧を取得
     * @param Cake\Http\Client $httpClient
     * @param int $listId ListId
     * @param string $completed Completed
     * @return array $taskList | false
     */
    private function getTaskList($httpClient, $listId, $completed = 'false')
    {
        try {
            $response = $httpClient->get(
                URL.'tasks',
                ['list_id' => $listId, 'completed' => $completed]
            );
            if ($response->isOk()) {
                $taskList = $response->json ?? [];
                return $taskList;
            }
            throw new Exception('API Call Error. ResponseHttpCode:'.$response->getStatusCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * リスト内のタスクの順序を取得
     * @param Cake\Http\Client $httpClient
     * @param int $listId ListId
     * @return array $taskList | false
     */
    private function getTaskOrder($httpClient, $listId)
    {
        try {
            $response = $httpClient->get(
                URL.'task_positions',
                ['list_id' => $listId]
            );
            if ($response->isOk()) {
                $taskOrderList = $response->json[0] ?? [];
                return $taskOrderList;
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * タスクの最新から指定した件数分のタイトルのリストを取得してくる
     * @param array $taskHistoryList
     * @param int $latestNum
     * @return array $latestTaskTitles | false
     */
    private function getLatestTaskTitleList($taskHistoryList, $latestNum)
    {
        try {
            // 履歴から最新の5件のメニュータイトルを取得
            $createdAts = array_column($taskHistoryList, 'created_at');
            array_multisort($createdAts, SORT_DESC, $taskHistoryList);
            $taskHistoryList = array_slice($taskHistoryList, 0, $latestNum);
            $latestTaskTitles = array_column($taskHistoryList, 'title');
            return $latestTaskTitles;
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * 今日のタスクにコメントを設定する
     * @param Cake\Http\Client $httpClient
     * @param int $taskId
     * @param string $comment
     * @return bool true | false
     */
    private function setTaskComment($httpClient, $taskId, $comment)
    {
        try {
            $data = [
                'task_id' => $taskId,
                'text' => $comment
            ];
            $response = $httpClient->post(
                URL.'task_comments',
                json_encode($data),
                ['type' => 'json']
            );
            if ($response->isOk()) {
                return true;
            }
            throw new Exception('API Call Error. ResponseCode:'.$response->getStatusCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * タスクにステータスを設定
     * @param Cake\Http\Client $httpClient
     * @param array $task
     * @return bool true | false
     */
    private function setTaskStatus($httpClient, $task)
    {
        try {
            $response = $httpClient->patch(
                URL.'tasks/'.$task['id'],
                json_encode($task),
                ['type' => 'json']
            );
            if ($response->isOk()) {
                return true;
            }
            throw new Exception('API Call Error. ResponseCode:'.$response->getStatusCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * 履歴タスクリストにタスクを挿入
     * @param Cake\Http\Client $httpClient
     * @param array $insertTaskData
     * @return bool true | false
     */
    private function insertTaskToHistory($httpClient, $insertTaskData)
    {
        try {
            $response = $httpClient->post(
                URL.'tasks',
                json_encode(array_merge(['list_id' => MENU_HISTORY_LIST_ID], $insertTaskData)),
                ['type' => 'json']
            );
            if ($response->isOk()) {
                return true;
            }
            throw new Exception('API Call Error. ResponseCode:'.$response->getStatusCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * リスト内のタスクの順序を更新
     * @param Cake\Http\Client $httpClient
     * @param int   $listId
     * @param array $tasksOrder
     * @return bool true | false
     */
    private function updateTasksOrder($httpClient, $listId, $tasksOrder, $revision)
    {
        try {
            $response = $httpClient->patch(
                URL.'task_positions/'.$listId,
                json_encode([
                    'values' => $tasksOrder,
                    'revision' => $revision
                ]),
                ['type' => 'json']
            );
            if ($response->isOk()) {
                return true;
            }
            throw new Exception('API Call Error. ResponseCode:'.$response->getStatusCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), LOG_WARNING);
            return false;
        }
    }
}
