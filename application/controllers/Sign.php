<?php
defined('BASEPATH') or die('No direct script access allowed');

class Sign extends CI_Controller{
	private $redis = null;
	public function __construct()
	{
		parent::__construct();
		$this->load->model('sign_model');
		$this->load->helper('url_helper');
		$this->load->library('CG_base');
		$this->load->library('email');
		$this->redis = new Redis();
		$this->redis->connect(REDIS_ADDR,REDIS_PORT);
	}

	public function index()
	{
		// 检查是否已经登陆
		$is_signin = $this->check_signin();
		
		if ($is_signin) 
		{
		    header('location:/portal/index');
            return;
		}

		$this->load->view('sign/sign_redis');
	}
	public function index_modify_passwd($flag)
	{
		if(empty($flag) || !$this->redis->exists($flag))
		{
			show_404();
			return ;
		}

        $this->load->view('sign/modify_password.php');
	}

	//注册接口
	public function signup()
	{


		$username  = $this->input->post('username');
		$email     = $this->input->post('email');
		$password  = $this->input->post('password');
		$password2 = $this->input->post('password2');

        check_signup($username,$email,$password);
		
		//输入信息过滤
		$username = addslashes(trim($username));
		$email    = addslashes(trim($email));
		$password = addslashes(trim($password));
		$regip    = $this->cg_base->real_ip();
		//用户信息写入数据库
		$result = $this->sign_model->add_user($username,$email,$password,$regip);
		$this->cg_base->echo_json($result,"success");
	}

    /**
	*用户名和口令：
			正则表达式限制用户输入口令；
			密码加密保存 md5(md5(passwd+salt))；
			允许浏览器保存口令；
			口令在网上传输协议http;
	*用户登陆状态：因为http是无状态的协议，每次请求都是独立的， 所以这个协议无
			法记录用户访问状态，在多个页面跳转中如何知道用户登陆状态呢?
			那就要在每个页面都要对用户身份进行认证。
			我将使用三种方法实现用户登录状态验证(在下才疏学浅望指正):
			(1)在cookie中保存用户名和密码，每次页面跳转的时候进行密码验证，正确则登录状态；
			   这个方法显然太挫了，每次都要与数据库交互显然严重影响性能，那么你可能想，直接
			   在cookie中保存登录状态true|false，这更挫，太不安全了，不过你可以在session
			   中保存，这就是第二种实现方法。
			(2)在session中保存登陆状态true|false|更多信息,在页面跳转的时候获取session即可，
			   因为session是保存在服务器中的， 所以相对安全一些,但是由于默认session是保存在
			   文件中的，所以当用户数量上来之后，会产生大量小文件，影响系统性能，当然你可以更
			   换文件系统，或者指定其他存储方式，比如数据库。
			(3)解决上面所说的性能问题，这时候就要用到Inmemory的key-value型数据库了，以前memcache
				用的比较广泛，现在redis等越来越火了。
	*使用cookie的一些原则：
		(1)cookie中保存用户名，登录序列，登录token;
				用户名：明文；
				登录序列：md5散列过的随机数,仅当强制用户输入口令时更新;
				登陆token：md5散列过的随机数，仅一个登陆session内有效，新的session会更新他。
		(2)上述三个东西会存放在服务器上，服务器会验证客户端cookie与服务器是否一致；
		(3)这样设计的效果
				(a)登录token是单实例，一个用户只能有一个登录实例；
				(b)登录序列用来做盗用行为检测
	*找回密码功能
		(1)不要使用安全问答
		(2)通过邮件自行重置。当用户申请找回密码时，系统生成一个md5唯一的随机字串
		　放在数据库中，然后设置上时限，给用户发一个邮件，这个链接中包含那个md5
		　，用户通过点击那个链接来自己重置新的口令。
		(3)更好的做法多重认证。
	*口令探测防守
		(1)验证码
		(2)用户口令失败次数,并且增加尝试的时间成本
		(3)系统全局防守,比如系统每天5000次口令错误，就认为遭遇了攻击，
	  	然后增加所有用户输错口令的时间成本。
		(4)使用第三方的OAuth和OpenID
	*/

	//登录接口
	public function signin()
	{

		$login_username = addslashes(trim($this->input->post('login_username')));
		$login_passwd   = addslashes(trim($this->input->post('login_passwd')));
		$user = $this->sign_model->get_user_by_username($login_username);

		//检查用户名是否存在
		if(empty($user))
		{
	      $this->cg_base->echo_json(-1,"username dose not exists");
		} 
		//检查登录错误次数
		$fail_num = $this->get_fail_count($login_username);
		if ($fail_num >= MAX_ERR_NUM)
		{
			$this->set_fail_count($login_username);
			$ttl = $this->get_ttl($login_username);
			$ttl = ceil($ttl/60);
			$this->cg_base->echo_json(-1,"Fail too many times, please login again after {$ttl} minutes "); 
		}
	    //验证密码		
		$login_passwd = md5(md5($login_passwd).$user->salt);
		if ($login_passwd == $user->password)
		{
			$last_signin_ip = $this->cg_base->real_ip();
			$this->sign_model->update_signin($last_signin_ip,time(),$user->username);
			
			$key = md5(mt_rand());
			$test = setcookie('uid',$key,time()+EXPIRE,'/','');
			$_COOKIE['uid'] = $key;
			$value = array(
				"uid"=>$user->uid,
				"username"=>$user->username,
				"email"=>$user->email
				);
			$test = $this->redis->setex($key,EXPIRE,json_encode($value));

		    $this->cg_base->echo_json(1,"signin success");
		}
		else
		{
			$this->set_fail_count($login_username);
			$count = 5 - $this->get_fail_count($login_username);
		    $this->cg_base->echo_json(-1," 还剩{$count}次机会");
		}
	}

