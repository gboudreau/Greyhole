<?php

define("STATIC_DIR", __DIR__); // @TODO

define("HOST", "0.0.0.0");
define("PORT", 8012);

if (array_search('--debug', $argv) !== FALSE) {
    define(DEBUG, TRUE);
} else {
    define(DEBUG, FALSE);
}

// All static files that can be served by this server:
exec('find ' . escapeshellarg(STATIC_DIR) . ' -type f', $all_static_files);

if (DEBUG) error_reporting(E_ALL);

// Just to quiet PHP warnings
date_default_timezone_set("America/Montreal");

function didReceiveRequest($msg, $thread) {
    global $_REQUEST, $_GET, $_POST, $all_static_files;
	$request = parseRequest($msg);
	if (!empty($request->url)) {
	    // Try to find a corresponding static file first
	    if (trim($request->url, '/') == '') {
	        $request->url = '/index.php';
	    }
	    $static_file = STATIC_DIR . $request->url;
	    if (file_exists($static_file) && !is_dir($static_file) && array_search($static_file, $all_static_files) !== FALSE) {
	        $content_type = getContentType($request->url);
	        // .php.xxx will be output with the content type normally associated with .xxx, but it will be executed as PHP
	        if (strpos($static_file, '.php') == strlen($static_file)-4 || strpos($static_file, '.php.') == strlen($static_file)-8) {
	            ob_start();
	            include($static_file);
	            $response = ob_get_contents();
	            ob_end_clean();
            	$thread->sendResponse(200, $response, $request, $content_type);
	        } else {
            	$thread->sendResponse(200, file_get_contents($static_file), $request, $content_type);
	        }
	    } else {
	        switch (trim($request->url, '/')) {
	            case 'save_samba':
	                $response = 'Saving samba config...';
                	$thread->sendResponse(200, $response, $request);
	                break;
	            default:
            	    $thread->sendResponse(404, "404 Not Found", $request);
                    break;
	        }
	    }
	}
}

function logRequest($request, $reponse_code, $response_size) {
    if (DEBUG) echo '- - - [' . date('d/M/Y:H:i:s O') . "] \"$request->method $request->url HTTP/$request->protocol_version" . (!empty($request->parameters) ? '?' . $request->parameters : '') . "\" $reponse_code $response_size \"-\" \"{$request->headers['User-Agent']}\"" . PHP_EOL;
}

function getContentType($url) {
    if (strpos($url, '.png') == strlen($url)-4) {
        return 'image/png';
    }
    if (strpos($url, '.gif') == strlen($url)-4) {
        return 'image/gif';
    }
    if (strpos($url, '.jpg') == strlen($url)-4) {
        return 'image/jpg';
    }
    if (strpos($url, '.css') == strlen($url)-4) {
        return 'text/css';
    }
    if (strpos($url, '.js') == strlen($url)-3) {
        return 'text/javascript';
    }
    return 'text/html; charset=utf-8';
}

$_REQUEST = array();
function parseRequest($msg) {
    $request = (object) array();
    $lines = explode("\n", $msg);
    
    $line = array_shift($lines);
    $request->parameters = '';
    if (preg_match('@([A-Z]+) (.+)\?(.+) HTTP/(1[01\.]+)@', $line, $regs)) {
        $request->method = $regs[1];
        $request->url = $regs[2];
        $request->parameters = $regs[3];
        $request->protocol_version = $regs[4];
    } else if (preg_match('@([A-Z]+) (.+) HTTP/(1[01\.]+)@', $line, $regs)) {
        $request->method = $regs[1];
        $request->url = $regs[2];
        $request->protocol_version = $regs[3];
    }
    $request->headers = array();
    while (!empty($lines)) {
        $line = array_shift($lines);
        if (trim($line) == '') {
            break;
        }
        $header = explode(':', $line);
        $request->headers[trim($header[0])] = trim($header[1]);
    }
    $request->data = implode("\n", $lines);
    if (!empty($request->parameters)) {
        global $_REQUEST, $_GET;
        foreach (explode('&', $request->parameters) as $param) {
            list($name, $value) = explode('=', $param);
            $_REQUEST[$name] = urldecode($value);
            $_GET[$name] = urldecode($value);
        }
    }
    if (!empty($request->data)) {
        global $_REQUEST, $_POST;
        foreach (explode('&', $request->data) as $param) {
            list($name, $value) = explode('=', $param);
            $_REQUEST[$name] = urldecode($value);
            $_POST[$name] = urldecode($value);
        }
    }
    return $request;
}

