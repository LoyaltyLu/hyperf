<?php


namespace Hyperf\TccTransaction;

use Hyperf\Di\Annotation\Inject;
use Hyperf\TccTransaction\Exception\TccTransactionException;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Exception\ParallelExecutionException;
use Hyperf\Utils\Parallel;
use Hyperf\Di\Container;

class TccTransaction
{
    /**
     * @Inject()
     * @var State
     */
    protected $state;

    /**
     * 开始事务
     * @param $proceedingJoinPoint
     * @param $servers
     * @param $tcc_method
     * @param $tid
     * @return array
     */
    public function send($proceedingJoinPoint, $servers, $tcc_method, $tid, $params)
    {
        $parallel = new Parallel();
        if ($tcc_method == 'tryMethod') {
            $parallel->add(function () use ($proceedingJoinPoint) {
                return $proceedingJoinPoint->process();
            });
        } else {
            $parallel->add(function () use ($params, $servers, $tcc_method) {
                $container = ApplicationContext::getContainer()->get($servers->master['services']);
                $tryMethod = $servers->master[$tcc_method];
                return $container->$tryMethod($params);
            });
        }

        foreach ($servers->slave as $key => $value) {
            $parallel->add(function () use ($value, $params, $tcc_method, $key) {
                $container = ApplicationContext::getContainer()->get($value['services']);
                $tryMethod = $value[$tcc_method];
                return $container->$tryMethod($params);
            });
        }
        try {
            $results = $parallel->wait();
            #TODO: 如果等待发起时本服务挂了，如何处理
            $this->state->upAllTccStatus($tid, $tcc_method, 'success');
            if ($tcc_method == 'tryMethod') {
                $results = $this->send($proceedingJoinPoint, $servers, 'confirmMethod', $tid, $params);
            }
            return $results;
        } catch (ParallelExecutionException $e) {
            return $this->errorTransction($tcc_method, $proceedingJoinPoint, $servers, $tid, $params);
        }

    }

    /**
     * 尝试回滚
     * @param $tcc_method
     * @param $proceedingJoinPoint
     * @param $servers
     * @param $tid
     * @param $params
     * @return array
     */
    public function errorTransction($tcc_method, $proceedingJoinPoint, $servers, $tid, $params)
    {
        switch ($tcc_method) {
            case 'tryMethod':
                return $this->send($proceedingJoinPoint, $servers, 'cancelMethod', $tid, $params); #tryMethod阶段失败直接回滚
            case 'cancelMethod':
                if ($this->state->upTccStatus($tid, $tcc_method, 'retried_cancel_count')) {
                    return $this->send($proceedingJoinPoint, $servers, 'cancelMethod', $tid, $params); #tryMethod阶段失败直接回滚
                }
                throw new  TccTransactionException('回滚异常');
            case 'confirmMethod':
                if ($this->state->upTccStatus($tid, $tcc_method, 'retried_confirm_count')) {
                    return $this->send($proceedingJoinPoint, $servers, 'confirmMethod', $tid, $params);
                }
                $params['cancel_confirm_flag'] = 1;
                return $this->send($proceedingJoinPoint, $servers, 'cancelMethod', $tid, $params);
        }

    }

}
