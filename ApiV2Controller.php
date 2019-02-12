<?php

namespace Application\ApiBundle\Controller;

use Application\ApiBundle\Entity\Location;
use Application\AppBundle\DBAL\Types\PersonType;
use Application\AppBundle\Entity\Person;
use Application\AppBundle\Service\Polyline;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcher;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FOS\RestBundle\Controller\Annotations\View;


class ApiV2Controller extends FOSRestController
{
    /**
     * @View
     * @QueryParam(name="date", default="1970-01-01 00:00:00")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Return persons for App",
     *  parameters={
     *      {"name"="date", "dataType"="date", "required"=false, "default"="1970-01-01 00:00:00",
     *          "description"="Если указывать дату, получаем записи которые были изменени после этой даты,
     *              если не укажем дату: получаем все записи текущего пользователя"}
     *  }
     * )
     * @return Response
     */
    public function getPersonsAction(ParamFetcher $paramFetcher)
    {
        $user = $this->getUser();

        $em = $this->getDoctrine()->getEntityManager();
        $phones = $this->getDoctrine()->getRepository('AppBundle:Phone')->findBy(['phone'=>$user->getUsername()]);
        foreach($phones as $phone){
            $person = $phone->getPerson();
            if(!$person->getUser()->contains($user) && $person->getType()==PersonType::STUDENT){
                $person->addUser($user);
                $em->persist($person);
                $em->flush();
            }
        }

        $query = $this->getDoctrine()->getEntityManager()
            ->createQueryBuilder()
            ->select('p')
            ->from('AppBundle:Person', 'p')
            ->join('p.user', 'u')
            ->join('p.department', 'd')
            ->addSelect('d')
            ->join('p.organization', 'o')
            ->addSelect('o')
            ->join('o.type', 't')
            ->addSelect('t')
//            ->leftJoin('p.locations', 'l')
//            ->leftJoin('p.locations', 'l2', "WITH", 'p=l2.person AND l.registered < l2.registered')
//            ->addSelect('l')
            ->where('u.id=:uid')
            ->andWhere('p.updated >= :date or p.updated is null')
//            ->andWhere('l2.id IS NULL')
        ;

        $query->setParameters(['uid'=>$user->getId(), 'date'=>$paramFetcher->get('date')]);

        $persons = $query->getQuery()->getArrayResult();

        $p = [];

        $result = [];
        foreach($persons as $person){
            $photo = is_array($person['photo']);
            $p['id'] = $person['id'];
            $p['name'] = $person['name'];
            $p['rfid'] = $person['rfid'];
            $p['code'] = $person['code'];
            $p['gender'] = $person['gender'];
            $p['birthday'] = $person['birthday'];
            $p['photo'] = $photo;
            $p['group'] = $person['department']['name'];
            $p['organization'] = $person['organization']['name'];
            $p['org_type'] = $person['organization']['type']['id'];
            $p['gps'] = $person['gps'];
            $p['lat'] = null;//empty($person['locations']) ? null : $person['locations'][0]['lat'];
            $p['lng'] = null;//empty($person['locations']) ? null : $person['locations'][0]['long'];
            $p['location_time'] = empty($person['locations']) ? null : $person['locations'][0]['registered'];
            $result[] = $p;
        }

        $view = $this->view($result, 200);
        return $this->handleView($view);
    }

    public function getPhotosAction(Person $person)
    {
        $photo = $person->getPhoto();
        $path = $this->get('kernel')->getRootDir() . '/../web'.$photo['path'];
        $content = file_get_contents($path);

        $response = new Response();

        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Content-Disposition', 'attachment;filename="'.$person->getCode().'.jpg"');//.$filename);

        $response->setContent($content);
        return $response;
    }

