<?php
declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Queue\Driver;

use Hyperf\Queue\JobInterface;
use Hyperf\Queue\Message;
use Hyperf\Queue\MessageInterface;
use Psr\Container\ContainerInterface;

class RedisDriver extends Driver
{
    /**
     * @var \Redis
     */
    protected $redis;

    protected $waiting;

    protected $reserved;

    protected $delayed;

    protected $failed;

    protected $moving;

    protected $timeout;

    public function __construct(ContainerInterface $container, $config)
    {
        parent::__construct($container, $config);
        $this->redis = $container->get(\Redis::class);

        $this->timeout = $config['timeout'] ?? 5;
        $this->waiting = "{$this->channel}.waiting";
        $this->reserved = "{$this->channel}.reserved";
        $this->delayed = "{$this->channel}.delayed";
        $this->failed = "{$this->channel}.failed";
        $this->moving = "{$this->channel}.moving";
    }

    public function push(JobInterface $job)
    {
        $message = new Message($job);
        $data = $this->packer->pack($message);
        $this->redis->lPush($this->waiting, $data);
    }

    public function delay(JobInterface $job, int $delay = 0)
    {
        if ($delay === 0) {
            return $this->push($job);
        }

        $message = new Message($job);
        $data = $this->packer->pack($message);
        $this->redis->zAdd($this->delayed, time() + $delay, $data);
    }

    public function pop(int $timeout = 0)
    {
        $this->move($this->delayed);
        $this->move($this->reserved);

        $res = $this->redis->brPop($this->waiting, $timeout);
        if (!isset($res[1])) {
            return [false, null];
        }

        $data = $res[1];
        $message = $this->packer->unpack($data);
        if (!$message) {
            return [false, null];
        }

        $this->redis->zadd($this->reserved, time() + 10, $data);

        return [$data, $message];
    }

    public function remove($data): bool
    {
        return $this->redis->zrem($this->reserved, $data) > 0;
    }

    public function ack($data)
    {
        $this->remove($data);
    }

    public function fail($data)
    {
        if ($this->remove($data)) {
            $this->redis->lPush($this->failed, $data);
        }
    }

    public function consume()
    {
        while (true) {
            list($key, $message) = $this->pop($this->timeout);

            if ($key === false) {
                continue;
            }

            try {
                if ($message instanceof MessageInterface) {
                    $message->job()->handle();
                }

                $this->ack($key);
            } catch (\Throwable $ex) {
                if ($message->attempts() && $this->remove($key)) {
                    // 10 seconds later handle it.
                    $data = $this->packer->pack($message);
                    $this->redis->zAdd($this->delayed, time() + 10, $data);
                } else {
                    $this->fail($key);
                }
            }
        }
    }

    /**
     * @param string $from
     */
    protected function move($from)
    {
        $now = time();
        if ($expired = $this->redis->zrevrangebyscore($from, (string)$now, '-inf')) {
            foreach ($expired as $job) {
                if ($this->redis->zRem($from, $job) > 0) {
                    $this->redis->lPush($this->waiting, $job);
                }
            }
        }
    }
}
