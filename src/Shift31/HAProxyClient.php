<?php

namespace Shift31;

use Zend\Http\Client;

/**
 * Class HAProxyClient
 *
 * @package Shift31
 */
class HAProxyClient
{

	const STATUS_DOWN = 'DOWN';
	const STATUS_UP = 'UP';
	const ACTION_DISABLE = 'disable';
	const ACTION_ENABLE = 'enable';

	protected $_checkMaxRetries;

    protected $_port;
    protected $_baseUrl;
    protected $_username;
    protected $_password;
	protected $_serverFqdn;
    protected $_statsUrl;
	protected $_client;
	protected $_logger;


	/**
	 * @param string|null	$serverFqdn
	 * @param int		$port
	 * @param string	$baseUrl
	 * @param string	$username
	 * @param string	$password
	 * @param int		$checkMaxRetries
	 * @param null		$logger
	 */
	public function __construct($serverFqdn, $port, $baseUrl, $username, $password, $checkMaxRetries = 15, $logger = null)
    {
		$this->_serverFqdn = $serverFqdn;
        $this->_port = $port;
        $this->_baseUrl = $baseUrl;
        $this->_username = $username;
        $this->_password = $password;
		$this->_checkMaxRetries = $checkMaxRetries;
		$this->_logger = $logger;

		$this->_client = new Client();
		$this->_client->setAuth($this->_username, $this->_password);

		if ($serverFqdn != null) {
			$this->setStatsUrl($serverFqdn);
			$this->setClientUri($serverFqdn);
		}
    }

    
    /**
     * @param string $serverFqdn
     */
    public function setStatsUrl($serverFqdn)
    {
		$this->_serverFqdn = $serverFqdn;
        $this->_statsUrl = "http://{$this->_username}:{$this->_password}@$serverFqdn:{$this->_port}{$this->_baseUrl};csv";
    }


	/**
	 * @param $serverFqdn
	 */
	public function setClientUri($serverFqdn)
	{
		$this->_serverFqdn = $serverFqdn;
		$this->_client->setUri("http://$serverFqdn:{$this->_port}{$this->_baseUrl}");
	}
    
    
    /**
     *
     * @return array $stats 
     */
    public function getStatsByProxy()
    {
        $stats = array();

        $lines = file($this->_statsUrl);

        if (preg_match('/#/', $lines[0])) {
            $header = str_getcsv(str_replace('# ', '', $lines[0]));

            foreach ($lines as $line) {

				// skip header
                if (preg_match('/#/', $line)) {
                    continue;
                }

                $csv = str_getcsv($line);

				// skip non-servers
                if (preg_match('/FRONTEND|BACKEND/', $csv[1])) {
                    continue;
                }

                for ($i = 0; $i < count($csv); $i++) {
                    $stats[$csv[0]][$csv[1]][$header[$i]] = $csv[$i];
                }
            }
        }

        return $stats;
    }

    
    /**
     * @param array		$loadBalancers	an array of HAProxy server FQDNs
	 *
     * @return array	$stats
     */
    public function getStatsFromCluster(array $loadBalancers)
    {
        $clusterStats = array();

        foreach ($loadBalancers as $loadBalancer) {
            try {
                $this->setStatsUrl($loadBalancer);
                $this->_log('info', "Getting stats from load balancer $loadBalancer...");
                $clusterStats[$loadBalancer] = $this->getStatsByProxy();
            } catch (\Exception $e) {
                $this->_log('err', "Exception: " . $e->getCode() . '-' . $e->getMessage());
                $this->_log('debug', "Exception Trace: " . $e->getTraceAsString());
                $this->_log('warn', "Unable to get stats from load balancer $loadBalancer");
            }
        }

        //$this->_log('debug', '$stats: ' . print_r($stats, true));
        return $clusterStats;
    }


