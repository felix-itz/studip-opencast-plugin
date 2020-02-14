<?php

namespace Opencast\Routes\Config;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Opencast\Errors\AuthorizationFailedException;
use Opencast\OpencastTrait;
use Opencast\OpencastController;

use Opencast\Models\Config;
use Opencast\Models\OCEndpoints;
use Opencast\Models\OCSeriesModel;
use Opencast\Models\REST\Config as RESTConfig;
use Opencast\Models\REST\ServicesClient;

use Opencast\Models\I18N as _;

class ConfigAdd extends OpencastController
{
    use OpencastTrait;

    public function __invoke(Request $request, Response $response, $args)
    {
        \SimpleOrMap::expireTableScheme();

        $json = $this->getRequestData($request);

        // store config in db
        $config = Config::find(1);

        if (!$config) {
            $config = new Config;
            $config->id = 1;
        }

        $config->config = $json['config'];
        $config->store();

        $config_id = $config->id;

        // check Configuration and load endpoints
        $message = null;

        // invalidate series-cache when editing configuration
        \StudipCacheFactory::getCache()->expire('oc_allseries');

        $service_url =  parse_url($config->config['url']);

        // check the selected url for validity
        if (!array_key_exists('scheme', $service_url)) {
            $message = [
                'type' => 'error',
                'text' => sprintf(
                    _('Ungültiges URL-Schema: "%s"'),
                    $config->config['url']
                )
            ];

            OCEndpoints::deleteBySql('config_id = ?', [$config_id]);
        } else {
            $service_host =
                $service_url['scheme'] .'://' .
                $service_url['host'] .
                (isset($service_url['port']) ? ':' . $service_url['port'] : '');

            try {
                $version = RESTConfig::getOCBaseVersion($config->id);

                OCEndpoints::deleteBySql('config_id = ?', [$config_id]);

                $config->config['version'] = $version;
                $config->store();

                OCEndpoints::setEndpoint($config_id, $service_host .'/services', 'services');

                $services_client = new ServicesClient($config_id);

                $comp = null;
                $comp = $services_client->getRESTComponents();
            } catch (AccessDeniedException $e) {
                OCEndpoints::removeEndpoint($config_id, 'services');

                $message = [
                    'type' => 'error',
                    'text' => sprintf(
                        $this->_('Fehlerhafte Zugangsdaten für die Opencast Installation mit der URL "%s". Überprüfen Sie bitte die eingebenen Daten.'),
                        $service_host
                    )
                ];

                $this->redirect('admin/config');
                return;
            }

            if ($comp) {
                $services = OCModel::retrieveRESTservices($comp, $service_url['scheme']);

                if (empty($services)) {
                    OCEndpoints::removeEndpoint($config_id, 'services');

                    $message = [
                        'type' => 'error',
                        'text' => sprintf(
                            $this->_('Es wurden keine Endpoints für die Opencast Installation mit der URL "%s" gefunden. '
                                . 'Überprüfen Sie bitte die eingebenen Daten, achten Sie dabei auch auf http vs https und '
                                . 'ob ihre Opencast-Installation https unterstützt.'),
                            $service_host
                        )
                    ];
                } else {

                    foreach($services as $service_url => $service_type) {
                        if (in_array(strtolower($service_type), Opencast\Constants::$SERVICES) !== false) {
                            OCEndpoints::setEndpoint($config_id, $service_url, $service_type);
                        } else {
                            unset($services[$service_url]);
                        }
                    }

                    $success_message[] = sprintf(
                        $this->_('Die Opencast Installation "%s" wurde erfolgreich konfiguriert.'),
                        $service_host
                    );

                    $message = [
                        'type' => 'success',
                        'text' => implode('<br>', $success_message)
                    ];
                }
            } else {
                OCEndpoints::removeEndpoint($config_id, 'services');
                $message = [
                    'type' => 'error',
                    'text' => sprintf(
                        _('Es wurden keine Endpoints für die Opencast Installation mit der URL "%s" gefunden. Überprüfen Sie bitte die eingebenen Daten.'),
                        $service_host
                    )
                ];
            }
        }


        // after updating the configuration, clear the cached series data
        OCSeriesModel::clearCachedSeriesData();
        #OpencastLTI::generate_complete_acl_mapping();

        return $this->createResponse(['config' => $config, 'message' => $message], $response);
    }
}
