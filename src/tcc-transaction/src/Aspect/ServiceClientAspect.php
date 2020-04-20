<?php


namespace Hyperf\TccTransaction\Aspect;


use App\Controller\IndexController;
use Hyperf\Contract\IdGeneratorInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\RpcClient\Exception\RequestException;
use Hyperf\RpcClient\ServiceClient;
use Hyperf\Di\Container;
use Hyperf\TccTransaction\State;
use Hyperf\TccTransaction\TccTransaction;

/**
 * @Aspect()
 * Class ServiceClientAspect
 * @package Hyperf\TccTransaction\Aspect
 */
class ServiceClientAspect extends AbstractAspect
{


    public $classes = [
        ServiceClient::class . "::__call",
    ];

    /**
     * @Inject()
     * @var State
     */
    protected $state;

    /**
     * @var TccTransaction
     */
    private $tccTransaction;

    public function __construct()
    {
        $this->tccTransaction = make(TccTransaction::class);
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $result = self::guessBelongsToRelation();
        $servers = CompensableAnnotationAspect::get($result['class']);
        if ($servers && count($servers->slave) > 0) {
            #如果是TCC事务注解,且子服务不为空，触发Tcc事务
            $tcc_method = array_search($result['function'], $servers->master);
            if ($tcc_method == 'tryMethod') {
                $tid = $this->state->initStatus($servers, $proceedingJoinPoint->getArguments()[1][0]);#初始化事务状态
                return $this->tccTransaction->send($proceedingJoinPoint, $servers, $tcc_method,$tid);
            }

        }

        return $proceedingJoinPoint->process();

    }


    protected function guessBelongsToRelation()
    {
        [$one, $two, $three, $four, $five, $six, $seven, $eight, $nine] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9);
        return $eight;
    }

}