    /**
     * @param array     $loadBalancers	an array of HAProxy server FQDNs
     * @param string    $proxyName
     * @param string    $serviceName
     * @param string    $statusToCheck
     *
     * @return void
     */
    public function checkServiceStatusInCluster(array $loadBalancers, $proxyName, $serviceName, $statusToCheck = 'UP')
    {
        $statusVerified = false;
		$noServiceInProxy = false;
		$lbTries = 0;

		while ($statusVerified === false && $lbTries < $this->_checkMaxRetries) {
			$lbTries++;

			$clusterStats = $this->getStatsFromCluster($loadBalancers);

			if (count($clusterStats) <= 0) {
				$this->_log('alert', "Unable to retrieve stats from any of $serviceName's load balancers. Please contact a Systems Administrator.");
				break; // break out of while loop
			}

			foreach ($clusterStats as $loadBalancer => $stats) {

				if (isset($stats[$proxyName][$serviceName])) {

					$proxyServiceStatus = $stats[$proxyName][$serviceName]['status'];
					$this->_log('debug', "$loadBalancer: Status for service '$serviceName' in proxy '$proxyName' is '$proxyServiceStatus'");

					if ($statusToCheck == 'UP') {
						$statusVerified = ($proxyServiceStatus == 'UP') ? true : false;
					} else {
						// make sure status is 'DOWN' or 'MAINT' and has zero current connections
						$statusVerified = ($proxyServiceStatus == 'DOWN' || $proxyServiceStatus == 'MAINT') && ($stats[$proxyName][$serviceName]['scur'] == '0') ? true : false;
					}

				} else {
					$this->_log('alert', "Unable to find service named '$serviceName' in proxy named '$proxyName' on $loadBalancer. Please contact a Systems Administrator.");
					$noServiceInProxy = true;
					break 2; // break out of while loop
				}
			}

			// wait 2 seconds between tries
			sleep(2);
		}

		if ($statusVerified === true) {
			$this->_log('info', "HAProxy detected service '$serviceName' of proxy '$proxyName' is '$statusToCheck'.  Retried $lbTries times (" . $lbTries * 2 . ' seconds).');
		} elseif ($noServiceInProxy === false) {
			$this->_log('warn', "HAProxy did not detect service '$serviceName' of proxy '$proxyName' as '$statusToCheck'.  Retried $lbTries times (" . $lbTries * 2 . ' seconds).');
		}
    }


	/**
	 * @param string	$serverName
	 * @param string	$action
	 * @param string	$proxyName
	 *
	 * @return bool
	 */
	public function setServerStatus($serverName, $action = 'enable', $proxyName = 'all')
	{
		// perform action on all proxies where server exists
		if ($proxyName == 'all') {
			$proxyNames = array();
			$stats = $this->getStatsByProxy();

			foreach ($stats as $proxyName => $serviceNames) {
				if (array_key_exists($serverName, $serviceNames)) {
					$proxyNames[] = $proxyName;
				}
			}
		} else {
			$proxyNames = array($proxyName);
		}

		foreach ($proxyNames as $proxyName) {
			try {
				$this->_client->setMethod(\Zend_Http_Client::POST);
				$this->_client->setParameterPost('s', $serverName);
				$this->_client->setParameterPost('b', $proxyName);
				$this->_client->setParameterPost('action', $action);
				$this->_client->request();
			} catch (\Exception $e) {
				$this->_log('crit', "Exception: " . $e->getCode() . '-' . $e->getMessage());
				$this->_log('debug', "Exception Trace: " . $e->getTraceAsString());
			}

			$response = $this->_client->getLastResponse();
			$success = $response->isSuccessful();

			if ($success) {
				$this->_log('info', ucwords($action) . "d '$serverName' in proxy '$proxyName' on $this->_serverFqdn");

				// get the status notice displayed on the page
				/** @noinspection PhpUndefinedMethodInspection */
				$notice = str_replace(array('[X]', '.'), '', qp($response->getRawBody(), 'div')->text());
				$this->_log('debug', "HAProxy response on $this->_serverFqdn: $notice");
			} else {
				$this->_log('err', "Failed to $action '$serverName' in proxy '$proxyName' on $this->_serverFqdn");
			}
		}
	}


	/**
	 * @param array  	$loadBalancers	an array of HAProxy server FQDNs
	 * @param string	$serverName
	 * @param string 	$action
	 * @param string 	$proxyName
	 */
	public function setServerStatusInCluster(array $loadBalancers, $serverName, $action = 'enable', $proxyName = 'all')
	{
		foreach ($loadBalancers as $loadBalancer) {
			$this->setStatsUrl($loadBalancer);
			$this->setClientUri($loadBalancer);
			$this->setServerStatus($serverName, $action, $proxyName);
		}
	}


	/**
	 *
	 * @param string $priority
	 * @param string $message
	 */
	protected function _log($priority, $message)
	{
		if ($this->_logger != null) {
			$class = str_replace(__NAMESPACE__ . "\\", '', get_called_class());
			$this->_logger->$priority("[$class] - $message");
		}
	}
}