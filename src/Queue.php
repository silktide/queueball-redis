<?php

namespace Silktide\QueueBall\Redis;

use Predis\Client;
use Silktide\QueueBall\Message\QueueMessage;
use Silktide\QueueBall\Message\QueueMessageFactoryInterface;
use Silktide\QueueBall\Queue\AbstractQueue;

/**
 * Queue
 */
class Queue extends AbstractQueue
{

    /**
     * @var Client
     */
    protected $predis;

    /**
     * @var QueueMessageFactoryInterface
     */
    protected $messageFactory;

    protected $receivedMessages = [];
    protected $receivedMessageCounter = 0;

    public function __construct(Client $predis, QueueMessageFactoryInterface $messageFactory, $queueId = null)
    {
        $this->predis = $predis;
        $this->messageFactory = $messageFactory;

        parent::__construct($queueId);

        // make sure the destruct method is called
        register_shutdown_function([$this, "__destruct"]);
    }

    public function createQueue($queueId, $options = [])
    {
        // Nothing to do, Redis queues are created if they do not exist
    }

    public function deleteQueue($queueId = null)
    {
        $queueId = $this->normaliseQueueId($queueId);

        $this->predis->del($queueId);
    }

    public function sendMessage($messageBody, $queueId = null)
    {
        $queueId = $this->normaliseQueueId($queueId);
        $this->predis->rpush($queueId, [$messageBody]);
    }

    public function receiveMessage($queueId = null, $waitTime = 0)
    {
        $queueId = $this->normaliseQueueId($queueId);
        $message = $this->predis->blpop([$queueId], $waitTime);

        /** @var QueueMessage $queueMessage */
        $queueMessage = $this->messageFactory->createMessage($message, $queueId);
        
        $index = $this->receivedMessageCounter++;
        $this->receivedMessages[$index] = $queueMessage;
        $queueMessage->setReceiptId($index);
        
        return $queueMessage;
    }

    public function completeMessage(QueueMessage $message)
    {
        // all we have to do is remove the reference to this message in receivedMessages
        unset($this->receivedMessages[$message->getReceiptId()]);
    }

    public function returnMessage(QueueMessage $message)
    {
        // re-add the message to the queue, as the first element
        $this->predis->lpush($message->getQueueId(), $message->getMessage());
        // forget we received the message
        $this->completeMessage($message);
    }

    public function normaliseQueueId($queueId = null) {
        if (empty($queueId)) {
            return $this->getQueueId();
        }
        return $queueId;
    }
    
    public function __destruct()
    {
        foreach ($this->receivedMessages as $message) {
            /** @var QueueMessage $message */
            $this->returnMessage($message);
        }
    }

}