// Create the server
try {
    $server = new Socket();
} catch (SocketException $e) {
    die("Can't start server, " . $e->getMessage() . PHP_EOL);
}

// Start the listen loop
try {
    $server->listen(HOST, PORT);
} catch (SocketException $e) {
    die("Can't listen, " . $e->getMessage() . PHP_EOL);
}

class Socket {
	/**
	 * Domain type to use when creating the socket
	 * @var int
	 */
	public $domain = AF_INET;
	/**
	 * The stream type to use when creating the socket
	 * @var int
	 */
	public $type = SOCK_STREAM;
	/**
	 * The protocol to use when creating the socket
	 * @var int
	 */
	public $protocol = SOL_TCP;
	/**
	 * Stores a reference to the created socket
	 * @var Resource
	 */
	private $link = null;
	/**
	 * Array of connected children
	 * @var array
	 */
	private $threads = array();
	/**
	 * Bool which determines if the socket is listening or not
	 * @var boolean
	 */
	private $listening = false;

	/**
	 * Creates a new Socket.
	 *
	 * @param array $args
	 * @param int $args[domain] AF_INET|AF_INET6|AF_UNIX
	 * @param int $args[type] SOCK_STREAM|SOCK_DGRAM|SOCK_SEQPACKET|SOCK_RAW|SOCK_UDM
	 * @param int $args[protocol] SOL_TCP|SOL_UDP
	 * @return Socket
	 */
	public function __construct(array $args = null) {
		// Default socket info
		$defaults = array(
			"domain" => AF_INET,
			"type" => SOCK_STREAM,
			"protocol" => SOL_TCP
		);
		if ($args == null) {
			$args = array();
		}
		// Merge $args in to $defaults
		$args = array_merge($defaults, $args);

		// Store these values for later, just in case
		$this->domain = $args['domain'];
		$this->type = $args['type'];
		$this->protocol = $args['protocol'];

		if (($this->link = socket_create($this->domain, $this->type, $this->protocol)) === false) {
			throw new SocketException("Unable to create Socket. PHP said, " . $this->getLastError(), socket_last_error());
		}
	}

	/**
	 * At destruct, close the socket
	 */
	public function __destruct() {
		@$this->close();
	}

