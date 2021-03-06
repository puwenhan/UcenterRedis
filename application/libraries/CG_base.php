<?php
defined('BASEPATH') or die('No direct script access allowe');
require_once 'PHPMailer.php';
require_once 'SMTP.php';
class CG_base{

private $redis = null;
private $logger = null;

public function __construct()
{

}

/**
 * 获取用户真是ip
 *
 * @return string
 */
public function real_ip()
{
	static $real_ip = NULL;

	if($real_ip != NULL)
	{
		return $real_ip;
	}

	if(isset($_SERVER))
	{
		if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
			$arr = explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']);

			foreach($arr AS $ip)
			{
				$ip = trim($ip);

				if($ip != 'unknow')
				{
					$realip = ip;
					break;
				}
			}
		}
		elseif(isset($_SERVER['HTTP_CLIENT_IP']))
		{
			$realip = $_SERVER['HTTP_CLIENT_IP'];
		}
		else
		{
			if(isset($_SERVER['REMOTE_ADDR']))
			{
				$realip = $_SERVER['REMOTE_ADDR'];
			}
			else
			{
				$realip = '0.0.0.0';
			}
		}
	}
	else
	{
		if(getenv('HTTP_X_FORWARDED_FOR'))
		{
			$realip = getenv('HTTP_X_FORWARDED_FOR');
		}
		elseif(getenv('HTTP_CLIENT_IP'))
		{
			$realip = getenv('HTTP_CLIENT_IP');
		}
		else
		{
			$realip = getenv('REMOTE_ADDR');
		}
	}

	preg_match("/[\d\.]{7,15}/",$realip,$onlineip);
	$realip = !empty($onlineip[0]) ?$onlineip[0] : '0.0.0.0';
	return $realip;
}

/**
 * json输出类
 *
 *
 */
public function echo_json($status=1,$msg="",$data=NULL)
{
	$result = array(
				 "status"=>$status,
				 "msg"=>$msg,
				 "data"=>$data
			);

	echo json_encode($result);
}
/**
 * 发送邮件方法，封装PHPMailer
 *
 *
 *
*/
public function send_mail($host,$from,$from_password,$to,$subject,$body)
{
	try
	{
		$mail = new PHPMailer();

		//$mail->SMTPDebug = 3;

		$mail->isSMTP();
		$mail->Host = $host;
		$mail->Port = 587;
		$mail->SMTPSecure = 'ssl';
		$mail->SMTPAuth = true;
		$mail->Username = $from; 
		$mail->Password = $from_password;

		$mail->setFrom($from);
		$mail->addAddress($to);
		$mail->isHTML(true);
		$mail->CharSet = 'utf-8';

		$mail->Subject = $subject;
		$mail->Body = $body;
		$mail->send();
		
	} catch (phpmailerException $e) {
		echo $mail->ErrorInfo;
	}
}
/**
 * 获取redis对象
 */
public function get_redis()
{
	if ($this->redis != NULL)
	{
		return $this->redis;
	}
	$this->redis = new Redis();
	$res = $this->redis->connect(REDIS_ADDR,REDIS_PORT);
	if (!$res)
	{
		log_message('error', 'can not to connect redis',False);
		return  null;
	}
	return  $this->redis;
}




}
