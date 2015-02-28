<?php

namespace Pagewiser\Idn\Client;

class Api
{

	/**
	 * @const Fits in given area, dimensions are less than or equal to the required dimensions
	 */
	const FIT = '';

	/**
	 * @const Shrinks images
	 */
	const SHRINK_ONLY = 'c';

	/**
	 * @const Stretch image and ignore aspect ratio
	 */
	const STRETCH = 's';

	/**
	 * @const Fills given area, dimensions are greater than or equal to the required dimensions
	 */
	const FILL = 'f';

	/**
	 * @const Fills given area exactly, crop the image
	 */
	const EXACT = 'e';

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
	 * Client UID
	 *
	 * @var string $client Client UID
	 */
	private $client;

	/**
	 * Password
	 *
	 * @var string $password Password
	 */
	private $password;

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


	/**
	 * Prepare the client API
	 *
	 * @param string $client Client UID
	 * @param string $password Password
	 */
	public function __construct($username, $password, $client)
	{
		$this->username = $username;
		$this->password = $password;
		$this->client = $client;
	}


	public function __call($name, $args)
	{
		if (strpos($name, 'on') === 0)
		{
			if (is_array($this->$name) || $_this->$name instanceof \Traversable)
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
		if (!empty($result['error']))
		{
			throw new OperationFailException($result['error']);
		}

		throw new OperationFailException('Unknown error');
	}


	private function getCurlFile($filePath)
	{
		$file = realpath($filePath);
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
		$header[] = 'XDEBUG_SESSION: 1';
		if (!empty($this->token))
		{
			$header[] = 'Token: ' . $this->token;
		}
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->browserAgent);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

		$result = curl_exec($ch);
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
		$result = $this->curl(
			'auth/login',
			'POST',
			array('username' => $this->username, 'password' => $this->password, 'client' => $this->client)
		);

		if ($result['status'] != 'connected')
		{
			throw new OperationFailException('Wrong username, password or client.', 403);
		}

		$this->token = $result['token'];
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
		$result = $this->callApi(
			'file/upload-archive',
			'POST',
			array('directory' => $directory, 'content' => $this->getCurlFile($file))
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
		return $this->imageUrl . '/' . $this->client . '/' . $size . $transformation . '/' . $path . '/' . $fileName;
	}


}

class InvalidResponseException extends \Exception {}

class OperationException extends \Exception {}

class OperationFailException extends OperationException {}
