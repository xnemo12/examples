<?php

namespace Application\ApiBundle\Service;

use JMS\Serializer\SerializationContext;
use Symfony\Component\DependencyInjection\Container;

class GpsService
{
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function optimize($locations)
    {
        $result = []; $prelocation = null; $pretime = null; $i=0;

        foreach ($locations as $location) {
            if($i==0){
                $result[] = $location;
            } else {
                $distance = $this->container->get('app.helper')
                    ->getDistanceFromLatLonInKm($location->getLat(), $location->getLong(), $prelocation->getLat(), $prelocation->getLong());
                $interval = $location->getRegistered()->diff($pretime);
                if($interval->s > 0){
                    $speed = ($distance/1000) / ($interval->s/3600);
                    $this->container->get('monolog.logger.api')->addEmergency('INTERVAL:' . $interval->s . ' DISTANCE:' . $distance. ' SPEED:' . $speed .   '  TIME: '.$location->getRegistered()->format('Y-m-d H:i:s'));
                    if($speed<120) {
                        $result[] = $location;
                    }
                }
            }
            $prelocation = $location; $pretime = $location->getRegistered();
            $i++;
        }

        return $result;
    }
}
