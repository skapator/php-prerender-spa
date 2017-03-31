<?php

namespace Hug\PrerenderSpa;

use Hug\Http\Http as Http;
use Hug\FileSystem\FileSystem as FileSystem;

/**
 *
 */
class PrerenderSpa
{
    public $urls;
    public $output;
    public $prerender_url;
    public $prerender_auth;
    public $prerender_sleep;

    public $report = [];

    /**
     * Constructor
     *
     * @param array $urls
     * @param string $output Output directory must be writable
     * @param string $prerender_url Prerender URL to call to generate HTML Snapshot
     * @param string $prerender_auth Optional Basic Authentication for your Prerender service (USER:PASSWORD)
     * @param int $prerender_sleep Seconds to wait between two calls to prerender service (default to 2 seconds)
     */
    function __construct($urls, $output, $prerender_url, $prerender_auth = null, $prerender_sleep = 2)
    {
        $this->urls = $urls;
        $this->output = $output;
        $this->prerender_url = $prerender_url;
        $this->prerender_auth = $prerender_auth;
        $this->prerender_sleep = $prerender_sleep;

        if(!(is_dir($this->output) && is_writable($this->output)))
        {
        	throw new \Exception("Output directory not writable", 1);
        }
        else
        {
        	if(!is_dir($this->output . 'reports'))
        		mkdir($this->output . 'reports');
        	if(!is_dir($this->output . 'snapshots'))
        		mkdir($this->output . 'snapshots');
        	if(!is_dir($this->output . 'archives'))
        		mkdir($this->output . 'archives');
        	if(!is_dir($this->output . 'logs'))
        		mkdir($this->output . 'logs');
        }
    }

    /**
     * Run Snapshot rendering for given URLs
     *
     * @return array $report Report generated by process 
     */
    public function prerender()
    {
        foreach ($this->urls as $key => $url)
        {
        	# Init report for this URL
        	$this->report[$key] = ['url' => $url];

            $time_pre = microtime(true);

            $prerender_snapshot_url = $this->prerender_url . $url;
            error_log('Call prerender : ' . $prerender_snapshot_url);

            # Cannot use file_get_contents because I block requests without a user agent and cannot pass Basic Auth params but it could fit your needs
            // $html = file_get_contents($prerender_snapshot_url);
            
            
            if(false !== $html = $this->take_snapshot($prerender_snapshot_url, $key))
            {
            	# Transform URL to Filename
	            $url_file = PrerenderSpa::url_to_filename($url);
	       		$this->report[$key]['file'] = $url_file;

	       		# Archive
	       		// PrerenderSpa::archive_snapshot($filename)

	            # Save snapshot
	    		file_put_contents($this->output . 'snapshots' . DIRECTORY_SEPARATOR . $url_file, $html);
	    	}

    		# Add execution time to report
    		# Could add info like % of difference with previous version ...
        	$this->report[$key]['time'] = round(microtime(true) - $time_pre, 2);

        	sleep($this->prerender_sleep);
        }

        # Save report
        $this->save_report($this->report);

        return $this->report;
    }

    /**
     * Call prerender service for one URL
     *
     * @param string $url URL to take snapshot for
     * @param int $key current report key to fill in data
     * @return string $html HTML Snapshot of required webpage
     */
    private function take_snapshot($url, $key)
    {
    	$html = '';
    	try
        {
	        $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/56.0.2924.76 Chrome/56.0.2924.76 Safari/537.36');
	        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        
	        # Otionnaly set Basic Auth on your snapshot service if you don't want it to be spammed
	        if($this->prerender_auth!==null)
	        {
	            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	            curl_setopt($ch, CURLOPT_USERPWD, $this->prerender_auth);
	        }
	        
	        $data = curl_exec($ch);

	        $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	        curl_close($ch);

	        if($retcode===200)
	        {
	        	$html = $data;
	        }
	        else
	        {
	        	error_log('Screenshot provider error. Status : ' . $retcode . ' Html : ' . $data);
	        }
	        $this->report[$key]['http'] = $retcode;
	        $this->report[$key]['size'] = FileSystem::human_file_size(strlen($html));
	    }
        catch (\Exception $e)
        {
			$html = false;
        }
        return $html;
    }

    /**
     * Transforms an URL into a acceptable file name
     * 
     * @param string $url
     * @return string $filename
     */
    public static function url_to_filename($url)
    {
        $filename = '';

        try
        {
	        # Remove domain from filename to shorten and simplify
	        $domain = rtrim(Http::extract_domain_from_url($url), '/');

	        $scheme = parse_url($url, PHP_URL_SCHEME) . '://';
	        // error_log('domain : ' . $domain);
	        // error_log('scheme : ' . $scheme);

	        $filename = trim(str_replace([$scheme, $domain, '/'], ['', '', '-'], $url), '-');

	        if($filename==='')
	            $filename = 'index';
	        $filename .= '.html';
        }
        catch (\Exception $e)
        {
			$filename = false;
        }

        return $filename;
    }

    /**
     * Extracts URLs from a Sitemap
     *
     * @param string $filename Path to sitemap file
     * @return array $urls Array of URLs extracted from sitemap or false on failure
     */
    public static function get_sitemap_urls($filename)
    {
        $urls = false;
	
		try
		{
            if(is_file($filename) && is_readable($filename))
            {
    	        $sitemap = file_get_contents($filename);
    	        if($sitemap!==false)
    	        {
    		        $xml = new \SimpleXMLElement($sitemap);
    		        
    		        $urls = [];

    		        foreach ($xml->url as $url_list)
    		        {
    		            $urls[] = (string)$url_list->loc;
    		        }
    		    }
            }
            else
            {
                error_log('PrerenderSpa get_sitemap_urls : ' . $filename . ' does not exist or is not writable ! ');
            }
        }
        catch (\Exception $e)
        {
			$urls = false;
        }

        return $urls;
    }


