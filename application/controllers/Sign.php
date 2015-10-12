<?php
defined('BASEPATH') or die('No direct script access allowed');

class Sign extends CI_Controller{
	public function __construct()
	{
		parent::__construct();
		$this->load->model('sign_model');
		$this->load->helper('url_helper');
		$this->load->library('LB_base_lib');
	}

	

	public function index_redis()
	{
		// 检查是否已经登陆
		$is_signin = $this->check_signin_by_redis();
		
		if ($is_signin) 
		{
		    header('location:/portal/index_redis');
            return;
		}

		$this->load->view('sign/sign_redis');
	}

	public function index_modify_password()
	{
		$this->load->view('sign/modify_password');
	}
	//注册接口
	public function signup()
	{
		$username  = $this->input->post('username');
		$email     = $this->input->post('email');
		$password  = $this->input->post('password');
		$password2 = $this->input->post('password2');

		//验证数据是否合法
		if (!$this->check_username_formate($username))
		{
			$this->lb_base_lib->echo_json_result(-1,'username is illegal');
		}
		if (!$this->check_email_formate($email)) 
		{
			$this->lb_base_lib->echo_json_result(-1,'email is illegal');
		}
		if (!$this->check_password_formate($password)) 
		{
			$this->lb_base_lib->echo_json_result(-1,'password is illegal');
		}

		//检查用户名是否已经注册
		if ($this->sign_model->check_username_exists($username))
		{
			$this->lb_base_lib->echo_json_result(-1,"username is exists");
		}
		//检查邮箱是否已经注册
		if ($this->sign_model->check_email_exists($email))
		{
			$this->lb_base_lib->echo_json_result(-1,"email is exists");
		}
			
		//输入信息过滤
		$username = addslashes(trim($username));
		$email    = addslashes(trim($email));
		$password = addslashes(trim($password));
		$regip    = $this->lb_base_lib->real_ip();
		//用户信息写入数据库
		$result = $this->sign_model->add_user($username,$email,$password,$regip);
		$this->lb_base_lib->echo_json_result($result,"success");

	}
	/*
	以下部分内容在Ucenter_redis中实现；

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
	public function signin_redis()
	{
		$login_username = addslashes(trim($this->input->post('login_username')));
		$login_passwd   = addslashes(trim($this->input->post('login_passwd')));
		//根据用户名获取数据库中用户信息
		$user = $this->sign_model->get_user_by_username($login_username);
		//用户名不存在
		if(empty($user))
		{
	      $this->lb_base_lib->echo_json_result(-1,"username dose not exists");

		} 

			
		$login_passwd = md5(md5($login_passwd).$user->salt);
		if ($login_passwd == $user->password)//登录成功
		{
			//更新最后登录ip
			$last_signin_ip = $this->lb_base_lib->real_ip();
			$this->sign_model->update_signin($last_signin_ip,time(),$user->username);
			
			//redis

		    $this->lb_base_lib->echo_json_result(1,"signin success");
		}
		else//登陆失败
		{
		    $this->lb_base_lib->echo_json_result(-1,"username or password was wrong");
		}
	}

	//登出
	public function signout_redis()
	{
		//redis
	}

	//修改密码
	public function modify_password()
	{
		//未实现，仅实现发送邮件功能，具体实现在Ucenter_redis中
		$host = 'smtp.163.com';
		$from = 'xiatianliubin@163.com';
		$from_password = '*';
		$to = 'codergma@163.com';
		$subject = '修改密码';
		$body = "点击下面链接修改密码<br/><a href='http://localhost:8084/sign/index_modify_password'>localhost:8084/sign/index_modify_password</a>";
		$this->lb_base_lib->send_mail($host,$from,$from_password,$to,$subject,$body);
	
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

	
	//第二种方法：根据session判断用户是否在线
	public function check_signin_by_redis()
	{
		//redis
		
	}


}