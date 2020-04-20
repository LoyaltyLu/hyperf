<?php


namespace Hyperf\TccTransaction;


use Hyperf\Di\Annotation\Inject;
use Hyperf\RpcClient\Exception\RequestException;
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
    public function send($proceedingJoinPoint, $servers, $tcc_method, $tid)
    {
        $parallel = new Parallel();
        if ($tcc_method == 'tryMethod') {
            $parallel->add(function () use ($proceedingJoinPoint) {
                return $proceedingJoinPoint->process();
            });
        } else {
            $parallel->add(function () use ($proceedingJoinPoint, $servers, $tcc_method) {
                $container = ApplicationContext::getContainer()->get($servers->master['services']);
                $tryMethod = $servers->master[$tcc_method];
                return $container->$tryMethod($proceedingJoinPoint->getArguments()[1][0]);
            });
        }

        foreach ($servers->slave as $key => $value) {
            $parallel->add(function () use ($value, $proceedingJoinPoint, $tcc_method, $key) {
                $container = ApplicationContext::getContainer()->get($value['services']);
                $tryMethod = $value[$tcc_method];
                return $container->$tryMethod($proceedingJoinPoint->getArguments()[1][0]);
            });
        }
        try {
            $results = $parallel->wait();
            $this->state->updateTccStatus($tid, $tcc_method, 'success');
            if ($tcc_method == 'tryMethod') {
                $results = $this->send($proceedingJoinPoint, $servers, 'confirmMethod', $tid);
            }
            return $results;
        } catch (ParallelExecutionException $e) {
            $this->state->updateTccStatus($tid, $tcc_method, 'fail');
            return $this->send($proceedingJoinPoint, $servers, 'cancelMethod', $tid);
        }

    }

}
