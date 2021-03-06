<?php

/**
 * Jyxo PHP Library
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * https://github.com/jyxo/php/blob/master/license.txt
 */

namespace Jyxo\Webdav;

/**
 * Client for work with WebDav. Uses the http PHP extension.
 *
 * @category Jyxo
 * @package Jyxo\Webdav
 * @copyright Copyright (c) 2005-2011 Jyxo, s.r.o.
 * @license https://github.com/jyxo/php/blob/master/license.txt
 * @author Jaroslav Hanslík
 */
class Client
{
	/** @var string */
	const METHOD_HEAD = 'HEAD';

	/** @var string */
	const METHOD_GET = 'GET';

	/** @var string */
	const METHOD_PUT = 'PUT';

	/** @var string */
	const METHOD_DELETE = 'DELETE';

	/** @var string */
	const METHOD_COPY = 'COPY';

	/** @var string */
	const METHOD_MOVE = 'MOVE';

	/** @var string */
	const METHOD_MKCOL = 'MKCOL';

	/** @var string */
	const METHOD_PROPFIND = 'PROPFIND';

	/** @var integer */
	const STATUS_200_OK = 200;

	/** @var integer */
	const STATUS_201_CREATED = 201;

	/** @var integer */
	const STATUS_204_NO_CONTENT = 204;

	/** @var integer */
	const STATUS_207_MULTI_STATUS = 207;

	/** @var integer */
	const STATUS_403_FORBIDDEN = 403;

	/** @var integer */
	const STATUS_404_NOT_FOUND = 404;

	/** @var integer */
	const STATUS_405_METHOD_NOT_ALLOWED = 405;

	/** @var integer */
	const STATUS_409_CONFLICT = 409;

	/**
	 * Servers list.
	 *
	 * @var array
	 */
	protected $servers = array();

	/**
	 * Connection cURL options.
	 *
	 * @var array
	 */
	protected $curlOptions = array(
		'connecttimeout' => 1,
		'timeout' => 30
	);

	/**
	 * Connection cURL SSL options.
	 *
	 * @var array
	 */
	protected $curlSslOptions = array();

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger = null;

	/**
	 * If parallel request sending is enabled.
	 *
	 * @var boolean
	 */
	protected $parallelSending = true;

	/**
	 * If directories should be created automatically.
	 *
	 * If disabled, commands will throw an error if target directory doesn't exist.
	 *
	 * @var boolean
	 */
	protected $createDirectoriesAutomatically = true;

	/**
	 * Constructor.
	 *
	 * @param array $servers
	 */
	public function __construct(array $servers)
	{
		$this->servers = $servers;
	}

	/**
	 * Sets an cURL option.
	 *
	 * @param string $name Option name
	 * @param mixed $value Option value
	 *
	 * @see \http\Client\Curl
	 */
	public function setCurlOption($name, $value)
	{
		$this->curlOptions[(string) $name] = $value;
	}

	/**
	 * Sets an cURL SSL option.
	 *
	 * @param string $name Option name
	 * @param mixed $value Option value
	 *
	 * @see \http\Client\Curl::$ssl
	 */
	public function setCurlSslOption($name, $value)
	{
		$this->curlSslOptions[(string) $name] = $value;
	}

	/**
	 * Sets a logger.
	 *
	 * @param LoggerInterface $logger Logger
	 * @return \Jyxo\Webdav\Client
	 */
	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;

