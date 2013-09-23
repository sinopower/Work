<?php

namespace FunctionalTest\MyCLabs\Work\RabbitMQ;

use MyCLabs\Work\Dispatcher\RabbitMQWorkDispatcher;
use MyCLabs\Work\TaskExecutor\TaskExecutor;
use MyCLabs\Work\Worker\RabbitMQWorker;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PHPUnit_Framework_TestCase;

/**
 * Test executing tasks through RabbitMQ
 */
class RabbitMQTest extends PHPUnit_Framework_TestCase
{
    const QUEUE_PREFIX = 'myclabs_work_test';

    /**
     * @var AMQPConnection
     */
    private $connection;

    /**
     * @var AMQPChannel
     */
    private $channel;

    /**
     * @var string
     */
    private $queue;

    public function setUp()
    {
        try {
            $this->connection = new AMQPConnection('localhost', 5672, 'guest', 'guest');
        } catch (AMQPRuntimeException $e) {
            // RabbitMQ not installed, mark test skipped
            $this->markTestSkipped('RabbitMQ is not installed or was not found');
            return;
        }
        $this->queue = self::QUEUE_PREFIX . '_' . rand();
        $this->channel = $this->connection->channel();
        $this->channel->queue_declare($this->queue, false, false, false, false);
    }

    public function tearDown()
    {
        $this->channel->queue_delete($this->queue);
        $this->channel->close();
        $this->connection->close();
    }

    public function testSimpleRunBackground()
    {
        $workDispatcher = new RabbitMQWorkDispatcher($this->channel, $this->queue);

        // Pile up a task to execute
        $task = new FakeTask();
        $workDispatcher->runBackground($task);

        // Run the worker to execute the task
        $worker = new RabbitMQWorker($this->channel, $this->queue);

        // Check that event methods are called
        $listener = $this->getMock('MyCLabs\Work\EventListener');
        $listener->expects($this->once())
            ->method('beforeTaskExecution');
        $listener->expects($this->once())
            ->method('onTaskSuccess');
        $worker->addEventListener($listener);

        // Fake task executor
        $taskExecutor = $this->getMockForAbstractClass('MyCLabs\Work\TaskExecutor\TaskExecutor');
        $taskExecutor->expects($this->once())
            ->method('execute')
            ->with($task);
        $worker->registerTaskExecutor(get_class($task), $taskExecutor);

        // Work
        $worker->work(1);
    }

    public function testRunBackgroundWithException()
    {
        $workDispatcher = new RabbitMQWorkDispatcher($this->channel, $this->queue);

        // Pile up a task to execute
        $task = new FakeTask();
        $workDispatcher->runBackground($task);

        // Run the worker to execute the task
        $worker = new RabbitMQWorker($this->channel, $this->queue);

        // Check that event methods are called
        $listener = $this->getMock('MyCLabs\Work\EventListener');
        $listener->expects($this->once())
            ->method('beforeTaskExecution');
        $listener->expects($this->once())
            ->method('onTaskException');
        $worker->addEventListener($listener);

        // Fake task executor
        $taskExecutor = $this->getMockForAbstractClass('MyCLabs\Work\TaskExecutor\TaskExecutor');
        $taskExecutor->expects($this->once())
            ->method('execute')
            ->with($task)
            ->will($this->throwException(new \Exception()));
        $worker->registerTaskExecutor(get_class($task), $taskExecutor);

        // Work
        $worker->work(1);
    }

    /**
     * Test that if we wait for a task and it times out, the callback is called
     */
    public function testRunBackgroundWithTimeout()
    {
        $workDispatcher = new RabbitMQWorkDispatcher($this->channel, $this->queue);

        // Check that "timeout" is called, but not "completed"
        $mock = $this->getMock('stdClass', ['completed', 'timeout']);
        $mock->expects($this->never())
            ->method('completed');
        $mock->expects($this->once())
            ->method('timeout');

        // Pile up a task to execute and let it timeout
        $workDispatcher->runBackground(new FakeTask(), 0.01, [$mock, 'completed'], [$mock, 'timeout']);
    }

    /**
     * Test the Dispatcher with waiting for the job to complete
     */
    public function testRunBackgroundWithWait()
    {
        $workDispatcher = new RabbitMQWorkDispatcher($this->channel, $this->queue);

        // Run the worker as background task
        $file = __DIR__ . '/worker.php';
        $log = __DIR__ . '/worker.log';
        exec("php $file {$this->queue} > $log 2> $log &");

        // Check that "completed" is called, but not "timeout"
        $mock = $this->getMock('stdClass', ['completed', 'timeout']);
        $mock->expects($this->once())
            ->method('completed');
        $mock->expects($this->never())
            ->method('timeout');

        $workDispatcher->runBackground(new FakeTask(), 1, [$mock, 'completed'], [$mock, 'timeout']);

        // Check that the log is empty (no error)
        $this->assertStringEqualsFile($log, '');
    }

    /**
     * Test the Worker with waiting for the job to complete
     */
    public function testWorkWithWait()
    {
        $worker = new RabbitMQWorker($this->channel, $this->queue);
        /** @var TaskExecutor $taskExecutor */
        $taskExecutor = $this->getMockForAbstractClass('MyCLabs\Work\TaskExecutor\TaskExecutor');
        $worker->registerTaskExecutor('FunctionalTest\MyCLabs\Work\RabbitMQ\FakeTask', $taskExecutor);

        // Run the task dispatcher as background task (it will emit 1 task and wait for it)
        $file = __DIR__ . '/dispatch-task.php';
        $log = __DIR__ . '/dispatch-task.log';
        exec("php $file {$this->queue} > $log 2> $log &");

        // Execute 1 task
        $worker->work(1);

        // Check that the log is empty (no error)
        $this->assertStringEqualsFile($log, '');
    }
}
