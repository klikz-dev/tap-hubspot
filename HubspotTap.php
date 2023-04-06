<?php

use GuzzleHttp\Client;
use SingerPhp\SingerTap;
use SingerPhp\Singer;
use HubSpot\Factory;
use HubSpot\Client\Crm\Properties\ApiException;

class HubspotTap extends SingerTap
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Hubspot PHP SDK client
     *
     * @var object
     */
    private $client = null;

    /**
     * Hubspot API Key
     *
     * @var string
     */
    private $access_token = '';

    /**
     * Current table being processed
     *
     * @var string
     */
    private $table = '';

    /**
     * Retry
     */
    const MAX_RETRIES = 5;
    const RETRY_INTERVAL = 15;

    /**
     * Column types
     * https://developers.hubspot.com/docs/api/crm/properties
     */
    private $types = [
        'bool'        => Singer::TYPE_BOOLEAN,
        'enumeration' => Singer::TYPE_STRING,
        'date'        => Singer::TYPE_DATE,
        'dateTime'    => Singer::TYPE_DATETIME,
        'string'      => Singer::TYPE_STRING,
        'number'      => Singer::TYPE_NUMBER,
    ];

    /**
     * Table Map
     */
    private $table_map = [
        'companies' => [
            'category' => 'Objects',
            'scope' => 'crm.objects.companies.read'
        ],
        'contacts' => [
            'category' => 'Objects',
            'scope' => 'crm.objects.contacts.read'
        ],
        'deals' => [
            'category' => 'Objects',
            'scope' => 'crm.objects.deals.read'
        ],
        'line_items' => [
            'category' => 'Objects',
            'scope' => 'crm.objects.line_items.read'
        ],
        'products' => [
            'category' => 'Objects',
            'scope' => 'e-commerce'
        ],
        'tickets' => [
            'category' => 'Objects',
            'scope' => 'tickets'
        ],
        'quotes' => [
            'category' => 'Objects',
            'scope' => 'crm.objects.quotes.read'
        ],
        'calls' => [
            'category' => 'Engagements',
            'scope' => 'crm.objects.contacts.read'
        ],
        'emails' => [
            'category' => 'Engagements',
            'scope' => 'sales-email-read'
        ],
        'meetings' => [
            'category' => 'Engagements',
            'scope' => 'crm.objects.contacts.read'
        ],
        'notes' => [
            'category' => 'Engagements',
            'scope' => 'crm.objects.contacts.read'
        ],
        'postal_mail' => [
            'category' => 'Engagements',
            'scope' => 'crm.objects.contacts.read'
        ],
        'tasks' => [
            'category' => 'Engagements',
            'scope' => 'crm.objects.contacts.read'
        ],
    ];

    /**
     * tests if the connector is working then writes the results to STDOUT
     */
    public function test()
    {
        try {
            $this->access_token = $this->singer->config->input('access_token');
            $this->initializeClient();
            $this->getProperties('companies');

            $this->singer->writeMeta(['test_result' => true]);
        } catch (Exception $e) {
            $this->singer->writeMeta(['test_result' => false]);
        }
    }

    /**
     * gets all schemas/tables and writes the results to STDOUT
     */
    public function discover()
    {
        $this->singer->logger->debug('Starting discover for tap Hubspot');

        $this->access_token = $this->singer->config->setting('access_token');
        $this->initializeClient();

        foreach ($this->singer->config->catalog->streams as $stream) {
            $this->table = $stream->stream;

            $this->singer->logger->debug("Writing schema for {$this->table}");

            $response = $this->getProperties($this->table);

            $columns = [
                'id' => [
                    'type' => Singer::TYPE_STRING
                ]
            ];
            foreach($response['results'] as $column) {
                $type = $column['type'];
                $type = array_key_exists($type, $this->types) ? $this->types[$type] : Singer::TYPE_STRING;

                $columns[$column['name']] = [
                    'type' => $type
                ];
            }

            $this->singer->writeSchema(
                stream: $this->table,
                schema: $columns,
                key_properties: ['id']
            );
        }
    }

    /**
     * gets the record data and writes to STDOUT
     */
    public function tap()
    {
        $this->singer->logger->debug('Starting sync for tap Hubspot');

        $this->access_token = $this->singer->config->setting('access_token');
        $this->initializeClient();

        foreach ($this->singer->config->catalog->streams as $stream) {
            $this->table = $stream->stream;

            $this->singer->logger->debug("Writing schema for {$this->table}");

            $response = $this->getProperties($this->table);

            $columns = [
                'id' => [
                    'type' => 'string'
                ]
            ];
            foreach($response['results'] as $column) {
                $type = $column['type'];
                $type = array_key_exists($type, $this->types) ? $this->types[$type] : Singer::TYPE_STRING;

                $columns[$column['name']] = [
                    'type' => $type
                ];
            }

            $this->singer->writeSchema(
                stream: $this->table,
                schema: $columns,
                key_properties: ['id']
            );

            $this->singer->logger->debug("Starting sync for {$this->table}");

            $total_records = 0;
            $after = '0';
            while (True) {
                $response = $this->getObjects($this->table, $after);

                if (isset($response['results'])) {
                    $results = $response['results'];

                    foreach ($results as $result) {
                        $record = $result['properties'];
                        $record['id'] = $result['id'];

                        $this->singer->writeRecord(
                            stream: $this->table,
                            record: $this->formatRecord($record, $columns),
                        );
                        $total_records++;
                    }
                } else {
                    break;
                }

                if (isset($response['paging'])) {
                    $after = $response['paging']['next']['after'];
                } else {
                    break;
                }
            }

            $this->singer->writeMetric(
                'counter',
                'record_count',
                $total_records,
                [
                    'table' => $this->table
                ]
            );

            $this->singer->logger->debug("Finished sync for {$this->table}");
        }
    }

    /**
     * writes a metadata response with the tables to STDOUT
     */
    public function getTables()
    {
        $this->access_token = $this->singer->config->input('access_token');

        // Get the scopes associated with the access token provided.
        $client = new GuzzleHttp\Client();
        $response = $client->request(
            'POST',
            'https://api.hubapi.com/oauth/v2/private-apps/get/access-token-info',
            [
                'http_errors' => false,
                'headers' => [ 'Content-Type' => 'application/json'],
                'body' => json_encode(['tokenKey' => $this->access_token])
            ]
        );
        $token_info = json_decode((string) $response->getBody(), true);

        // Filter tables based on the scopes associated.
        if ( isset($token_info['scopes']) ) {
            $scopes = $token_info['scopes'];

            $tables = array_filter($this->table_map, function($table) use ($scopes) {
                return in_array($table['scope'], $scopes);
            });

            $tables = array_values(array_keys($tables));

            $this->singer->writeMeta(compact('tables'));
        } else {
            $code = $response->getStatusCode();
            throw new Exception("Unable to retrieve the scopes associated with the access token. status code: ({$code})");
        }
    }

    /**
     * initialize Hubspot PHP SDK client
     */
    public function initializeClient()
    {
        $this->client = Factory::createWithAccessToken($this->access_token);
    }

    /**
     * get CRM Object properties that will be used to decide the table columns.
     * @param  string   $object_type     object type (table name)
     * @return array
     * @throws Exception
     */
    public function getProperties($object_type)
    {
        $attempts = 0;
        while (True) {
            try {
                $response = $this->client->crm()->properties()->coreApi()->getAll($object_type, false);
                return $response;
            } catch (ApiException $e) {
                $attempts++;
                if ($attempts > self::MAX_RETRIES) {
                    throw new Exception("Exception when calling core_api->get_all: " . $e->getMessage());
                }

                $this->singer->logger->debug("Getting {$object_type} properties failed. Will retry in " . self::RETRY_INTERVAL . " seconds.");
                sleep(self::RETRY_INTERVAL);
            }
        }
    }

    /**
     * get CRM Object data
     * @param  string   $object_type     object type (table name)
     * @param  string   $after          pagination token
     * @return array
     * @throws Exception
     */
    public function getObjects($object_type, $after = '0')
    {
        // Convert snake_case to camelCase
        $objectType = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $object_type))));

        $attempts = 0;
        while (True) {
            try {
                if ($this->table_map[$object_type]['category'] == "Objects") {
                    $response = $this->client->crm()->$objectType()->basicApi()->getPage(100, $after, false);
                    return $response;
                }
                elseif ($this->table_map[$object_type]['category'] == "Engagements") {
                    $response = $this->client->crm()->objects()->$objectType()->basicApi()->getPage(100, $after, false);
                    return $response;
                }
                
                return [];
            } catch (ApiException $e) {
                $attempts++;
                if ($attempts > self::MAX_RETRIES) {
                    throw new Exception("Exception when calling basic_api->get_page: " . $e->getMessage());
                }

                $this->singer->logger->debug("Getting {$object_type} objects failed. Will retry in " . self::RETRY_INTERVAL . " seconds.");
                sleep(self::RETRY_INTERVAL);
            }
        }
    }

    /**
     * Format records to match table columns
     * @param array   $record           The response array
     * @param array   $columns          The record model
     * @return array
     */
    public function formatRecord($record, $columns) {
        $recordValues = array_map(function($key, $value) use ($columns) {
            // Fix invalid number format
            if ( $columns[$key]['type'] == Singer::TYPE_NUMBER ) {
                if ( $value == null || $value == "" ) {
                    return 0;
                }
            }
            return $value;
        }, array_keys($record), array_values($record));

        $record = array_combine(array_keys($record), $recordValues);

        return $record;
    }
}
