<?php

namespace Ideative\IdShutterstockConnector\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Ideative\IdStockPictures\ConnectorInterface;
use Ideative\IdStockPictures\Domain\Model\SearchResult;
use Ideative\IdStockPictures\Domain\Model\SearchResultItem;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ShutterstockConnector implements ConnectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * URL of the Shutterstock Search API
     */
    const SEARCH_ENDPOINT = 'https://api.shutterstock.com/v2/images/search';

    /**
     * URL of the Shutterstock Subscription API
     * This allows us to retrieve the current subscriptions to know which kind of assets we're allowed to download in HD
     */
    const SUBSCRIPTION_ENDPOINT = 'https://api.shutterstock.com/v2/user/subscriptions';

    /**
     * URL of the Shutterstock Licencing API
     * This allows us to download HD versions of assets
     */
    const LICENSING_ENDPOINT = 'https://api.shutterstock.com/v2/images/licenses';

    /**
     * URL of the Shutterstock Licencing API
     * This allows us to download HD versions of assets
     */
    const CATEGORIES_ENDPOINT = 'https://api.shutterstock.com/v2/images/categories';

    /**
     * URL of the Shutterstock Collections API
     * This allows us to list all of the user's collections
     */
    const COLLECTIONS_ENDPOINT = 'https://api.shutterstock.com/v2/images/collections';

    /**
     * URL of the Shutterstock Collections content API
     * This allows us to fetch the images contained in a selected collection
     */
    const COLLECTIONS_CONTENT_ENDPOINT = 'https://api.shutterstock.com/v2/images/collections/{id}/items';

    /**
     * URL of the Shutterstock Images API
     * This allows us to fetch the details of a series of images, based on their ID
     */
    const IMAGES_ENDPOINT = 'https://api.shutterstock.com/v2/images';

    /**
     * @var array
     */
    protected $extensionConfiguration;

    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('id_shutterstock_connector');
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param string $id
     * @return string|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFileUrlAndExtension(string $id): array
    {
        $subscriptionId = $this->getSubscription();
        $url = null;
        $extension = null;
        $result = null;
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
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage());
            }
        }
        $errors = [];
        if ($result->errors) {
            foreach ($result->errors as $error) {
                $errors[] = $error->message;
            }
        }

        return [
            'url' => $url,
            'extension' => $extension,
            'errors' => $errors
        ];
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
            $result = json_decode($response->getBody()->getContents());
            if (!empty($result->data[0])) {
                // Return the first returned subscription
                $result = $result->data[0]->id;
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
            $result = null;
        }
        return $result;
    }

    /**
     * @param array $params
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
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
        $params = array_filter($params, function ($value) {
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
                $rawResult = json_decode($response->getBody()->getContents());
                if ($rawResult) {
                    $result = $this->formatResults($rawResult, $params);
                }
            } else {
                if ($params['page'] == 1) {
                    // There is no pagination when searching through collections
                    $result = $this->getImagesFromCollection($params['collection']);
                    // Disable all other filters, as we cannot search within a collection
                    $result->disabledFilters = array_values(
                        array_filter(
                            array_keys($this->getAvailableFilters()),
                            function ($filter) {
                                return $filter !== 'collection';
                            }
                        )
                    );
                    // Also disable the search input
                    $result->disabledFilters[] = 'q';
                }
            }
        } catch (ClientException $e) {
            $this->logger->critical($e->getMessage());
            $result->success = false;
            $result->message = $e->getMessage();
        }

        return $result;
    }

    public function getImagesFromCollection($collectionId)
    {
        $client = new Client();
        // First fetch the list of images contained in this collection
        $url = str_replace('{id}', $collectionId, self::COLLECTIONS_CONTENT_ENDPOINT);
        $response = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
            ]
        ]);

        $rawResult = json_decode($response->getBody()->getContents());
        if (!empty($rawResult->data)) {
            // If there were results, fetch the details of those images
            $imageIds = array_map(function ($item) {
                return $item->id;
            }, $rawResult->data);

            $getParam = implode('&id=', $imageIds) . '&view=minimal';

            $response = $client->request('GET', self::IMAGES_ENDPOINT . '?id=' . $getParam, [
                'auth' => [
                    $this->extensionConfiguration['shutterstock_consumer_key'],
                    $this->extensionConfiguration['shutterstock_consumer_secret']
                ]
            ]);
            $rawResult = json_decode($response->getBody()->getContents());
            if ($rawResult) {
                return $this->formatResults($rawResult);
            }
        }
        return null;
    }


    /**
     * Converts the raw API results into a common format
     * @param array $rawData
     * @param array $params
     */
    public function formatResults($rawData, $params = [])
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
        return LocalizationUtility::translate('button.add_media', 'id_shutterstock_connector');
    }

    public function getCategoriesFilter()
    {
        $client = new Client();
        try {
            $response = $client->request('GET', self::CATEGORIES_ENDPOINT, [
                'auth' => [
                    $this->extensionConfiguration['shutterstock_consumer_key'],
                    $this->extensionConfiguration['shutterstock_consumer_secret']
                ]
            ]);
            $result = json_decode($response->getBody()->getContents());
            $options = [
                [
                    'label' => LocalizationUtility::translate('filter.category.I.any', 'id_shutterstock_connector'),
                    'value' => ''
                ]
            ];
            foreach ($result->data as $category) {
                $options[] = [
                    'label' => $category->name,
                    'value' => $category->id
                ];
            }
        } catch (\Exception $e) {
            $options = [];
        }
        return $options;
    }

    /**
     * Returns the list of available collections from the shutterstock account
     */
    public function getAvailableCollections()
    {
        $options = [];
        try {
            $client = new Client();
            $response = $client->request('GET', self::COLLECTIONS_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->extensionConfiguration['shutterstock_token'],
                ]
            ]);
            $result = json_decode($response->getBody()->getContents());
            if (!empty($result->data)) {
                $options[] = [
                    'label' => LocalizationUtility::translate('filter.collection.I.any', 'id_shutterstock_connector'),
                    'value' => ''
                ];
                $options = array_merge(
                    $options,
                    array_map(
                        function ($item) {
                            return [
                                'label' => $item->name . ' (' . $item->total_item_count . ')',
                                'value' => $item->id
                            ];
                        },
                        $result->data)
                );
            }
        } catch (\Exception $e) {
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
                'label' => LocalizationUtility::translate('filter.collection.label', 'id_shutterstock_connector'),
                'options' => $this->getAvailableCollections()
            ],
            'orientation' => [
                'label' => LocalizationUtility::translate('filter.orientation.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.any',
                            'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.horizontal',
                            'id_shutterstock_connector'),
                        'value' => 'horizontal'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.vertical',
                            'id_shutterstock_connector'),
                        'value' => 'vertical'
                    ],
                ]
            ],
            'category' => [
                'label' => LocalizationUtility::translate('filter.category.label', 'id_shutterstock_connector'),
                'options' => $this->getCategoriesFilter()
            ],
            'color' => [
                'label' => LocalizationUtility::translate('filter.color.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.any', 'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.grayscale',
                            'id_shutterstock_connector'),
                        'value' => 'grayscale'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.blue', 'id_shutterstock_connector'),
                        'value' => '0000FF'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.fuschia',
                            'id_shutterstock_connector'),
                        'value' => 'FF00FF'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.green', 'id_shutterstock_connector'),
                        'value' => '00FF00'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.orange', 'id_shutterstock_connector'),
                        'value' => 'FFA500'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.purple', 'id_shutterstock_connector'),
                        'value' => '800080'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.red', 'id_shutterstock_connector'),
                        'value' => 'FF0000'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.teal', 'id_shutterstock_connector'),
                        'value' => '008080'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.yellow', 'id_shutterstock_connector'),
                        'value' => 'FFFF00'
                    ],
                ]
            ],
            'image_type' => [
                'label' => LocalizationUtility::translate('filter.image_type.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.image_type.I.any',
                            'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.image_type.I.photo',
                            'id_shutterstock_connector'),
                        'value' => 'photo'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.image_type.I.vector',
                            'id_shutterstock_connector'),
                        'value' => 'vector'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.image_type.I.illustration',
                            'id_shutterstock_connector'),
                        'value' => 'illustration'
                    ],
                ]
            ],
            'people_ethnicity' => [
                'label' => LocalizationUtility::translate('filter.people_ethnicity.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.any',
                            'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.african',
                            'id_shutterstock_connector'),
                        'value' => 'african'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.african_american',
                            'id_shutterstock_connector'),
                        'value' => 'african_american'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.brazilian',
                            'id_shutterstock_connector'),
                        'value' => 'brazilian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.caucasian',
                            'id_shutterstock_connector'),
                        'value' => 'caucasian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.chinese',
                            'id_shutterstock_connector'),
                        'value' => 'chinese'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.east_asian',
                            'id_shutterstock_connector'),
                        'value' => 'east_asian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.hispanic',
                            'id_shutterstock_connector'),
                        'value' => 'hispanic'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.japanese',
                            'id_shutterstock_connector'),
                        'value' => 'japanese'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.middle_eastern',
                            'id_shutterstock_connector'),
                        'value' => 'middle_eastern'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.native_american',
                            'id_shutterstock_connector'),
                        'value' => 'native_american'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.pacific_islander',
                            'id_shutterstock_connector'),
                        'value' => 'pacific_islander'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.south_asian',
                            'id_shutterstock_connector'),
                        'value' => 'south_asian'
                    ],

                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.southeast_asian',
                            'id_shutterstock_connector'),
                        'value' => 'southeast_asian'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_ethnicity.I.other',
                            'id_shutterstock_connector'),
                        'value' => 'other'
                    ],
                ]
            ],
            'people_gender' => [
                'label' => LocalizationUtility::translate('filter.people_gender.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.people_gender.I.any',
                            'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_gender.I.male',
                            'id_shutterstock_connector'),
                        'value' => 'male'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_gender.I.female',
                            'id_shutterstock_connector'),
                        'value' => 'female'
                    ],
                ]
            ],
            'people_age' => [
                'label' => LocalizationUtility::translate('filter.people_age.label', 'id_shutterstock_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.any',
                            'id_shutterstock_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.infants',
                            'id_shutterstock_connector'),
                        'value' => 'infants'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.children',
                            'id_shutterstock_connector'),
                        'value' => 'children'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.teenagers',
                            'id_shutterstock_connector'),
                        'value' => 'teenagers'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.20s',
                            'id_shutterstock_connector'),
                        'value' => '20s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.30s',
                            'id_shutterstock_connector'),
                        'value' => '30s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.40s',
                            'id_shutterstock_connector'),
                        'value' => '40s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.50s',
                            'id_shutterstock_connector'),
                        'value' => '50s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.60s',
                            'id_shutterstock_connector'),
                        'value' => '60s'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.people_age.I.older',
                            'id_shutterstock_connector'),
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
        $buttonLabel = LocalizationUtility::translate('button.add_media', 'id_shutterstock_connector');
        $submitButtonLabel = LocalizationUtility::translate('button.submit', 'id_shutterstock_connector');
        $cancelLabel = LocalizationUtility::translate('button.cancel', 'id_shutterstock_connector');
        $placeholderLabel = LocalizationUtility::translate('placeholder.search', 'id_shutterstock_connector');
        return [
            'title' => $buttonLabel,
            'data-btn-submit' => $submitButtonLabel,
            'data-placeholder' => $placeholderLabel,
            'data-btn-cancel' => $cancelLabel
        ];
    }

}