		return $this;
	}

	/**
	 * Enables/disables parallel request sending.
	 *
	 * @param boolean $parallelSending
	 * @return \Jyxo\Webdav\Client
	 */
	public function setParallelSending($parallelSending)
	{
		$this->parallelSending = (bool) $parallelSending;
		return $this;
	}

	/**
	 * Enables/disables automatic creation of target directories.
	 *
	 * @param boolean $createDirectoriesAutomatically
	 * @return \Jyxo\Webdav\Client
	 */
	public function setCreateDirectoriesAutomatically($createDirectoriesAutomatically)
	{
		$this->createDirectoriesAutomatically = $createDirectoriesAutomatically;
		return $this;
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $path File path
	 * @return boolean
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function exists($path)
	{
		$response = $this->sendRequest($this->getFilePath($path), self::METHOD_HEAD);
		return self::STATUS_200_OK === $response->getResponseCode();
	}

	/**
	 * Returns file contents.
	 *
	 * @param string $path File path
	 * @return string
	 * @throws \Jyxo\Webdav\FileNotExistException If the file does not exist
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function get($path)
	{
		// Asking random server
		$path = $this->getFilePath($path);
		$response = $this->sendRequest($path, self::METHOD_GET);

		if (self::STATUS_200_OK !== $response->getResponseCode()) {
			throw new FileNotExistException(sprintf('File %s does not exist.', $path));
		}

		return $response->getBody()->toString();
	}

	/**
	 * Returns a file property.
	 * If no particular property is set, all properties are returned.
	 *
	 * @param string $path File path
	 * @param string|null $property Property name
	 * @return mixed
	 * @throws \Jyxo\Webdav\FileNotExistException If the file does not exist
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function getProperty($path, $property = null)
	{
		// Asking random server
		$path = $this->getFilePath($path);
		$response = $this->sendRequest($path, self::METHOD_PROPFIND, array('Depth' => '0'));

		if (self::STATUS_207_MULTI_STATUS !== $response->getResponseCode()) {
			throw new FileNotExistException(sprintf('File %s does not exist.', $path));
		}

		// Fetches file properties from the server
		$properties = $this->getProperties($response);

		// Returns the requested property value
		if ($property !== null && isset($properties[$property])) {
			return $properties[$property];
		}

		// Returns all properties
		return $properties;
	}

	/**
	 * Saves data to a remote file.
	 *
	 * @param string $path File path
	 * @param string $data Data
	 * @throws \Jyxo\Webdav\FileNotCreatedException If the file cannot be created
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function put($path, $data)
	{
		$this->processPut($this->getFilePath($path), $data, false);
	}

	/**
	 * Saves file contents to a remote file.
	 *
	 * @param string $path File path
	 * @param string $file Local file path
	 * @throws \Jyxo\Webdav\FileNotCreatedException If the file cannot be created
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function putFile($path, $file)
	{
		$this->processPut($this->getFilePath($path), $file, true);
	}

	/**
	 * Copies a file.
	 * Does not work on Lighttpd.
	 *
	 * @param string $pathFrom Source file path
	 * @param string $pathTo Target file path
	 * @throws \Jyxo\Webdav\FileNotCopiedException If the file cannot be copied
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function copy($pathFrom, $pathTo)
	{
		$pathTo = $this->getFilePath($pathTo);

		// Try creating the directory first
		if ($this->createDirectoriesAutomatically) {
			try {
				$this->mkdir(dirname($pathTo));
			} catch (DirectoryNotCreatedException $e) {
				throw new FileNotCopiedException(sprintf('File %s cannot be copied to %s.', $pathFrom, $pathTo), 0, $e);
			}
		}

		$requests = $this->createAllRequests($this->getFilePath($pathFrom), self::METHOD_COPY);
		foreach ($requests as $server => $request) {
			$request->addHeader('Destination', $server . $pathTo);
		}

		foreach ($this->sendAllRequests($requests) as $response) {
			// 201 means copied
			if (self::STATUS_201_CREATED !== $response->getResponseCode()) {
				throw new FileNotCopiedException(sprintf('File %s cannot be copied to %s.', $pathFrom, $pathTo));
			}
		}
	}

	/**
	 * Renames a file.
	 * Does not work on Lighttpd.
	 *
	 * @param string $pathFrom Original file name
	 * @param string $pathTo New file name
	 * @throws \Jyxo\Webdav\FileNotRenamedException If the file cannot be renamed
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function rename($pathFrom, $pathTo)
	{
		$pathTo = $this->getFilePath($pathTo);

		// Try creating the directory first
		if ($this->createDirectoriesAutomatically) {
			try {
				$this->mkdir(dirname($pathTo));
			} catch (DirectoryNotCreatedException $e) {
				throw new FileNotRenamedException(sprintf('File %s cannot be renamed to %s.', $pathFrom, $pathTo), 0, $e);
			}
		}

		$requests = $this->createAllRequests($this->getFilePath($pathFrom), self::METHOD_MOVE);
		foreach ($requests as $server => $request) {
			$request->addHeader('Destination', $server . $pathTo);
		}

		foreach ($this->sendAllRequests($requests) as $response) {
			switch ($response->getResponseCode()) {
				case self::STATUS_201_CREATED:
				case self::STATUS_204_NO_CONTENT:
					// Means renamed
					break;
				default:
					throw new FileNotRenamedException(sprintf('File %s cannot be renamed to %s.', $pathFrom, $pathTo));
			}
		}
	}

	/**
	 * Deletes a file.
	 * Contains a check preventing from deleting directories.
	 *
	 * @param string $path Directory path
	 * @throws \Jyxo\Webdav\FileNotDeletedException If the file cannot be deleted
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function unlink($path)
	{
		// We do not delete directories
		if ($this->isDir($path)) {
			throw new FileNotDeletedException(sprintf('The path %s is a directory.', $path));
		}

		foreach ($this->sendAllRequests($this->createAllRequests($this->getFilePath($path), self::METHOD_DELETE)) as $response) {
			switch ($response->getResponseCode()) {
				case self::STATUS_204_NO_CONTENT:
					// Means deleted
				case self::STATUS_404_NOT_FOUND:
					break;
				default:
					throw new FileNotDeletedException(sprintf('File %s cannot be deleted.', $path));
			}
		}
	}

	/**
	 * Checks if a directory exists.
	 *
	 * @param string $dir Directory path
	 * @return boolean
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function isDir($dir)
	{
		// Asking random server
		$response = $this->sendRequest($this->getDirPath($dir), self::METHOD_PROPFIND, array('Depth' => '0'));

		// The directory does not exist or server does not support PROPFIND method
		if (self::STATUS_207_MULTI_STATUS !== $response->getResponseCode()) {
			return false;
		}

		// Fetches properties from the server
		$properties = $this->getProperties($response);

		// Checks if it is a directory
		return isset($properties['getcontenttype']) && ('httpd/unix-directory' === $properties['getcontenttype']);
	}

	/**
	 * Creates a directory.
	 *
	 * @param string $dir Directory path
	 * @param boolean $recursive Create directories recursively?
	 * @throws \Jyxo\Webdav\DirectoryNotCreatedException If the directory cannot be created
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function mkdir($dir, $recursive = true)
	{
		// If creating directories recursively, create the parent directory first
		$dir = trim($dir, '/');
		if ($recursive) {
			$dirs = explode('/', $dir);
		} else {
			$dirs = array($dir);
		}

		$path = '';
		foreach ($dirs as $dir) {
			$path .= rtrim($dir);
			$path = $this->getDirPath($path);

			foreach ($this->sendAllRequests($this->createAllRequests($path, self::METHOD_MKCOL)) as $response) {
				switch ($response->getResponseCode()) {
					// The directory was created
					case self::STATUS_201_CREATED:
						break;
					// The directory already exists
					case self::STATUS_405_METHOD_NOT_ALLOWED:
						break;
					// The directory could not be created
					default:
						throw new DirectoryNotCreatedException(sprintf('Directory %s cannot be created.', $path));
				}
			}
		}
	}

	/**
	 * Deletes a directory.
	 *
	 * @param string $dir Directory path
	 * @throws \Jyxo\Webdav\DirectoryNotDeletedException If the directory cannot be deleted
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	public function rmdir($dir)
	{
		foreach ($this->sendAllRequests($this->createAllRequests($this->getDirPath($dir), self::METHOD_DELETE)) as $response) {
			// 204 means deleted
			if (self::STATUS_204_NO_CONTENT !== $response->getResponseCode()) {
				throw new DirectoryNotDeletedException(sprintf('Directory %s cannot be deleted.', $dir));
			}
		}
	}

	/**
	 * Processes a PUT request.
	 *
	 * @param string $path File path
	 * @param string $data Data
	 * @param boolean $isFile Determines if $data is a file name or actual data
	 * @throws \Jyxo\Webdav\DirectoryNotCreatedException If the target directory cannot be created
	 * @throws \Jyxo\Webdav\FileNotCreatedException If the file cannot be created
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	protected function processPut($path, $data, $isFile)
	{
		$requests = $this->createAllRequests($path, self::METHOD_PUT);
		foreach ($requests as $request) {
			if ($isFile) {
				$body = new \http\Message\Body(fopen($data, 'r'));
				$request->setBody($body);
			} else {
				$body = new \http\Message\Body();
				$body->append($data);
				$request->setBody($body);
			}
		}

		$success = true;
		foreach ($this->sendAllRequests($requests) as $response) {
			switch ($response->getResponseCode()) {
				// Saved
				case self::STATUS_200_OK:
				case self::STATUS_201_CREATED:
					break;
				// An existing file was modified
				case self::STATUS_204_NO_CONTENT:
					break;
				// The directory might not exist
				case self::STATUS_403_FORBIDDEN:
				case self::STATUS_404_NOT_FOUND:
				case self::STATUS_409_CONFLICT:
					$success = false;
					break;
				// Could not save
				default:
					throw new \Jyxo\Webdav\FileNotCreatedException(sprintf('File %s cannot be created.', $path));
			}
		}

		// Saved
		if ($success) {
			return;
		}

		// Not saved, try creating the directory first
		if ($this->createDirectoriesAutomatically) {
			try {
				$this->mkdir(dirname($path));
			} catch (DirectoryNotCreatedException $e) {
				throw new \Jyxo\Webdav\FileNotCreatedException(sprintf('File %s cannot be created.', $path), 0, $e);
			}
		}

		// Try again
		foreach ($this->sendAllRequests($requests) as $response) {
			// 201 means saved
			if (self::STATUS_201_CREATED !== $response->getResponseCode()) {
				throw new \Jyxo\Webdav\FileNotCreatedException(sprintf('File %s cannot be created.', $path));
			}
		}
	}

	/**
	 * Sends requests to all servers.
	 *
	 * @param \http\Client\Request[] $requests Request list
	 * @return \http\Client\Response[]
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	protected function sendAllRequests(array $requests)
	{
		try {
			$responses = array();

			if ($this->parallelSending) {
				// Send parallel requests

				$client = new \http\Client();

				// Attach requests
				foreach ($requests as $request) {
					$client->enqueue($request);
				}

				// Send
				$client->send();

				foreach ($requests as $server => $request) {
					$response = $client->getResponse($request);
					$responses[$server] = $response;

					// Log
					if ($this->logger !== null) {
						$this->logger->log(sprintf("%s %d %s", $request->getRequestMethod(), $response->getResponseCode(), $request->getRequestUrl()));
					}
				}

			} else {

				// Send by separate requests
				$client = new \http\Client();
				foreach ($requests as $server => $request) {
					$client->reset();
					$client->enqueue($request);
					$client->send();

					$response = $client->getResponse();
					$responses[$server] = $response;

					// Log
					if ($this->logger !== null) {
						$this->logger->log(sprintf("%s %d %s", $request->getRequestMethod(), $response->getResponseCode(), $request->getRequestUrl()));
					}
				}
			}

			return $responses;
		} catch (\http\Exception $e) {
			throw new Exception($e->getMessage(), 0, $e);
		}
	}

	/**
	 * Sends a request.
	 *
	 * @param string $path Request path
	 * @param integer $method Request method
	 * @param array $headers Array of headers
	 * @return \http\Client\Response
	 * @throws \Jyxo\Webdav\Exception On error
	 */
	protected function sendRequest($path, $method, array $headers = array())
	{
		try {
			// Send request to a random server
			$request = $this->createRequest($this->getRandomServer(), $path, $method, $headers);

			$client = new \http\Client();
			$client->enqueue($request);
			$client->send();

			$response = $client->getResponse();

			if (null !== $this->logger) {
				$this->logger->log(sprintf("%s %d %s", $request->getRequestMethod(), $response->getResponseCode(), $request->getRequestUrl()));
			}

			return $response;
		} catch (\http\Exception $e) {
			throw new Exception($e->getMessage(), 0, $e);
		}
	}

	/**
	 * Creates a list of requests; one for each server.
	 *
	 * @param string $path Request path
	 * @param integer $method Request method
	 * @param array $headers Array of headers
	 * @return \http\Client\Request[]
	 */
	protected function createAllRequests($path, $method, array $headers = array())
	{
		$requests = array();
		foreach ($this->servers as $server) {
			$requests[$server] = $this->createRequest($server, $path, $method, $headers);
		}
		return $requests;
	}

	/**
	 * Creates a request.
	 *
	 * @param string $server Server
	 * @param string $path Path
	 * @param integer $method Request method
	 * @param array $headers Array of headers
	 * @return \http\Client\Request
	 */
	protected function createRequest($server, $path, $method, array $headers = array())
	{
		$request = new \http\Client\Request($method, $server . $path);
		$request->setOptions($this->curlOptions);
		$request->setSslOptions($this->curlSslOptions);
		$request->setHeaders($headers + array('Expect' => ''));
		return $request;
	}

	/**
	 * Creates a file path without the trailing slash.
	 *
	 * @param string $path File path
	 * @return string
	 */
	protected function getFilePath($path)
	{
		return '/' . trim($path, '/');
	}

	/**
	 * Creates a directory path with the trailing slash.
	 *
	 * @param string $path Directory path
	 * @return string
	 */
	protected function getDirPath($path)
	{
		return '/' . trim($path, '/') . '/';
	}

	/**
	 * Fetches properties from the response.
	 *
	 * @param \http\Client\Response $response Response
	 * @return array
	 */
	protected function getProperties(\http\Client\Response $response)
	{
		// Process the XML with properties
		$properties = array();
		$reader = new \Jyxo\XmlReader();
		$reader->XML($response->getBody()->toString());

		// Ignore warnings
		while (@$reader->read()) {
			if ((\XMLReader::ELEMENT === $reader->nodeType) && ('D:prop' === $reader->name)) {
				while (@$reader->read()) {
					// Element must not be empty and has to look something like <lp1:getcontentlength>13744</lp1:getcontentlength>
					if ((\XMLReader::ELEMENT === $reader->nodeType) && (!$reader->isEmptyElement)) {
						if (preg_match('~^lp\\d+:(.+)$~', $reader->name, $matches)) {
							// Apache
							$properties[$matches[1]] = $reader->getTextValue();
						} elseif (preg_match('~^D:(.+)$~', $reader->name, $matches)) {
							// Lighttpd
							$properties[$matches[1]] = $reader->getTextValue();
						}
					} elseif ((\XMLReader::END_ELEMENT === $reader->nodeType) && ('D:prop' === $reader->name)) {
						break;
					}
				}
			}
		}

		return $properties;
	}

	/**
	 * @return string
	 */
	protected function getRandomServer()
	{
		return $this->servers[array_rand($this->servers)];
	}
}
