<?php

namespace Pagewiser\Idn\Client;

class Api
{

	/**
	 * @const Fits in given area, dimensions are less than or equal to the required dimensions
	 */
	const FIT = '';

	/**
	 * @const Fills given area, dimensions are greater than or equal to the required dimensions
	 */
	const FILL = 'f';

	/**
	 * @const Fills given area exactly, crop the image
	 */
	const EXACT = 'e';

	/**
	 * @const Fills given area exactly, crop the image
	 */
	const CROP = 'c';

	/**
	 * @const Fit the image and pad to exact size
	 */
	const PAD = 8;

	/**
	 * @const Fit the image and pad to exact size
	 */
	const EXACT_BACKGROUND = 16;

	/**
	 * @const Zoom to the face on the image
	 */
	const FACE = 'a';

	/**
	 * IDN API url
	 *
	 * @var string $apiUrl IDN API url
	 */
	private $apiUrl = '';

	/**
	 * IDN image url
	 *
	 * @var string $imgUrl IDN image url
	 */
	private $imgUrl = '';

	/**
	 * Application ID
	 *
	 * @var string $appId Application ID
	 */
	private $appId;

	/**
	 * Application secret phrase
	 *
	 * @var string $appSecret Application secret phrase
	 */
	private $appSecret;

	/**
	 * Username
	 *
	 * @var string $username Username
	 */
	private $username;

	/**
	 * Password
	 *
	 * @var string $password Password
	 */
	private $password;

	/**
	 * Client UID
	 *
	 * @var string $client Client UID
	 */
	private $client;

	/**
	 * Token
	 *
	 * @var string $token Authorization API token
	 */
	private $token;

	private $browserAgent = 'IdnClient/1.0 (PHP)';

	private $debug = FALSE;

	public $onCurlCall = array();

	public $onCurlFinished = array();

	public $onCurlFailed = array();

	public $onInvalidToken = array();

	private $lastErrorCode;

	private $maxUploadSize;


	public function getToken()
	{
		return $this->token;
	}


	public function setToken($token)
	{
		$this->token = $token;
	}


	/**
	 * Prepare the client API
	 *
	 * @param string $appId Application ID
	 * @param string $appSecret Application passowrd
	 */
	public function __construct($appId, $appSecret)
	{
		$this->appId = $appId;
		$this->appSecret = $appSecret;
	}


	protected function cleanPathString($path)
	{
		return trim(preg_replace('#/+#', '/', $path), '/');
	}


	/**
	 * Login as user
	 *
	 * @param string $username Username
	 * @param string $client Client UID
	 * @param string $password Password
	 *
	 * @return NULL
	 */
	public function loginUser($username, $password, $lazy = FALSE)
	{
		$this->username = $username;
		$this->password = $password;

		$this->token = NULL;

		if ($lazy === FALSE)
		{
			return $this->authenticate();
		}
	}


	public function loginClient($client)
	{
		$this->client = $client;
	}


	public function __call($name, $args)
	{
		if (strpos($name, 'on') === 0)
		{
			if (is_array($this->$name) || $this->$name instanceof \Traversable)
			{
				foreach ($this->$name as $handler)
				{
					call_user_func_array($handler, $args);
				}
				return;
			}
			elseif ($this->$name !== NULL)
			{
				throw new \InvalidArgumentException("Property $class::$$name must be array or NULL, " . gettype($_this->$name) ." given.");
			}
		}

		throw new \BadMethodCallException("Call to undefined method $name().");
	}


	public function setApiUrl($url)
	{
		$this->apiUrl = $url;
	}


	public function setImageUrl($url)
	{
		$this->imageUrl = $url;
	}


	public function enableDebug($bool)
	{
		$this->debug = (bool) $bool;
	}


	private function throwGenericResponseError($response)
	{
		if (array_key_exists('code', $response) && $response['code'] == 403)
		{
			$this->onInvalidToken();
			throw new InvalidTokenException($response['message'], 403);
		}
		if (!empty($result['error']))
		{
			throw new OperationFailException($result['error']);
		}

		throw new OperationFailException('Unknown error');
	}


	private function getCurlFile($filePath)
	{
		$file = realpath($filePath);

		if (empty($file))
		{
			throw new FileNotFoundException('File not found');
		}

		if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION >= 5)
		{
			$file = new \CurlFile($file);
		}
		else
		{
			$file = '@'.$file;
		}