	//登出
	public function signout()
	{
		if (isset($_COOKIE['uid']))
		{
			$this->redis->del($_COOKIE['uid']);
			$this->cg_base->echo_json(1,"signout success");
		}
	}

	//发送修改密码链接
	public function modify_password()
	{
		$email = $this->input->post('email');
        $flag = md5(mt_rand());
        $this->redis->setex($flag,PASSWD_EXPIRE,$email);
        $url = "http://localhost:8084/sign/index_modify_passwd/".$flag;
        $msg = "点击一下链接修改密码<br/>"."<a href='{$url}'>".$url."</a>";
        $subject = '修改密码';

		$config['protocol'] = 'smtp';  
        $config['smtp_host'] = 'smtp.163.com';  
        $config['smtp_user'] = 'xiatianliubin@163.com';  
        $config['smtp_pass'] = '****';  
        $config['smtp_port'] = '25';  
        $config['charset'] = 'utf-8';  
        $config['wordwrap'] = TRUE;  
        $config['mailtype'] = 'html';  
        $this->email->initialize($config); 
		$this->email->from('xiatianliubin@163.com','xiatianliubin');
		$this->email->to($email,'fortesab');
		$this->email->subject($subject);
		$this->email->message($msg);
		$this->email->send();
	}
	//重置密码
	public function reset_passwd()
	{
		$passwd = $this->input->post_get('password');
		if (!$this->check_password_formate($passwd))
		{
			$this->cg_base->echo_json(-1,'fail');
			return;
		}
		
		$request_url = $this->input->server('REQUEST_URL');
		$flag =  substr($request_url, -32);
		$email = $this->redis->get($flag);

		if (empty($email))
		{
			$this->cg_base->echo_json(-1,'fail');
			return; 
		}
		
		if($this->sign_model->reset_passwd($email,$passwd))
		{
			$this->cg_base->echo_json(1,'success');
			return;
		}
	}

	//验证注册数据是否合法
	protected function check_signup($username,$email,$password)
	{
		if (!$this->check_username_formate($username))
		{
			$this->cg_base->echo_json(-1,'username is illegal');
			exit;
		}
		if (!$this->check_email_formate($email)) 
		{
			$this->cg_base->echo_json(-1,'email is illegal');
			exit;
		}
		if (!$this->check_password_formate($password)) 
		{
			$this->cg_base->echo_json(-1,'password is illegal');
			exit;
		}
		//检查用户名是否已经注册
		if ($this->sign_model->check_username_exists($username))
		{
			$this->cg_base->echo_json(-1,"username is exists");
			exit;
		}
		//检查邮箱是否已经注册
		if ($this->sign_model->check_email_exists($email))
		{
			$this->cg_base->echo_json(-1,"email is exists");
			exit;
		}
	}
	//检查用户名格式
	public function check_username_formate($username)
	{
		return preg_match('/^[0-9A-Za-z_]{6,32}$/', $username);
	}
	//检查邮件格式
	public function check_email_formate($email)
	{
    return preg_match('/^([0-9A-Za-z]+)([0-9a-zA-Z_-]*)@([0-9A-Za-z]+).([A-Za-z]+)$/',$email);

	}
	//检查密码格式
	public function check_password_formate($password)
	{
    return preg_match('/[a-zA-Z]+/', $password) && preg_match('/[0-9]+/',$password) && preg_match('/[\s\S]{6,16}$/',$password);
	}

	
	//根据redis判断用户是否在线
	public function check_signin()
	{
		//redis
		if (isset($_COOKIE['uid'])) {
			return $this->redis->exists($_COOKIE['uid']);
		}else{
			return false;
		}
	}
	//登录失败，错误数加一,expire为30分钟
	protected function set_fail_count($username)
	{
		$key = md5($username.'sign_fail');

		if ($this->redis->exists($key))
		{
			$this->redis->incr($key);
		}else
		{
			$this->redis->setex($key,EXPIRE,1);
		}
	}
	//当前登陆错误次数　
	protected function get_fail_count($username)
	{
		$key = md5($username.'sign_fail');
		if ($this->redis->exists($key))
		{
			return $this->redis->get($key);
		}else
		{
			return 0;
		}
	}
	//redis中记录错误次数的键值对过期时间
	protected function get_ttl($username)
	{
		$key = md5($username.'sign_fail');
		if ($this->redis->exists($key))
		{
			return $this->redis->ttl($key);
		}else
		{
			return 0;
		}

	}



}