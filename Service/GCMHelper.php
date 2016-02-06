<?php
namespace Sopinet\GCMBundle\Service;
use Application\Sopinet\UserBundle\Entity\User;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use PetyCash\AppBundle\Entity\Message;
use RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException;
use RMS\PushNotificationsBundle\Message\AndroidMessage;
use RMS\PushNotificationsBundle\Message\iOSMessage;
use RMS\PushNotificationsBundle\Service\OS\AndroidGCMNotification;
use Sopinet\GCMBundle\Model\Msg;
use Sopinet\GCMBundle\SopinetGCMBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class GCMHelper {
    private $_container;
    function __construct(ContainerInterface $container) {
        $this->_container = $container;
    }

    /**
     * Añade un dispositivo a un usuario en la base de datos
     *
     * @param $device_id
     * @param $user
     * @param $type('Android'|'iOS')
     * @return mixed
     */
    public function addDevice($device_id, $user, $token, $type='Android')
    {
        $em = $this->_container->get("doctrine.orm.entity_manager");
        $reDevice = $em->getRepository('SopinetGCMBundle:Device');
        return $reDevice->addDevice($device_id, $user, $token, $type);
    }

    /**
     * Funcion que construye un mensaje y lo envia en el formato adecuado para el dispositivo receptor

     * @param Msg $msg
     * @param $to
     */
    public function sendMessage(Msg $msg, $to) {
        $mes['type'] = $msg->type;
        $mes['text'] = $msg->text;
        $mes['chatid'] = $msg->chatid;
        $mes['chattype'] = $msg->chattype;
        $mes['msgid'] = $msg->msgid;
        $mes['phone'] = $msg->phone;
        $mes['time'] = $msg->time;
	if (property_exists($msg, 'groupId')) {
        	$mes['groupId']= $msg->groupId;
	}
	if (property_exists($msg, 'username')) {
	        $mes['username']=$msg->username;
	}
        $mes['to'] = $to;

	$config = $this->_container->getParameter('sopinet_gcm.config');
        if ($config['background']) {
            $this->_container->get('old_sound_rabbit_mq.send_gcmbundle_producer')->setContentType('application/json');
            $this->_container->get('old_sound_rabbit_mq.send_gcmbundle_producer')->publish(json_encode($mes));
        } else {
		if ($msg->device==$msg::ANDROID) {
		    $this->sendGCMessage($mes, $to);
		} elseif ($msg->device==$msg::IOS) {
		    $this->sendAPNMessage($mes, $to, $msg->type!='received');
		}
	}
    }

    /**
     * Funcion que envia un mensaje con el servicio GCM de Google
     * @param $mes
     * @param $to
     */
    private function sendGCMessage($mes, $to)
    {
        $message=new AndroidMessage();
        $message->setMessage($mes['text']);
        $message->setData($mes);
        $message->setDeviceIdentifier($to);
        $message->setGCM(true);
        $logger = $this->_container->get('logger');
        $logger->emerg(implode(',', $message->getData()));
        try {
            $this->_container->get('rms_push_notifications')->send($message);
        } catch (InvalidMessageTypeException $e) {
            throw $e;
        }
    }


    /**
     * Funcion que envia un mensaje con el sevricio APN de Apple
     * @param $mes
     * @param $to
     */
    private function sendAPNMessage($mes, $to, $wakeUp=true)
    {
        $message=new iOSMessage();
        try {
            $message->setData($mes);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }
        $alert=[];
        $logger = $this->_container->get('logger');
        $logger->emerg(implode(',', $mes));
        $em = $this->_container->get("doctrine.orm.entity_manager");
        $reDevice = $em->getRepository('ApplicationSopinetUserBundle:User');
        /** @var User $user */
        $user=$reDevice->findOneByPhone($mes['phone']);
        if ($user!=null && $wakeUp) {
            if ($mes['chattype']=='event') {
                $em = $this->_container->get("doctrine.orm.entity_manager");
                $reChat = $em->getRepository('PetyCashAppBundle:Chat');
                $chat = $reChat->find($mes['chatid']);
                $text = $chat->getName().'@'.$user->getUserName();
            } else {
                $text = $user->getUserName();
            }
            $alert['loc-args']=array($text, $mes['text']);
            $alert['loc-key']=$mes['type'];
            $message->setMessage($alert);
            $message->setAPSSound('default');

        }
        $message->setDeviceIdentifier($to);
        $message->setAPSContentAvailable($wakeUp);
        $this->_container->get('rms_push_notifications')->send($message);
    }

    /**
     * @param $text
     * @param $from
     * @param $type
     * @param $time
     * @param $phone
     * @param $toToken
     */
    public function sendNotification($text, $groupId, $type, $time, $phone, $toToken, $deviceType)
    {
        $mes['type'] = $type;
        $mes['text'] = $text;
        $mes['groupId']= $groupId;
        $mes['phone'] = $phone;
        $mes['time'] =$time->getTimestamp()*1000;
        if ($deviceType==Msg::ANDROID) {
            $this->sendGCMessage($mes, $toToken);
        } elseif ($deviceType==Msg::IOS) {
            $this->sendAPNMessage($mes, $toToken);
        }
    }

    /**
     * @param $text
     * @param $chatId
     * @param $from
     * @param $chatType
     * @param $time
     * @param $type
     * @param $msgId
     * @param $deviceType
     * @return Msg
     */
    public function createMsg($text, $chatId, $from, $chatType, $time, $type, $msgId, $deviceType)
    {
        $msg= new Msg();
        $msg->text = $text;
        $msg->from = $from;
        $msg->chatid = $chatId;
        $msg->chattype = $chatType;
        $msg->time = $time;
        $msg->type = $type;
        $msg->msgid = $msgId;
        $msg->device = $deviceType;

        return $msg;
    }
}
?>