		return $file;
	}


	private function curl($url, $method = 'GET', $params = array())
	{
		$this->onCurlCall(func_get_args());

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/' . $url);
		$header = array();
		if (!empty($this->token))
		{
			$header[] = 'Token: ' . $this->token;
		}
		if (!empty($this->client))
		{
			$header[] = 'AuthClient: ' . $this->client;
		}
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->browserAgent);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

		$result = curl_exec($ch);
		$this->lastErrorCode = curl_errno($ch);
		curl_close ($ch);

		$json = json_decode($result, TRUE);

		$this->onCurlFinished($json);

		if (!is_array($json) || empty($json))
		{
			$this->onCurlFailed($result);
			throw new InvalidResponseException('Received invalid response from server.');
		}

		return $json;
	}


	protected function callApi($url, $method = 'GET', $params = array())
	{
		if (is_null($this->token))
		{
			$this->authenticate();
		}

		return $this->curl($url, $method, $params);
	}


	private function authenticate()
	{
		$authData = array('appId' => $this->appId, 'appSecret' => $this->appSecret);
		$authData['username'] = $this->username;
		$authData['password'] = $this->password;
		$result = $this->curl(
			'auth/login',
			'POST',
			$authData
		);

		if ($result['status'] != 'connected')
		{
			throw new OperationFailException('Wrong username, password or client.', 403);
		}

		$this->token = $result['token'];
	}


	public function createUser($username, $email, $password)
	{
		$result = $this->callApi(
			'user/create',
			'POST',
			array('username' => $username, 'email' => $email, 'password' => $password)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function changeEmail($email)
	{
		$result = $this->callApi(
			'user/change-email',
			'POST',
			array('email' => $email)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function changePassword($oldPassword, $newPassword)
	{
		$result = $this->callApi(
			'user/change-password',
			'POST',
			array('oldPassword' => $oldPassword, 'newPassword' => $newPassword)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function assignUserClient($client)
	{
		$result = $this->callApi(
			'user/assign-client',
			'POST',
			array('client' => $client)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function unassignUserClient($client)
	{
		$result = $this->callApi(
			'user/unassign-client',
			'POST',
			array('client' => $client)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function assignedClients()
	{
		$result = $this->callApi(
			'user/assigned-clients',
			'POST',
			array()
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function createClient($client, $name)
	{
		$result = $this->callApi(
			'client/create',
			'POST',
			array('client' => $client, 'name' => $name)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function getClientStatistics()
	{
		$result = $this->callApi(
			'client/get-statistics',
			'POST',
			array()
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function uploadUrl($directory, $fileName, $fileUrl)
	{
		$result = $this->callApi(
			'file/upload-url',
			'POST',
			array('directory' => $directory, 'file' => $fileName, 'url' => $fileUrl)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function uploadArchive($directory, $file)
	{
		$max = $this->getMaxUploadSize();

		if (!is_file($file))
		{
			throw new FileNotFoundException($file . ' is not valid file', 404);
		}

		if (filesize($file) > $max)
		{
			throw new FileTooLargeException('File is too large', 413);
		}

		try
		{
			$result = $this->callApi(
				'file/upload-archive',
				'POST',
				array('directory' => $directory, 'content' => $this->getCurlFile($file))
			);
		}
		catch (InvalidResponseException $ex)
		{
			// Request entity too large
			if ($this->lastErrorCode = 413)
			{
				throw new FileTooLargeException('File is too large', 413);
			}
			throw $ex;
		}

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function uploadUrlArchive($directory, $url)
	{
		$result = $this->callApi(
			'file/upload-url-archive',
			'POST',
			array('directory' => $directory, 'url' => $url)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function upload($directory, $fileName, $file)
	{
		$result = $this->callApi(
			'file/upload',
			'POST',
			array('directory' => $directory, 'file' => $fileName, 'content' => $this->getCurlFile($file))
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function delete($directory, $fileName)
	{
		$result = $this->callApi(
			'file/delete',
			'DELETE',
			array('directory' => $directory, 'file' => $fileName)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function findDuplicate($file)
	{
		$result = $this->callApi(
			'file/search',
			'POST',
			array('sha1' => sha1_file($file), 'filesize' => filesize($file))
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function createDirectory($directory)
	{
		$result = $this->callApi(
			'directory/create',
			'POST',
			array('directory' => $directory)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function listDirectory($directory)
	{
		$result = $this->callApi(
			'directory/list',
			'POST',
			array('directory' => $directory)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function directorySettings($directory, $settings = NULL)
	{
		$result = $this->callApi(
			is_null($settings) ? 'directory/settings' : 'directory/set-settings',
			'POST',
			array('directory' => $directory, 'settings' => $settings)
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		return $result;
	}


	public function getMaxUploadSize()
	{
		if ($this->maxUploadSize)
		{
			return $this->maxUploadSize;
		}

		$result = $this->callApi(
			'setup/max-upload-size',
			'POST',
			array()
		);

		if ($result['status'] != 'success')
		{
			$this->throwGenericResponseError($result);
		}

		$this->maxUploadSize = $result['max_file_size'];
		return $result['max_file_size'];
	}


	public function clean($path, $fileName)
	{
		$post = array(
			'action' => 'clean',
			'path' => $path,
			'filename' => $fileName,
		);

		return $this->callApi($post);
	}


	public function purge()
	{
		$post = array(
			'action' => 'purge',
		);

		return $this->callApi($post);
	}


	public function image($path, $fileName, $size = 'full', $transformation = self::FIT)
	{
		return $this->imageUrl . $this->cleanPathString('/' . $this->client . '/' . $size . $transformation . '/' . $path . '/' . $fileName);
	}


}

class InvalidResponseException extends \Exception {}

class OperationException extends \Exception {}

class OperationFailException extends OperationException {}

class FileException extends OperationException {}

class FileNotFoundException extends FileException {}

class FileTooLargeException extends FileException {}

class InvalidTokenException extends OperationException {}
