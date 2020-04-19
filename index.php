<?php
new class
{
	const DATA_DIR = '/data';
	const WKHTMLTOPDF_BIN = '/usr/local/bin/wkhtmltopdf';
	
	private $api_action = null;
	private $api_output = null;
	private $api_status = null;
	private $api_message = null;
	private $api_request = null;
	private $api_request_raw = null;
	
	public function __construct ()
	{
		header('Content-Type: text/plain;charset=utf-8');
		$url = parse_url(isset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI']) ? str_replace(dirname($_SERVER['SCRIPT_NAME']), '', $_SERVER['REQUEST_URI']) : '');
		
		// wczytanie danych wejściowych
		$this->api_query = null;
		$this->api_action = 'api_' . str_replace('-', '_', preg_replace('/[^a-z0-9_-]/', '', isset($url['path']) ? $url['path'] : ''));
		$this->api_request_raw = file_get_contents('php://input');
		$this->api_request = json_decode($this->api_request_raw, true);
		if (isset($url['query']))
			parse_str($url['query'], $this->api_query);
		
		// sprawdzenie requestu i dodanie wymaganych nagłówków
		$this->_headers();
		
		// kody i opisy odpowiedzi
		$this->api_status = 500;
		$this->api_message = [
			0	=> 'OK',
			1	=> 'Not supported',
			2	=> 'Executable not found',
			3	=> 'Failed to process',
			4	=> 'Invalid token',
			5	=> 'In progress',
			500	=> 'Error',
		];
		
		// wykonanie funkcji API
		if (is_callable([$this, $this->api_action]))
		{
			try
			{
				$this->{$this->api_action}($this->api_request);
			}
			catch (exception $e)
			{
				$this->api_output['!exception'] = [
					'line'		=> $e->getLine(),
					'file'		=> $e->getFile(),
					'message'	=> $e->getMessage()
				];
			}
		}
		
		// wysłanie odpowiedzi
		$this->_response();
	}
	
	private function _input (array $input = null, array $defaults = null): array
	{
		$output = [];
		if ($defaults !== null)
			$output = $defaults;
		return array_merge($output, is_array($input) ? $input : []);
	}
	
	private function _headers ()
	{
		header('Access-Control-Allow-Origin: *', true);
		header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
		header('Access-Control-Allow-Headers: Keep-Alive,User-Agent,X-Safestar-Token,Cache-Control,Content-Type');
		header('Access-Control-Max-Age: 1728000');
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			header('Content-Length: 0');
			exit;
		}
	}
	
	private function _response ()
	{
		header('Content-Type: application/json; charset=utf-8');
		die(preg_replace('/:\s*\"([1-9][0-9]{0,' . (strlen((string)PHP_INT_MAX) - 1) . '}|0)\"/', ':$1', json_encode(array_merge(is_array($this->api_output) ? $this->api_output : [], ['status_code' => $this->api_status, 'status_message' => $this->api_message[$this->api_status]]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)));
	}
	
	// ====================  Funkcje API  ====================
	
	private function api_process (array $input = null)
	{
		$input = $this->_input($input, [
			'args'		=> false,
			'html'		=> false,
			'header'	=> false,
			'footer'	=> false
		]);
		
		// dane wejściowe html
		if ($input['html'] !== false)
		{
			// sprawdzenie, czy w ogóle mamy wkhtmltopdf
			if (file_exists(self::WKHTMLTOPDF_BIN))
			{
				// wygenerowanie tokenu do pliku
				$pdf_token = sprintf('p%13s', uniqid());
				
				// zbudowanie parametrów dodatkowych
				$args = [
					'--quiet',
					'--disable-smart-shrinking',
					'--enable-internal-links'
				];
				if (is_array($input['args']) && !empty($input['args']))
					foreach ($input['args'] as $arg)
						$args[] = $arg;
				
				// szablon plików tymczasowych
				$file_template = sys_get_temp_dir() . '/' . $pdf_token . '_' . str_replace('.', '_', microtime(true)) . '_%s.html';
				$file_temp = [];
				
				// zapisanie danych wejściowych do pliku
				if ($input['html'] !== false && strlen($input['html']) > 0)
				{
					file_put_contents(sprintf($file_template, 'content'), $input['html']);
					$file_temp['content'] = sprintf($file_template, 'content');
				}
				if ($input['header'] !== false && strlen($input['header']) > 0)
				{
					file_put_contents(sprintf($file_template, 'header'), $input['header']);
					$file_temp['header'] = sprintf($file_template, 'header');
					$args[] = '--header-html ' . sprintf($file_template, 'header');
				}
				if ($input['footer'] !== false && strlen($input['footer']) > 0)
				{
					file_put_contents(sprintf($file_template, 'footer'), $input['footer']);
					$file_temp['footer'] = sprintf($file_template, 'footer');
					$args[] = '--footer-html ' . sprintf($file_template, 'footer');
				}
				
				// wykonanie procesu wkhtmltopdf (w tle)
				if (isset($file_temp['content']))
				{
					$pid = trim(shell_exec(self::WKHTMLTOPDF_BIN . ' ' . implode(' ', $args) . ' ' . $file_temp['content'] . ' ' . self::DATA_DIR . '/' . $pdf_token . '.pdf >/dev/null 2>&1 & echo $!'));
					if (posix_kill($pid, 0))
					{
						// zapisanie metadanych
						file_put_contents(self::DATA_DIR . '/' . $pdf_token . '.metadata', json_encode(['tmp' => $file_temp, 'pdf' => self::DATA_DIR . '/' . $pdf_token . '.pdf', 'ts' => time(), 'pid' => $pid]));
						
						// zwrócenie statusu
						$this->api_status = 0;
						$this->api_output['pid'] = $pid;
						$this->api_output['token'] = $pdf_token;
					}
					else
					{
						// usunięcie plików tymczasowych
						foreach ($file_temp as $_tmp)
							unlink($_tmp);
						
						// zwrócenie statusu (błąd generowania)
						$this->api_status = 3;
					}
				}
			}
			else
				$this->api_status = 2;
		}
	}
	
	private function api_check (array $input = null)
	{
		$input = $this->_input($input, [
			'token'		=> false
		]);
		
		if ($input['token'] !== false && preg_match('/^p[0-9a-f]{13}$/', $input['token']))
		{
			// sprawdzenie metadanych
			if (file_exists(self::DATA_DIR . '/' . $input['token'] . '.metadata'))
			{
				$meta = json_decode(file_get_contents(self::DATA_DIR . '/' . $input['token'] . '.metadata'));
				if (posix_kill($meta->pid, 0))
				{
					$this->api_status = 5;
					$this->api_output['pid'] = $meta->pid;
					$this->api_output['token'] = $input['token'];
				}
				else
				{
					if (file_exists($meta->pdf) && filesize($meta->pdf) > 0)
					{
						// usunięcie pliku tymczasowego
						foreach ($meta->tmp as $_tmp)
							if (file_exists($_tmp))
								unlink($_tmp);
						
						// zwrócenie danych pliku
						$this->api_status = 0;
						$this->api_output['ts'] = filemtime($meta->pdf);
						$this->api_output['size'] = filesize($meta->pdf);
						$this->api_output['token'] = $input['token'];
					}
					else
					{
						$this->api_status = 3;
					}
				}
			}
			else
			{
				$this->api_status = 4;
			}
		}
	}
	
	private function api_download ()
	{
		if (isset($this->api_query['token']) && preg_match('/^p[0-9a-f]{13}$/', $this->api_query['token']))
		{
			if (file_exists(self::DATA_DIR . '/' . $this->api_query['token'] . '.pdf'))
			{
				// zwrócenie pliku
				header('Content-Type: application/pdf');
				header('Content-Length: ' . filesize(self::DATA_DIR . '/' . $this->api_query['token'] . '.pdf'));
				readfile(self::DATA_DIR . '/' . $this->api_query['token'] . '.pdf');
				
				// usunięcie pliku
				unlink(self::DATA_DIR . '/' . $this->api_query['token'] . '.pdf');
				
				// usunięcie metadanych
				if (file_exists(self::DATA_DIR . '/' . $this->api_query['token'] . '.metadata'))
					unlink(self::DATA_DIR . '/' . $this->api_query['token'] . '.metadata');
			}
			else
				header('Status: 404 Not Found');
		}
		else
			header('Status: 403 Forbidden');
		exit;
	}
};