	/**
	 * After calling this method, the Socket will start to listen on the port
	 * specified or the default port.
	 *
	 * @see Socket::$port
	 * @param string $host
	 * @param int $port
	 */
	public function listen($host, $port) {
		if ($this->link === null) {
			throw new SocketException("No socket available, cannot listen");
		}

		socket_set_nonblock($this->link);

		// Bind to the host/port
		if (!socket_bind($this->link, $host, $port)) {
			throw new SocketException("Cannot bind to $host:$port. PHP said, " . $this->getLastError($this->link));
		}
		// Try to listen
		if (!socket_listen($this->link)) {
			throw new SocketException("Cannot listen on $host:$port. PHP said, " . $this->getLastError($this->link));
		}

		if (DEBUG) echo "Listening on port $port ..." . PHP_EOL;
		
	    exec('ifconfig | grep inet | grep -v inet6 | awk \'{print $2}\' | grep -v \'127.0.0.1\'', $ips);
		echo "Open any of the following URLs in your browser to access Greyhole-UI:" . PHP_EOL;
		foreach ($ips as $ip) {
		    $ip = preg_replace('/[^0-9\.]/', '', $ip);
		    echo "  http://$ip:$port" . PHP_EOL;
		}
		echo PHP_EOL;
		
		$this->listening = true;

		// Start main loop
		while ($this->listening) {
			// Accept new connections
			if (($thread = @socket_accept($this->link)) !== false) {
				$child = new ChildSocket($thread);
				array_push($this->threads, $child);
				if (DEBUG) echo "Accepted child, " . $child->getInfo() . PHP_EOL;
			}

			// Loop through children, listen for read
			foreach ($this->threads as $index => $child) {
				try {
					$msg = $child->read();
				} catch (SocketCloseException $e) {

					// Child socket closed unexpectedly, remove from active
					// threads
					echo "ERROR: Terminating child at $index" . PHP_EOL;
					echo $e->getMessage() . PHP_EOL;
					$this->closeChild($index);
					unset($child);
					continue;
				}
				$msg = trim($msg);

				if ($msg !== false && !empty($msg)) {
				    didReceiveRequest($msg, $child);
				    $this->closeChild($index);
					unset($child);
					continue;
				}
			}
		}
	}
	/**
	 * Closes a child socket at $index
	 *
	 * @param integer $index
	 * @return void
	 */
	public function closeChild($index) {
		if(isset($this->threads[$index])) {
		    usleep(50000);
			$this->threads[$index]->close();
			unset($this->threads[$index]);
		}
	}
	/**
	 * Closes the listening socket
	 *
	 * @return void
	 */
	public function close() {
		$this->listening = false;

		// @see http://www.php.net/manual/en/function.socket-close.php#66810
		$socketOptions = array('l_onoff' => 1, 'l_linger' => 0);
		socket_set_option($this->link, SOL_SOCKET, SO_LINGER, $socketOptions);

		socket_close($this->link);
	}

	/**
	 * Sends a message to a child. Set child as "all" to send to all children.
	 *
	 * @param string $message
	 * @return boolean
	 */
	public function send($child, $message) {
		if ($this->link === null) {
			throw new SocketException("Socket not connected");
		}
		if (empty($message)) {
			return;
		}
		try {
			$child->write($message . PHP_EOL);
		} catch (SocketException $e) {
			return false;
		}
		return true;
	}

	/**
	 * Terminates all active child connections
	 *
	 * @return void;
	 */
	public function killAll() {
		foreach ($this->threads as $child) {
			$child->close();
		}
		$this->listening = false;
		$this->close();
	}

	/**
	 * Returns the last error on the socket specified. If no socket is specified
	 * the last error that occured is returned.
	 *
	 * @param Resource $socket
	 * @return string
	 */
	public function getLastError($socket = null) {
		if (empty($socket)) {
			return socket_strerror(socket_last_error());
		} else {
			return socket_strerror(socket_last_error($socket));
		}
	}

}

class SocketException extends Exception {

	public function __construct($message = "", $code = 0) {
		parent::__construct($message, $code);
	}

}

class SocketCloseException extends SocketException {

	public function __construct($message = "", $code = 0) {
		parent::__construct($message, $code);
	}

}

class ChildSocket {

	/**
	 * Stores a reference to the created socket
	 * @var Resource
	 */
	private $link = null;

	/**
	 * Connection reset by peer error number
	 * @var int
	 */
	const PEER_RESET = 104;

	public function __construct($thread = null) {
		if ($thread === null || !is_resource($thread)) {
			throw new SocketException("No socket available, cannot create Child");
		}
		$this->link = $thread;
	}

