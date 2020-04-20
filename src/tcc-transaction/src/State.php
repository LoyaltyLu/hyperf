<?php


namespace Hyperf\TccTransaction;


use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

class State
{

    const RETRIED_CANCEL_COUNT = 0;#重试次数
    const RETRIED_CONFIRM_COUNT = 0;#重试次数
    const RETRIED_MAX_COUNT = 1;#最大允许重试次数

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 初始化事务状态，服务列表以及参数
     * @param $services
     * @param $params
     * @return string
     */
    public function initStatus($services, $params)
    {
        $tid = session_create_id(md5(microtime()));
        $tccData = [
            'tid' => $tid, //事务id
            'services' => $services, //参与者信息
            'content' => $params, //传递的参数
            'status' => 'normal', //(normal,abnormal,success,fail)事务整体状态
            'tcc_method' => 'tryMethod', //try,confirm,cancel (当前是哪个阶段)
            'retried_cancel_count' => self::RETRIED_CANCEL_COUNT, //重试次数
            'retried_confirm_count' => self::RETRIED_CONFIRM_COUNT, //重试次数
            'retried_max_count' => self::RETRIED_MAX_COUNT, //最大允许重试次数
            'create_time' => time(), //创建时间
            'last_update_time' => time(), //最后的更新时间
        ];
        $this->redis->hSet("Tcc", $tid, json_encode($tccData));
        return $tid;
    }

    /**
     * 事务状态处理器
     * @param string $tid
     * @param int $flag
     * @param string $tcc_method
     * @param array $data
     * @return bool
     */
    public function tccStatus(string $tid, $flag = 1, $tcc_method = '', $data = [])
    {
        $originalData = $this->redis->hget("Tcc", $tid);
        $originalData = json_decode($originalData, true);
        //(回滚处理)修改回滚次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 1) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            if ($originalData['retried_cancel_count'] >= $originalData['retried_max_count']) {
                $originalData['status'] = 'fail';
                $this->redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            $originalData['retried_cancel_count'] ++;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $originalData['last_update_time'] = time();
            $this->redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }
        //(confirm处理)修改尝试次数,并且记录当前是哪个阶段出现了异常
        if ($flag == 2) {
            //判断当前事务重试的次数为几次,如果重试次数超过最大次数,则取消重试
            if ($originalData['retried_confirm_count'] >= 1) {
                $originalData['status'] = 'fail';
                $this->redis->hSet('Tcc', $tid, json_encode($originalData));
                return false;
            }
            $originalData['retried_confirm_count'] ++;
            $originalData['tcc_method'] = $tcc_method;
            $originalData['status'] = 'abnormal';
            $originalData['last_update_time'] = time();
            $this->redis->hSet('Tcc', $tid, json_encode($originalData));
            return true;
        }
        //修改当前事务的阶段
//        if ($flag == 3) {
//            $originalData['tcc_method'] = $data['tcc_method'];
//            $originalData['status'] = $data['status'];
//            $originalData['last_update_time'] = time();
//            $this->redis->hSet('Tcc', $tid, json_encode($originalData)); //主服务状态
//        }
    }

    /**
     * 修改事务整体服务的状态
     * @param $tid
     * @param $data
     */
    public function updateTccStatus($tid, $tcc_method,$status)
    {
        $originalData = $this->redis->hget("Tcc", $tid);
        $originalData = json_decode($originalData, true);
        $originalData['tcc_method'] = $tcc_method;
        $originalData['status'] = $status;
        $originalData['last_update_time'] = time();
        $this->redis->hSet('Tcc', $tid, json_encode($originalData)); //主服务状态
    }
}
