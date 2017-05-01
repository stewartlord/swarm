<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Connection;

use P4\ClientPool\ClientPool;
use P4\Connection\Connection;
use P4\Log\Logger as P4Logger;
use Record\Cache\Cache as RecordCache;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Sets up a connection based on the params passed to constructor combined
 * with those present on the service locator config.
 */
class ConnectionFactory implements FactoryInterface
{
    const CLIENT_PREFIX = 'swarm-';

    protected $params   = null;

    /**
     * The constructor takes the paramaters to be passed to the p4 connection factory.
     * All other settings are taken off the service manager config.
     *
     * Expected keys include:
     *  port
     *  user
     *  client
     *  password
     *  ticket
     *
     * Though technically all optional, user and one of password or ticket are strongly
     * recommended.
     *
     * @param   array   $params     paramaters to pass to p4 connection factory
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * A new connection will be created using the params passed to our constructor
     * and then configured with a logger, services, etc. based on the service locator.
     *
     * @param   ServiceLocatorInterface $services   service locator for details
     * @return  Connection              the connection to use
     */
    public function createService(ServiceLocatorInterface $services)
    {
        $logger = $services->get('logger');
        $p4     = $this->params;

        // write p4 activity to the log
        P4Logger::setLogger($logger);

        // place the p4trust file under the data path.
        // the trust file has to be in a writable
        // location to support ssl enabled servers
        putenv('P4TRUST=' . DATA_PATH . '/p4trust');

        // use the factory to get an actual connection
        $connection = Connection::factory(
            isset($p4['port'])     ? $p4['port']     : null,
            isset($p4['user'])     ? $p4['user']     : null,
            isset($p4['client'])   ? $p4['client']   : null,
            isset($p4['password']) ? $p4['password'] : null,
            isset($p4['ticket'])   ? $p4['ticket']   : null
        );

        // set the program and version
        $connection->setProgName(VERSION_NAME);
        $connection->setProgVersion(VERSION_RELEASE . '/' . VERSION_PATCHLEVEL);

        // if slow command logging thresholds are specified pass them along
        if (isset($p4['slow_command_logging'])) {
            $connection->setSlowCommandLogging($p4['slow_command_logging']);
        }

        // if pre-run callbacks were specified, add them
        if (isset($p4['callbacks']['pre_run'])) {
            foreach ((array) $p4['callbacks']['pre_run'] as $callback) {
                $connection->addPreRunCallback($callback);
            }
        }

        // if post-run callbacks were specified, add them
        if (isset($p4['callbacks']['post_run'])) {
            foreach ((array) $p4['callbacks']['post_run'] as $callback) {
                $connection->addPostRunCallback($callback);
            }
        }

        // give the connection a client manager
        $prefix = static::CLIENT_PREFIX;
        $connection->setService(
            'clients',
            function ($p4) use ($services, $prefix) {
                // we base our maximum number of clients on the number of workers
                // if we cannot determine the worker limit we use the default of 3.
                $config  = $services->get('Configuration');
                $workers = isset($config['queue']['workers']) ? $config['queue']['workers'] : 3;

                // set the root and max. we double the workers to allow for use
                // of clients in web processes as well.
                // @todo user partitioning logic should move into the client pool
                $clients = new ClientPool($p4);
                $clients->setRoot(DATA_PATH . '/clients/' . strtoupper(bin2hex($p4->getUser())))
                        ->setPrefix($prefix)
                        ->setMax($workers * 2);

                return $clients;
            }
        );

        // inject a simple caching service as a factory (lazy-loaded)
        $connection->setService(
            'cache',
            function ($p4) {
                $cache = new RecordCache($p4);
                $cache->setCacheDir(DATA_PATH . '/cache');
                return $cache;
            }
        );

        // lazily expose the depot_storage service via the Zend service manager
        $connection->setService(
            'depot_storage',
            function () use ($services) {
                return $services->get('depot_storage');
            }
        );

        // lazily expose the translator service via the Zend service manager
        $connection->setService(
            'translator',
            function () use ($services) {
                return $services->get('translator');
            }
        );

        return $connection;
    }
}