	/**
	 * Sends a message to the socket
	 *
	 * @param string $message
	 * @return boolean
	 */
	public function write($message) {
		if ($this->link == null) {
			throw new SocketException("Socket not connected");
		}
		if (empty($message) && $message !== chr(0)) {
			return false;
		}

		$wrote = 0;
		while ($wrote < strlen($message)) {
            $message = substr($message, $wrote);
    		$wrote = @socket_write($this->link, $message);
    		if ($wrote === FALSE) $wrote = 0;
    		#if (DEBUG) echo "Sent $wrote bytes..." . PHP_EOL;
		}
		if ($wrote === FALSE) {
			throw new SocketException("Failed to write to socket.\n PHP said: " . $this->getLastError());
		}
		
		return (strlen($message) == $wrote);
	}

	/**
	 * Sends a response to the client
	 *
	 * @param integer $statusCode
	 * @param string $response
	 * @return void
	 */
	public function sendResponse($statusCode, $response, $request, $content_type=null) {
		switch($statusCode) {
			case 200:
				$status = "OK";
			    break;
			case 404:
				$status = "Not Found";
			    break;
			default:
				$status = 'Server Error';
			    break;
		}
		$buf[] = "HTTP/1.1 $statusCode $status";
		$buf[] = "Server: Greyhole-UI/1.0";
		$buf[] = "Content-length: " . strlen($response);
		if ($content_type != null) {
    		$buf[] = "Content-type: $content_type";
		}
		$buf[] = "";
	    $buf[] = $response;

		$buf = implode(PHP_EOL, $buf);
		
		#if (DEBUG && $content_type == 'text/html') echo "Response: " . PHP_EOL . '  ' . str_replace("\n", "\n  ", $buf) . PHP_EOL;

		try {
			$this->write($buf);
			logRequest($request, $statusCode, strlen($response));
		} catch (SocketException $e) {
			throw $e;
		}
		
	}

	/**
	 * Reads from the Socket, returns false if there is nothing to read
	 *
	 * @param int $bufferSize
	 * @return mixed
	 */
	public function read($bufferSize = 2048) {
		if ($this->link == null) {
			throw new SocketException("Socket not connected");
		}
		if (empty($bufferSize)) {
			$bufferSize = 2048;
		}

		$buffer = "";
		$in = "";

		if (($bytes = @socket_recv($this->link, $in, $bufferSize, MSG_DONTWAIT)) > 0) {
			if (!empty($in)) {
				$buffer .= $in;
			}
		} else if ($bytes === false) {
			// Some errors are recoverable
			switch ($this->getLastErrorNo()) {
				// Connection Reset
				case SOCKET_ECONNRESET:
					throw new SocketCloseException("Connection reset unexpectedly. PHP Said " . $this->getLastError(), $this->getLastErrorNo());
					break;
				// Connection timed out
				case SOCKET_ETIMEDOUT:
					throw new SocketCloseException("Connection timed out. PHP Said " . $this->getLastError(), $this->getLastErrorNo());
					break;
				default:
					$buffer = "";
					break;
			}
		}

		return $buffer;
	}

	/**
	 * At destruct, close the socket
	 */
	public function __destruct() {
		@$this->close();
	}

	/**
	 * Closes the socket
	 *
	 * @return void
	 */
	public function close() {
		// @see http://www.php.net/manual/en/function.socket-close.php#66810
		$socketOptions = array('l_onoff' => 1, 'l_linger' => 0);
		@socket_set_option($this->link, SOL_SOCKET, SO_LINGER, $socketOptions);
		@socket_close($this->link);
	}

	/**
	 * Returns a string which contains the connection info
	 *
	 * @return string
	 */
	public function getInfo() {
		$IP = "0.0.0.0";
		$port = 0;

		if ($this->link == null) {
			throw new SocketException("Socket not connected");
		}

		socket_getsockname($this->link, $IP, $port);

		return "IP: $IP:$port";
	}

	/**
	 * Returns the last error number
	 *
	 * @return int
	 */
	public function getLastErrorNo() {
		return socket_last_error($this->link);
	}

	/**
	 * Returns the last error this socket has received
	 *
	 * @return string
	 */
	public function getLastError() {
		return socket_strerror(socket_last_error($this->link));
	}
}
?>