    /**
     * @View
     * @QueryParam(name="date", default="1970-01-01 00:00:00")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Return Schedule for App",
     *  parameters={
     *      {"name"="date", "dataType"="date", "required"=false, "default"="1990-01-01",
     *          "description"="Возвращает список расписания для учеников текущего пользователья.
     *                      Входные параметры: дата (необъязательный параметр). "}
     *  }
     * )
     * @return Response
     */
    public function getScheduleAction(ParamFetcher $paramFetcher)
    {
        $user = $this->getUser();

        $p = [];

        foreach($user->getPerson() as $person){

            $schedules = $this->getDoctrine()->getRepository('MarkBundle:Schedule')
                ->getScheduleByDepartment(
                    $person->getDepartment()->getId(),
                    $paramFetcher->get('date')
                )
            ;

            $p[$person->getId()] = $schedules;
        }

        $view = $this->view($p, 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="date", default="1970-01-01 00:00:00")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Return Visits for App",
     *  parameters={
     *      {"name"="date", "dataType"="date", "required"=false, "default"="1970-01-01 00:00:00",
     *          "description"="Возвращает список посещений для учеников текущего пользователья. Входные параметры дата (необъязательный параметр). "}
     *  }
     * )
     * @return Response
     */
    public function getVisitAction(ParamFetcher $paramFetcher)
    {
        $user = $this->getUser();

        $p = [];

        foreach($user->getPerson() as $person){

            $visits = $this->getDoctrine()->getRepository('AppBundle:Visit')
                ->getVisitsByPerson(
                    $person->getId(),
                    $paramFetcher->get('date')
                );

            $visits_array = [];

            foreach($visits as $v){
                $visits_array[] = [
                    'id'=>$v['id'],
                    'registered'=>$v['registered'],
                    'event'=>$v['event'],
                    'isLate'=>$v['isLate'],
                    'building'=>$v['building']
                ];
            }

            $p[$person->getId()] = $visits;
        }

        $view = $this->view($p, 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="date", default="1970-01-01 00:00:00")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Return Marks for App",
     *  parameters={
     *      {"name"="date", "dataType"="date", "required"=false, "default"="1970-01-01 00:00:00",
     *          "description"="Возвращает список оценок для учеников текущего пользователья. Входные параметры дата (необъязательный параметр). "}
     *  }
     * )
     * @return Response
     */
    public function getMarkAction(ParamFetcher $paramFetcher)
    {
        $user = $this->getUser();

        $p = [];

        foreach($user->getPerson() as $person){

            $p[$person->getId()] = $this->getDoctrine()->getRepository('MarkBundle:Mark')
                ->getMarksByPersons(
                    $person->getId(),
                    $paramFetcher->get('date')
                );
        }

        $view = $this->view($p, 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="current_password")
     * @QueryParam(name="new_password")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Take new password for App",
     *  parameters={
     *      {"name"="current_parameter", "dataType"="string", "required"=true,
     *          "description"="старый пароль"},
     *      {"name"="new_password", "dataType"="string", "required"=true,
     *          "description"="новый пароль"}
     *  }
     * )
     * @return Response
     */
    public function getPasswordAction(ParamFetcher $paramFetcher)
    {
        try
        {
            $user_manager = $this->get('fos_user.user_manager');
            $user = $user_manager->findUserBy(['id'=>$this->getUser()->getId()]);

            $factory = $this->get('security.encoder_factory');
            $encoder = $factory->getEncoder($user);

            $current_password = $paramFetcher->get('current_password');
            $new_password = $paramFetcher->get('new_password');

            $bool = ($encoder->isPasswordValid($user->getPassword(), $current_password,$user->getSalt())) ? true : false;

            if($bool){
                $user->setPlainPassword($new_password);
                $user_manager->updateUser($user);
                $result=['success'=>true, 'msg'=>'Пароль успешно изменен'];
            } else {
                $result=['success'=>false, 'msg'=>'Неправильный текущий пароль'];
            }
        } catch(Exception $e){

            $result=['success'=>false, 'msg'=>'Ошибка, посмотрите лог для подробной информации'];
        }
        $view = $this->view($result, 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="token")
     * @QueryParam(name="device")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Get token for GCM Service",
     *  parameters={
     *      {"name"="token", "dataType"="string", "required"=true,
     *          "description"="токен"},
     *     {"name"="device", "dataType"="string", "required"=true,
     *          "description"="девайс"}
     *  }
     * )
     * @return Response
     */
    public function getTokenAction(ParamFetcher $paramFetcher)
    {
        try
        {
            $token = $paramFetcher->get('token');
            $device = $paramFetcher->get('device');
            if($token){

                $tokenManager = $this->get('fos_oauth_server.access_token_manager.default');

		$this->get('monolog.logger.api')->addEmergency($this->get('security.token_storage')->getToken()->getToken());

                $accessToken = $tokenManager->findTokenByToken(
                    $this->get('security.token_storage')->getToken()->getToken()
                );
                $client = $accessToken->getClient();

		$this->get('monolog.logger.api')->addEmergency($client->getId());

                $client_manager = $this->get('fos_oauth_server.client_manager');
                $client->setGcmToken($token);
                $client->setDevice($device);
                $client_manager->updateClient($client);
                $result = ['success'=>true, 'msg'=>'Токен успешно сохранен'];
            } else {
                $result = ['success'=>false, 'msg'=>'Token required'];
            }

        } catch(Exception $e){

            $result=['success'=>false, 'msg'=>'Ошибка, посмотрите лог для подробной информации'];
        }
        $view = $this->view($result, 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="times")
     * @QueryParam(name="polyline")
     * @ApiDoc(
     *  description="Save students current location (gps lon|lat)",
     *  parameters={
     *     {"name"="times", "dataType"="array", "required"=false},
     *     {"name"="polyline", "dataType"="string", "required"=false}
     *  }
     * )
     * @return Response
     */
    public function postGpsAction(Request $request) {

        try{
            $param = json_decode($request->getContent(), true);

            $em = $this->getDoctrine()->getManager();

            if(is_null($request->get('times')) || $request->get('times')!=''){
                $times = $param['times'];
            } else {
                $times = json_decode($request->get('times'));
            }

            $polyline = $request->get('polyline', $param['polyline']);

            $points = Polyline::decode($polyline);
            $points = Polyline::pair($points);

            $person = $this->getUser()->getPerson()->first();
            $this->get('monolog.logger.api')->addEmergency($person . json_encode($times));

            $i=0;
            foreach ($times as $t) {
                if( is_array($points[$i]) && count($points[$i])==2 ){

                    $lat = $points[$i][0];
                    $long = $points[$i][1];
                    $lat2 = null;
                    $long2 = null;
                    $last_time = date('Y-m-d H:i:s');

                    $pl = $em->getRepository('ApiBundle:Location')->findOneBy(['person'=>$person], ['id'=>'DESC']);

                    if($pl){
                        $lat2 = $pl->getLat();
                        $long2 = $pl->getLong();
                        $last_time = $pl->getRegistered()->format('Y-m-d H:i:s');
                    }

                    $distance = $this->get('app.helper')->getDistanceFromLatLonInKm($lat, $long, $lat2, $long2);

                    if($distance>150 or is_null($pl)){
                        $location = new Location();
                        $location->setPerson($person);
                        $location->setLat($lat);
                        $location->setLong($long);
                        $location->setRegistered(new \DateTime($t));
                        $em->persist($location);
                        $em->flush();
                    } elseif(strtotime($t) > strtotime($last_time)){
                        $pl->setLat($lat);
                        $pl->setLong($long);
                        $pl->setRegistered(new \DateTime($t));
                        $em->persist($pl);
                        $em->flush();
                    }
                }
                $i++;
            }
            //$em->flush();
            $result = ['success' => true, 'msg' => 'Координаты успешно сохранены'];

        }catch(Exception $e){
            $result=['success'=>false, 'msg'=>'Ошибка отправки координат'];
        }

        $view = $this->view($result, 200);
        return $this->handleView($view);
    }

    /**
     * @param ParamFetcher $param
     * @internal param ParamFetcher $param
     * @return Response
     *
     * @View
     * @QueryParam(name="date_end")
     * @QueryParam(name="date_begin", default="1970-01-01 00:00:00")
     * @QueryParam(name="id")
     *
     * @ApiDoc(
     *  description="Get students location history",
     *  parameters={
     *      {
     *          "name"="date_begin", "dataType"="date", "required"=false, "default"="1970-01-01 00:00:00",
     *          "description"="Если указывать дату, получаем записи которые были добавлены после этой даты"
     *      }, {
     *          "name"="date_end", "dataType"="date", "required"=false, "default"="now",
     *          "description"="Если указывать дату, получаем записи которые были добавлены до этой даты"
     *      }, {
     *          "name"="id", "dataType"="integer", "required"=true,
     *          "description"="ID ученика"
     *      }
     *  }
     * )
     */
    public function getGpsAction(ParamFetcher $param)
    {
        $person = $this->getDoctrine()
            ->getRepository('AppBundle:Person')
            ->findOneBy(['id'=>$param->get('id')]);

        if (empty($person)) return $this->notFound('Person was not found!!');

        $locations = $this->getDoctrine()
            ->getRepository('ApiBundle:Location')
            ->getLocation($person, $param->get('date_begin'), $param->get('date_end'), 1);

        $times = []; $tuples=[];

        $locations = $this->get('gps.service')->optimize($locations);

        foreach ($locations as $location) {
            $times[] = $location->getRegistered();
            $tuples[] = [$location->getLat(), $location->getLong()];
        }

//        $times = []; $tuples=[];
//        $location = $this->getDoctrine()
//            ->getRepository('ApiBundle:Location')
//            ->findOneBy(['person'=>$person], ['registered'=>'DESC']);
//        $times[] = $location->getRegistered();
//        $tuples[] = [$location->getLat(), $location->getLong()];

        $polyline = Polyline::encode($tuples);

        $result = [];

        $result['success'] = true;
        $result['times'] = $times;
        $result['polyline'] = $polyline;

        $view = $this->view($result, 200);

        return $this->handleView($view);
    }

    /**
     * @param ParamFetcher $param
     * @internal param ParamFetcher $param
     * @return Response
     *
     * @View
     * @QueryParam(name="id")
     *
     * @ApiDoc(
     *  description="Get last students location",
     *  parameters={
     *      {
     *          "name"="id", "dataType"="integer", "required"=true,
     *          "description"="ID ученика"
     *      }
     *  }
     * )
     */
    public function getLocationAction(ParamFetcher $param)
    {
        $person = $this->getDoctrine()
            ->getRepository('AppBundle:Person')
            ->findOneBy(['id'=>$param->get('id')]);

        if (empty($person)) return $this->notFound('Person was not found!!');

        $location = $this->getDoctrine()
            ->getRepository('ApiBundle:Location')
            ->findOneBy(['person'=>$person], ['id'=>'DESC']);

        $result = [];

        if($location){
            $result['success'] = true;
            $result['longitude'] = $location->getLong();
            $result['latitude'] = $location->getLat();
            $result['registered'] = $location->getRegistered();
        } else {
            $result['success'] = false;
            $result['msg'] = "Location not found";
        }

        $view = $this->view($result, 200);

        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="badge", default="0")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Set Topic Badge value, default=0",
     *  parameters={
     *      {"name"="badge", "dataType"="integer", "required"=false, "default"="0",
     *          "description"="Обнуление topicBadge"}
     *  }
     * )
     * @return Response
     */
    public function postTopicBadgeAction(ParamFetcher $paramFetcher)
    {
        $badge = $paramFetcher->get('badge');
        $tokenManager = $this->get('fos_oauth_server.access_token_manager.default');
        $accessToken = $tokenManager->findTokenByToken(
            $this->get('security.token_storage')->getToken()->getToken()
        );
        $client = $accessToken->getClient();
        $client_manager = $this->get('fos_oauth_server.client_manager');
        $client->setTopicBadge($badge);
        $client_manager->updateClient($client);

        $view = $this->view([
            'success' => true,
            'msg' => 'Badge reset successfully'
        ], 200);
        return $this->handleView($view);
    }

