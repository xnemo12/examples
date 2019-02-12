<?php

namespace Application\AppBundle\Service;

use Application\AppBundle\Entity\Person;
use Application\Sonata\UserBundle\Entity\User;
use Symfony\Component\DependencyInjection\Container;

class PersonManagerService {

    private $entityManager;

    public function __construct (Container $container) {
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
    }

    public function person ($rfid) {
        $repository = $this->entityManager->getRepository('AppBundle:Person');
        return $repository->findOneBy(['rfid' => $rfid]);
    }

    public function checkPersonsPhone (Person $person, $phone) {
        $phones = $person->getPhone();

        foreach ($phones as $p) {
            if ($p->getPhone() == $phone) return true;
        }

        return false;
    }

    public function personsByPhone ($phone) {
        $phones = $this->entityManager->getRepository('AppBundle:Phone')->findBy(['phone' => $phone]);
        $persons = [];

        foreach ($phones as $p) {
            $persons[] = $p->getPerson();
        }

        return $persons;
    }

    public function bindPersonsToUser (User $user, $persons) {
        foreach ($persons as $person) {
            if (!$person) break;

            if(!$person->getUser()->contains($user)){
                $person->addUser($user);
                $this->entityManager->persist($person);
            }
        }
    }
}