    /**
     * Retrieve lastest snapshot for given URL
     *
     * @param string $url
     * @return string $html HTML snapshot of required web page
     */
    public static function get_snapshot($url, $output)
    {
        $html = false;

        try
        {
        	$url_file = PrerenderSpa::url_to_filename($url);
	        $url_file_path = $output . 'snapshots' . DIRECTORY_SEPARATOR . $url_file;
            if(is_file($url_file_path) && is_readable($url_file_path))
            {
    	        $html = file_get_contents($url_file_path);
            }
            else
            {
                error_log('PrerenderSpa get_snapshot : File ' . $url_file_path . ' does not exist or not readable !');
            }
        }
        catch (\Exception $e)
        {
			$html = false;
        } 
        
        return $html;
    }

    /**
     * Log Snap Shot Request And by whom
     *
     * @param string $ip
     * @param string $useragent
     * @param string $url
     * @param string $http_code
     * @return bool $log
     */
    public static function log_snapshot($ip, $ua, $url, $http_code, $output)
    {
        $log = false;

        try
        {
            $date = new \DateTime('now');
            $today = $date->format('d-m-Y');
            $now = $date->format('d-m-Y H:i:s');
            
            $today_log = $output . 'logs' . DIRECTORY_SEPARATOR . 'snapshot-'.$today.'.log';
            
            if(!is_file($today_log))
            {
                file_put_contents($today_log, '');
            }

            if(is_file($today_log) && is_readable($today_log))
            {
                $log_line = $now.';'.$ip.';'.$url.';'.$http_code.';'.$ua."\n";
                
                if(file_put_contents($today_log, $log_line, FILE_APPEND)!==false)
                {
                    $log = true;
                }
            }
            else
            {
                error_log('PrerenderSpa log_snapshot : File ' . $today_log . ' does not exist or not readable !');
            }
        }
        catch (\Exception $e)
        {
            $log = false;
        } 
        
        return $log;
    }

    /**
     * Save report generated by prerender function
     *
     * @param string $url
     * @return string $html HTML snapshot of required web page
     */
    public function save_report()
    {
    	$saved = false;

        try
        {
        	$date = new \DateTime('now');
        	$date = $date->format('Y-m-d H:i:s');
	        $filename = $this->output . 'reports' . '/' . $date .'.json'; 
	        if(file_put_contents($filename, json_encode($this->report)))
	        {
	        	$saved = true;
	        }
        }
        catch (\Exception $e)
        {
			$saved = false;
        } 
        
        return $saved;
    }

    /**
     * Load reports generated by prerender function
     *
     * @return array $reports
     */
    public function load_reports()
    {
    	$reports = false;

        try
        {
        	$reports = FileSystem::scandir_h($this->output . 'reports', 'json');
        }
        catch (\Exception $e)
        {
			$reports = false;
        } 
        
        return $reports;
    }

    /**
     * Set Custom 404 page
     *
     * @param string $html
     * @param string $output
     * @return bool $saved 
     */
    public static function set_404($html, $output)
    {
        $saved = false;

        $filename = $output . '404.html';
        try
        {
            if(file_put_contents($filename, $html)!==false)
            {
                $saved = true;
            }
        }
        catch(\Exception $e)
        {
            error_log('PrerenderSpa set_404 : ' . $e->getMessage());
        }

        return $saved;
    }

    /**
     * Get Custom or default 404 page
     *
     * @param string $output
     * @return string $_404 
     */
    public static function get_404($output)
    {
        $_404 = '';
        $filename = $output . '404.html';
        if(is_file($filename) && is_readable($filename))
        {
            $_404 = file_get_contents($filename);
        }
        else
        {
            $_404 = PrerenderSpa::get_default_404();
        }
        return $_404;
    }

    /**
     * Get Default 404 page
     *
     * @return string $_404 
     */
    public static function get_default_404()
    {
        $_404 = <<<'LABEL'
<!DOCTYPE html>
<html>
<head>
    <title>404</title>
</head>
<body>
404
</body>
</html>
LABEL;
        return $_404;
    }

    /**
     * Set Custom 500 page
     *
     * @param string $html
     * @param string $output
     * @return bool $saved 
     */
    public static function set_500($html, $output)
    {
        $saved = false;

        $filename = $output . '500.html';
        try
        {
            if(file_put_contents($filename, $html)!==false)
            {
                $saved = true;
            }
        }
        catch(\Exception $e)
        {
            error_log('PrerenderSpa set_500 : ' . $e->getMessage());
        }

        return $saved;
    }

    /**
     * Get Custom or default 500 page
     *
     * @param string $output
     * @return string $_500 
     */
    public static function get_500($output)
    {
        $_500 = '';
        $filename = $output . '500.html';
        if(is_file($filename) && is_readable($filename))
        {
            $_500 = file_get_contents($filename);
        }
        else
        {
            $_500 = PrerenderSpa::get_default_500();
        }
        return $_500;
    }

    /**
     * Get Default 500 page
     *
     * @return string $_500 
     */
    public static function get_default_500()
    {
            $_500 = <<<'LABEL'
<!DOCTYPE html>
<html>
<head>
    <title>500</title>
</head>
<body>
500
</body>
</html>
LABEL;
        return $_500;
    }


    /**
     * Archive older file (for recovery or comparison)
     */
    /*public static function archive_snapshot($filename)
    {
        # save older version for visual comparison and diff ?
        if(file_exists($filename))
        {
        }
    }*/

}