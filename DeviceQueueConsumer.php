<?php

namespace Application\ApiBundle\Consumer;

use Application\AppBundle\Entity\ConnectionHistory;
use Application\AppBundle\Service\Helper;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class DeviceQueueConsumer implements ConsumerInterface
{
    private $em;
    private $logger;
    private $helper;

    public function __construct(EntityManager $em, Logger $logger, Helper $helper)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function execute(AMQPMessage $msg)
    {
        $this->logger->addNotice($msg->body);

        $ping = json_decode($msg->body);

        $device = $this->em->getRepository('AppBundle:Device')->find($ping->id);

        if ($device){

            $device->setIsConnected($ping->connected);

            $deviceTime = $this->helper->intToDateTime($ping->time);
            $this->logger->addWarning($deviceTime);

            $device->setLastRequest(new \DateTime($deviceTime));
            $device->setDeviceTime($ping->time);
            $this->em->persist($device);

            $connection = new ConnectionHistory();
            $connection->setType(true);
            $connection->setDevice($device);
            $connection->setCreated(new \DateTime($deviceTime));

            $this->em->persist($connection);
            $this->em->flush();

        } else {

            $this->logger->addWarning($ping->id . " - device is not found!");

        }

        return true;
    }
}
