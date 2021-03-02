<?php
/**
 * 工作进程类
 * User: Dean.Lee
 * Date: 16/10/14
 */
namespace Core;

Class Worker
{
    //Worker进程ID
    Public $id = null;

    //Worker进程的操作系统进程ID
    Public $pid = null;

    Static Public $callFunc = [];

    /**
     * 工作/任务进程启动回调
     * @param swoole_server $server
     * @param $worker_id
     */
    Static Public function onstart(\swoole_server $server, int $worker_id)
    {
        global $argv;
        //实例化进程对象
        Root::$worker = new self();
        file_put_contents(TMP_PATH . "worker_{$worker_id}.pid", $server->worker_pid);
        swoole_set_process_name("Worker[{$worker_id}] process in <". __ROOT__ ."{$argv[0]}>");
        echo "WorkerID[{$worker_id}] PID[". $server->worker_pid ."] creation finish!" . PHP_EOL;
        //工作进程启动后执行
        $method = Conf::server('APP','worker_start');
        if(!empty($method))$method();
    }

    /**
     * 工作/任务进程终止回调
     * @param \swoole_server $server
     * @param int $worker_id
     */
    Static Public function onstop(\swoole_server $server, int $worker_id){
    }

    /**
     * 接收通道消息回调
     * @param swoole_server $server
     * @param int $from_worker_id
     * @param string $message
     */
    Static Public function pipeMessage(\swoole_server $server, int $from_worker_id, string $message)
    {
        $data = json_decode($message, true);
        $worker_num = Conf::server('SWOOLE','worker_num');
        if(isset($data['act']) && method_exists(Root::$worker, $data['act'])) {
            $act = $data['act'];
            Root::$worker->$act($data['data']);
        }elseif(isset($data['data']) && isset($data['worker_id']) && $data['worker_id'] >= 0 && $data['worker_id'] < $worker_num + Sub::$count && isset($data['cid'])){
            if(isset($data['callback'])) {
                if($data['callback'] == 2 && \Swoole\Coroutine::exists($data['cid'])){
                    Sub::$contents[$data['cid']] = $data['data'];
                    \Swoole\Coroutine::resume($data['cid']);
                    unset(Sub::$contents[$data['cid']]);
                    return true;
                }elseif(isset($data['data']['act']) && method_exists(Root::$worker, $data['data']['act'])){
                    $act = $data['data']['act'];
                    $res = Root::$worker->$act($data['data']['data']??null);
                    if($data['callback'] == 1){
                        if($data['worker_id'] < $worker_num)
                            Root::$serv->sendMessage(json_encode([
                                'data' => $res,
                                'cid' => $data['cid'],
                                'worker_id' => $data['worker_id'],
                                'callback' => 2
                            ]), $data['worker_id']);
                        else{
                            Sub::$procs[$data['worker_id'] - $worker_num]->write(json_encode([
                                'data' => $res,
                                'cid' => $data['cid'],
                                'worker_id' => $data['worker_id'],
                                'callback' => 2
                            ]));
                        }

                    }
                    return true;
                }
            }
            L("工作进程[{$data['worker_id']}]发来数据：\n" . var_export($data['data'], true), 'pipe', 'common');
        }
    }

    Private function __construct()
    {
        $this->id = Root::$serv->worker_id;
        $this->pid = Root::$serv->worker_pid;
        //加载函数库
        Root::loadFunc(APP_PATH);
        //加载应用类库
        Root::loadAppClass();
        //初始化数据库连接池
        Base\Model::_initialize();
        //启动心跳维持
        if(Conf::server('WEBSOCKET','is_enable'))Websocket::heartbeat();
    }

    public function __call($name, $arguments)
    {
        if(isset(self::$callFunc[$name])){
            $func = self::$callFunc[$name];
            call_user_func_array($func, $arguments);
        }else trigger_error('Worker实例中未储备匿名函数['. $name .']');
    }

    /**
     * 利用进程管道发送数据
     * @param string $act 方法名
     * @param array $data 带入参数
     * @param int $worker_id 目标工作进程ID，-1为全部进程
     * @return bool
     */
    Public function send(string $act, $data, int $worker_id = -1)
    {
        if($worker_id == $this->id){
            $this->$act($data);
            return true;
        }
        $datas = json_encode([
            'act' => $act,
            'data' => $data
        ]);
        $sum = Root::$serv->setting['worker_num'];
        if($worker_id > -1 && $worker_id < $sum){
            return Root::$serv->sendMessage($datas, $worker_id);
        }
        for($i = 0; $i < $sum; $i++){
            if($i == $this->id){
                $this->$act($data);
                break;
            }
            Root::$serv->sendMessage($datas, $i);
        }
        return true;
    }

    /**
     * 解锁/唤醒协程
     * @param int $cid 被挂起的协程ID
     */
    Public function unlock(int $cid)
    {
        if(\Swoole\Coroutine::exists($cid))\Swoole\Coroutine::resume($cid);
    }

    Public function getGlobals($keys = null)
    {
        $res = $GLOBALS;
        $_res = [];
        if(is_string($keys) && isset($res[$keys]))$_res = $res[$keys];
        elseif(is_array($keys)){
            foreach($keys as $key){
                if(isset($res[$key]))
                    $res = $res[$key];
                else break;
            }
            if($res != $GLOBALS)$_res = $res;
        }
        return $_res;
    }

    /**
     * 设置对应进程的公共变量
     * @param array $data
     */
    Public function setGlobals(array $data)
    {
        foreach ($data as $key => $val){
            $GLOBALS[$key] = $val;
        }
    }

}