    /**
     * @View
     * @QueryParam(name="badge", default="0")
     * @param ParamFetcher $paramFetcher
     * @ApiDoc(
     *  description="Set Badge value, default=0",
     *  parameters={
     *      {"name"="badge", "dataType"="integer", "required"=false, "default"="0",
     *          "description"="Обнуление Badge"}
     *  }
     * )
     * @return Response
     */
    public function postBadgeAction(ParamFetcher $paramFetcher)
    {
        $badge = $paramFetcher->get('badge');
        $tokenManager = $this->get('fos_oauth_server.access_token_manager.default');
        $accessToken = $tokenManager->findTokenByToken(
            $this->get('security.token_storage')->getToken()->getToken()
        );
        $client = $accessToken->getClient();
        $client_manager = $this->get('fos_oauth_server.client_manager');
        $client->setBadge($badge);
        $client_manager->updateClient($client);

        $view = $this->view([
            'success' => true,
            'msg' => 'Badge reset successfully'
        ], 200);
        return $this->handleView($view);
    }

    private function notFound ($msg = 'Не найдено') {
        $view = $this->view([
            'success' => false,
            'msg' => $msg
        ], 404);

        return $this->handleView($view);
    }

    private function setBadge($badge = 0)
    {
        $tokenManager = $this->get('fos_oauth_server.access_token_manager.default');
        $accessToken = $tokenManager->findTokenByToken(
            $this->get('security.token_storage')->getToken()->getToken()
        );
        $client = $accessToken->getClient();
        $client_manager = $this->get('fos_oauth_server.client_manager');
        $client->setBadge($badge);
        $client_manager->updateClient($client);
    }
}
