<?php

namespace Ideative\IdShutterstockConnector\Connector;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Ideative\IdStockPictures\ConnectorInterface;
use Ideative\IdStockPictures\Domain\Model\SearchResult;
use Ideative\IdStockPictures\Domain\Model\SearchResultItem;
use JsonException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use stdClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ShutterstockConnector implements ConnectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * URL of the Shutterstock Search API
     */
    public const SEARCH_ENDPOINT = 'https://api.shutterstock.com/v2/images/search';

    /**
     * URL of the Shutterstock Subscription API
     * This allows us to retrieve the current subscriptions to know which kind of assets we're allowed to download in HD
     */
    public const SUBSCRIPTION_ENDPOINT = 'https://api.shutterstock.com/v2/user/subscriptions';

    /**
     * URL of the Shutterstock Licencing API
     * This allows us to download HD versions of assets
     */
    public const LICENSING_ENDPOINT = 'https://api.shutterstock.com/v2/images/licenses';

    /**
     * URL of the Shutterstock Licencing API
     * This allows us to download HD versions of assets
     */
    public const CATEGORIES_ENDPOINT = 'https://api.shutterstock.com/v2/images/categories';

    /**
     * URL of the Shutterstock Collections API
     * This allows us to list all of the user's collections
     */
    public const COLLECTIONS_ENDPOINT = 'https://api.shutterstock.com/v2/images/collections';

    /**
     * URL of the Shutterstock Collections content API
     * This allows us to fetch the images contained in a selected collection
     */
    public const COLLECTIONS_CONTENT_ENDPOINT = 'https://api.shutterstock.com/v2/images/collections/{id}/items';

    /**
     * URL of the Shutterstock Images API
     * This allows us to fetch the details of a series of images, based on their ID
     */
    public const IMAGES_ENDPOINT = 'https://api.shutterstock.com/v2/images';

    protected mixed $extensionConfiguration;

    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('shutterstock-connector');
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param string $id
     * @return array
     * @throws GuzzleException
     */
    public function getFileUrlAndExtension(string $id): array
    {
        $subscriptionId = $this->getSubscription();
        $url = null;
        $extension = null;
        $result = null;
        $imageDetails = null;

        if ($subscriptionId) {
            try {
                $body = [
                    'images' => [
                        [
                            'image_id' => $id,
                        ]
                    ]
                ];

                $client = new Client();
                $response = $client->request('POST', self::LICENSING_ENDPOINT, [
                    'query' => [
                        'subscription_id' => $subscriptionId
                    ],
                    'body' => json_encode($body),
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
                        'Content-Type' => 'application/json'
                    ],
                ]);
                $result = json_decode($response->getBody()->getContents());

                $url = !empty($result->data[0]->download->url) ? $result->data[0]->download->url : null;
                $extension = pathinfo($url)['extension'] ?? '';
            } catch (Exception $e) {
                $this->logger->critical($e->getMessage());
            }
        }
        $errors = [];
        if ($result->errors) {
            foreach ($result->errors as $error) {
                $errors[] = $error->message;
            }
        }
        $imageDetails = $this->getImageDetails($id);

        return [
            'url' => $url,
            'extension' => $extension,
            'metadata' => [
                'title' => $this->getFileTitle($imageDetails),
                'description' => $this->getFileDescription($imageDetails),
                'width' => $imageDetails->assets->huge_jpg->width ?? 0,
                'height' => $imageDetails->assets->huge_jpg->height ?? 0,
            ],
            'errors' => $errors
        ];
    }

    /**
     * Récupère les détails d'une image depuis l'API Shutterstock
     * @param string $id
     * @return mixed|null
     * @throws GuzzleException
     */
    protected function getImageDetails(string $id): mixed
    {
        try {
            $client = new Client();
            $response = $client->request('GET', self::IMAGES_ENDPOINT . '/' . $id, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
                ],
            ]);

            return json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch image details: ' . $e->getMessage());
            return null;
        }
    }

    protected function getFileName(mixed $fileData): string
    {
        return $fileData->id ?? '';
    }

    protected function getFileTitle(mixed $fileData): string
    {
        return $fileData->description ?? '';
    }

    protected function getFileDescription(mixed $fileData): string
    {
        if (!$fileData || !isset($fileData->contributor)) {
            return '';
        }

        $contributorName = $fileData->contributor->id ?? '';

        if ($contributorName) {
            return sprintf('Shutterstock #%s', $contributorName);
        }

        return '';
    }

    /**
     * This function is specific to shutterstock.
     * We need to get the current subscription to know which kinds of assets we're allowed to download
     */
    public function getSubscription()
    {
        $client = new Client();
        try {
            $response = $client->request('GET', self::SUBSCRIPTION_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            if (!empty($result->data[0])) {
                // Return the first returned subscription
                $result = $result->data[0]->id;
            }
        } catch (Exception|GuzzleException $e) {
            $this->logger->critical($e->getMessage());
            $result = null;
        }
        return $result;
    }

    /**
     * @param array $params
     * @return SearchResult
     */
    public function search(array $params): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $params['sort'] = 'relevance';
        $params['per_page'] = 20;
        $params['query'] = $params['q'];
        unset($params['q']);

        // Remove unset filters
        $params = array_filter($params, static function ($value) {
            return !empty($value);
        });

        try {
            $client = new Client();

            if (empty($params['collection'])) {
                $response = $client->request('GET', self::SEARCH_ENDPOINT, [
                    'query' => $params,
                    'auth' => [
                        $this->extensionConfiguration['shutterstock_consumer_key'],
                        $this->extensionConfiguration['shutterstock_consumer_secret']
                    ]
                ]);
                $rawResult = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
                if ($rawResult) {
                    $result = $this->formatResults($rawResult, $params);
                }
            } else if ($params['page'] === 1) {
                // There is no pagination when searching through collections
                $result = $this->getImagesFromCollection($params['collection']);
                // Disable all other filters, as we cannot search within a collection
                $result->disabledFilters = array_values(
                    array_filter(
                        array_keys($this->getAvailableFilters()),
                        static function ($filter) {
                            return $filter !== 'collection';
                        }
                    )
                );
                // Also disable the search input
                $result->disabledFilters[] = 'q';
            }
        } catch (ClientException|GuzzleException|JsonException $e) {
            $this->logger->critical($e->getMessage());
            $result->success = false;
            $result->message = $e->getMessage();
        }

        return $result;
    }

    /**
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getImagesFromCollection($collectionId): ?SearchResult
    {
        $client = new Client();
        // First fetch the list of images contained in this collection
        $url = str_replace('{id}', $collectionId, self::COLLECTIONS_CONTENT_ENDPOINT);
        $response = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
            ]
        ]);

        $rawResult = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
        if (!empty($rawResult->data)) {
            // If there were results, fetch the details of those images
            $imageIds = array_map(static function ($item) {
                return $item->id;
            }, $rawResult->data);

            $getParam = implode('&id=', $imageIds) . '&view=minimal';

            $response = $client->request('GET', self::IMAGES_ENDPOINT . '?id=' . $getParam, [
                'auth' => [
                    $this->extensionConfiguration['shutterstock_consumer_key'],
                    $this->extensionConfiguration['shutterstock_consumer_secret']
                ]
            ]);
            $rawResult = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            if ($rawResult) {
                return $this->formatResults($rawResult);
            }
        }
        return null;
    }


    /**
     * Converts the raw API results into a common format
     * @param stdClass $rawData
     * @param array $params
     * @return SearchResult
     */
    public function formatResults(stdClass $rawData, array $params = []): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $result->search = $params;
        $result->page = $rawData->page ?? 1;
        $result->totalCount = $rawData->total_count ?? count($rawData->data);

        foreach ($rawData->data as $item) {
            $resultItem = GeneralUtility::makeInstance(SearchResultItem::class);
            $resultItem->id = $item->id;
            $resultItem->preview = $item->assets->preview->url;
            $result->data[] = $resultItem;
        }
        return $result;
    }

    /**
     * Returns the label of the "Add media" button
     * @return string|null
     */
    public function getAddButtonLabel(): ?string
    {
        return LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:button.add_media', 'shutterstock-connector');
    }

    public function getCategoriesFilter(): array
    {
        $client = new Client();
        try {
            $response = $client->request('GET', self::CATEGORIES_ENDPOINT, [
                'auth' => [
                    $this->extensionConfiguration['shutterstock_consumer_key'],
                    $this->extensionConfiguration['shutterstock_consumer_secret']
                ]
            ]);
            $result = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            $options = [
                [
                    'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.category.I.any', 'shutterstock-connector'),
                    'value' => ''
                ]
            ];
            foreach ($result->data as $category) {
                $options[] = [
                    'label' => $category->name,
                    'value' => $category->id
                ];
            }
        } catch (Exception|GuzzleException) {
            $options = [];
        }
        return $options;
    }

    /**
     * Returns the list of available collections from the shutterstock account
     */
    public function getAvailableCollections(): array
    {
        $options = [];
        try {
            $client = new Client();
            $response = $client->request('GET', self::COLLECTIONS_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
                ]
            ]);
            $result = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            if (!empty($result->data)) {
                $options[] = [
                    'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.collection.I.any', 'shutterstock-connector'),
                    'value' => ''
                ];
                $options = array_merge(
                    $options,
                    array_map(
                        static function ($item) {
                            return [
                                'label' => $item->name . ' (' . $item->total_item_count . ')',
                                'value' => $item->id
                            ];
                        },
                        $result->data)
                );
            }
        } catch (Exception|GuzzleException $e) {
            $this->logger->critical(
                sprintf(
                    'An error occured while fetching collections from Shutterstock. Error: %s',
                    $e->getMessage()
                )
            );
        }
        return $options;
    }

    /**
     * Returns the list of available filters when using the Shutterstock API
     * This array is then JSON encoded to be fed as a data attribute to the "Add media" button
     * @return string[]
     */
    public function getAvailableFilters(): array
    {
        return [
            'collection' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.collection.label', 'shutterstock-connector'),
                'options' => $this->getAvailableCollections()
            ],
            'orientation' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.orientation.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.any',
                            'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.horizontal',
                            'shutterstock-connector'),
                        'value' => 'horizontal'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.vertical',
                            'shutterstock-connector'),
                        'value' => 'vertical'
                    ],
                ]
            ],
            'category' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.category.label', 'shutterstock-connector'),
                'options' => $this->getCategoriesFilter()
            ],
            'color' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.any', 'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.grayscale',
                            'shutterstock-connector'),
                        'value' => 'grayscale'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.blue', 'shutterstock-connector'),
                        'value' => '0000FF'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.fuschia',
                            'shutterstock-connector'),
                        'value' => 'FF00FF'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.green', 'shutterstock-connector'),
                        'value' => '00FF00'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.orange', 'shutterstock-connector'),
                        'value' => 'FFA500'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.purple', 'shutterstock-connector'),
                        'value' => '800080'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.red', 'shutterstock-connector'),
                        'value' => 'FF0000'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.teal', 'shutterstock-connector'),
                        'value' => '008080'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.color.I.yellow', 'shutterstock-connector'),
                        'value' => 'FFFF00'
                    ],
                ]
            ],
            'image_type' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.image_type.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.image_type.I.any',
                            'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.image_type.I.photo',
                            'shutterstock-connector'),
                        'value' => 'photo'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.image_type.I.vector',
                            'shutterstock-connector'),
                        'value' => 'vector'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.image_type.I.illustration',
                            'shutterstock-connector'),
                        'value' => 'illustration'
                    ],
                ]
            ],
            'people_ethnicity' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.any',
                            'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.african',
                            'shutterstock-connector'),
                        'value' => 'african'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.african_american',
                            'shutterstock-connector'),
                        'value' => 'african_american'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.brazilian',
                            'shutterstock-connector'),
                        'value' => 'brazilian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.caucasian',
                            'shutterstock-connector'),
                        'value' => 'caucasian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.chinese',
                            'shutterstock-connector'),
                        'value' => 'chinese'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.east_asian',
                            'shutterstock-connector'),
                        'value' => 'east_asian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.hispanic',
                            'shutterstock-connector'),
                        'value' => 'hispanic'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.japanese',
                            'shutterstock-connector'),
                        'value' => 'japanese'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.middle_eastern',
                            'shutterstock-connector'),
                        'value' => 'middle_eastern'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.native_american',
                            'shutterstock-connector'),
                        'value' => 'native_american'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.pacific_islander',
                            'shutterstock-connector'),
                        'value' => 'pacific_islander'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.south_asian',
                            'shutterstock-connector'),
                        'value' => 'south_asian'
                    ],

                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.southeast_asian',
                            'shutterstock-connector'),
                        'value' => 'southeast_asian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_ethnicity.I.other',
                            'shutterstock-connector'),
                        'value' => 'other'
                    ],
                ]
            ],
            'people_gender' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_gender.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_gender.I.any',
                            'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_gender.I.male',
                            'shutterstock-connector'),
                        'value' => 'male'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_gender.I.female',
                            'shutterstock-connector'),
                        'value' => 'female'
                    ],
                ]
            ],
            'people_age' => [
                'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.label', 'shutterstock-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.any',
                            'shutterstock-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.infants',
                            'shutterstock-connector'),
                        'value' => 'infants'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.children',
                            'shutterstock-connector'),
                        'value' => 'children'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.teenagers',
                            'shutterstock-connector'),
                        'value' => 'teenagers'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.20s',
                            'shutterstock-connector'),
                        'value' => '20s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.30s',
                            'shutterstock-connector'),
                        'value' => '30s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.40s',
                            'shutterstock-connector'),
                        'value' => '40s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.50s',
                            'shutterstock-connector'),
                        'value' => '50s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.60s',
                            'shutterstock-connector'),
                        'value' => '60s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:filter.people_age.I.older',
                            'shutterstock-connector'),
                        'value' => 'older'
                    ],
                ]
            ]
        ];
    }

    /**
     * Returns the markup for the icon of the "Add media" button
     * @return string
     */
    public function getAddButtonIcon(): string
    {
        return '<span class="t3js-icon icon icon-size-small icon-state-default icon-actions-online-media-add" data-identifier="actions-shutterstock-media-add">
                <span class="icon-markup">
                    <svg class="icon-color" role="img"><use xlink:href="/typo3/sysext/core/Resources/Public/Icons/T3Icons/sprites/actions.svg#actions-cloud" /></svg>
                </span>
            </span>';
    }

    /**
     * Returns the additional attributes added to the "Add media button", so they can be used in Javascript later
     * @return array
     */
    public function getAddButtonAttributes(): array
    {
        $buttonLabel = LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:button.add_media', 'shutterstock-connector');
        $submitButtonLabel = LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:button.submit', 'shutterstock-connector');
        $cancelLabel = LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:button.cancel', 'shutterstock-connector');
        $placeholderLabel = LocalizationUtility::translate('LLL:EXT:shutterstock-connector/Resources/Private/Language/locallang.xlf:placeholder.search', 'shutterstock-connector');
        return [
            'title' => $buttonLabel,
            'data-btn-submit' => $submitButtonLabel,
            'data-placeholder' => $placeholderLabel,
            'data-btn-cancel' => $cancelLabel
        ];
    }

}
