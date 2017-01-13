<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Mapping\NodeTypeMappingBuilder;
use Flowpack\ElasticSearch\Domain\Model\Document as ElasticSearchDocument;
use Flowpack\ElasticSearch\Domain\Model\Index;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Service\ContentDimensionCombinator;
use TYPO3\TYPO3CR\Domain\Service\ContextFactory;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;
use TYPO3\TYPO3CR\Search\Indexer\AbstractNodeIndexer;
use TYPO3\TYPO3CR\Search\Indexer\BulkNodeIndexerInterface;

/**
 * Indexer for Content Repository Nodes. Triggered from the NodeIndexingManager.
 *
 * Internally, uses a bulk request.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer implements BulkNodeIndexerInterface
{
    /**
     * Optional postfix for the index, e.g. to have different indexes by timestamp.
     *
     * @var string
     */
    protected $indexNamePostfix = '';

    /**
     * @Flow\Inject
     * @var ElasticSearchClient
     */
    protected $searchClient;

    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilder
     */
    protected $nodeTypeMappingBuilder;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contextFactory;

    /**
     * The current ElasticSearch bulk request, in the format required by http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-bulk.html
     *
     * @var array
     */
    protected $currentBulkRequest = [];

    /**
     * @var boolean
     */
    protected $bulkProcessing = false;

    /**
     * Returns the index name to be used for indexing, with optional indexNamePostfix appended.
     *
     * @return string
     */
    public function getIndexName()
    {
        $indexName = $this->searchClient->getIndexName();
        if (strlen($this->indexNamePostfix) > 0) {
            $indexName .= '-' . $this->indexNamePostfix;
        }

        return $indexName;
    }

    /**
     * Set the postfix for the index name
     *
     * @param string $indexNamePostfix
     * @return void
     */
    public function setIndexNamePostfix($indexNamePostfix)
    {
        $this->indexNamePostfix = $indexNamePostfix;
    }

    /**
     * Return the currently active index to be used for indexing
     *
     * @return Index
     */
    public function getIndex()
    {
        $index = $this->searchClient->findIndex($this->getIndexName());
        $index->setSettingsKey($this->searchClient->getIndexName());

        return $index;
    }

    /**
     * Index this node, and add it to the current bulk request.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspaceName In case this is triggered during publishing, a workspace name will be passed in
     * @return void
     * @throws \TYPO3\TYPO3CR\Search\Exception\IndexingException
     */
    public function indexNode(NodeInterface $node, $targetWorkspaceName = null)
    {
        $indexer = function (NodeInterface $node, $targetWorkspaceName = null) {
            $contextPath = $node->getContextPath();

            if ($this->settings['indexAllWorkspaces'] === false) {
                // we are only supposed to index the live workspace.
                // We need to check the workspace at two occasions; checking the
                // $targetWorkspaceName and the workspace name of the node's context as fallback
                if ($targetWorkspaceName !== null && $targetWorkspaceName !== 'live') {
                    return;
                }

                if ($targetWorkspaceName === null && $node->getContext()->getWorkspaceName() !== 'live') {
                    return;
                }
            }

            if ($targetWorkspaceName !== null) {
                $contextPath = str_replace($node->getContext()->getWorkspace()->getName(), $targetWorkspaceName, $contextPath);
            }

            $documentIdentifier = $this->calculateDocumentIdentifier($node, $targetWorkspaceName);
            $nodeType = $node->getNodeType();

            $mappingType = $this->getIndex()->findType(NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($nodeType));

            if ($this->bulkProcessing === false) {
                // Remove document with the same documentIdentifier but different NodeType, required after NodeType change
                $this->logger->log(sprintf('NodeIndexer (%s): Search and remove duplicate document for node %s (%s) if needed.', $documentIdentifier, $contextPath, $node->getIdentifier()), LOG_DEBUG, null, 'ElasticSearch (CR)');
                $this->getIndex()->request('DELETE', '/_query', [], json_encode([
                    'query' => [
                        'bool' => [
                            'must' => [
                                'ids' => [
                                    'values' => [$documentIdentifier]
                                ]
                            ],
                            'must_not' => [
                                'term' => [
                                    '_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($node->getNodeType()->getName())
                                ]
                            ],
                        ]
                    ]
                ]));
            }

            $logger = $this->logger;
            $fulltextIndexOfNode = [];
            $nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextIndexOfNode, function ($propertyName) use ($logger, $documentIdentifier, $node) {
                $logger->log(sprintf('NodeIndexer (%s) - Property "%s" not indexed because no configuration found, node type %s.', $documentIdentifier, $propertyName, $node->getNodeType()->getName()), LOG_DEBUG, null, 'ElasticSearch (CR)');
            });

            $document = new ElasticSearchDocument($mappingType,
                $nodePropertiesToBeStoredInIndex,
                $documentIdentifier
            );

            $documentData = $document->getData();
            if ($targetWorkspaceName !== null) {
                $documentData['__workspace'] = $targetWorkspaceName;
            }

            $dimensionCombinations = $node->getContext()->getDimensions();
            if (is_array($dimensionCombinations)) {
                $documentData['__dimensionCombinations'] = $dimensionCombinations;
                $documentData['__dimensionCombinationHash'] = md5(json_encode($dimensionCombinations));
            }

            if ($this->isFulltextEnabled($node)) {
                if ($this->isFulltextRoot($node)) {
                    // for fulltext root documents, we need to preserve the "__fulltext" field. That's why we use the
                    // "update" API instead of the "index" API, with a custom script internally; as we
                    // shall not delete the "__fulltext" part of the document if it has any.
                    $this->currentBulkRequest[] = [
                        [
                            'update' => [
                                '_type' => $document->getType()->getName(),
                                '_id' => $document->getId()
                            ]
                        ],
                        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-update.html
                        [
                            'script' => '
                            fulltext = (ctx._source.containsKey("__fulltext") ? ctx._source.__fulltext : new LinkedHashMap());
                            fulltextParts = (ctx._source.containsKey("__fulltextParts") ? ctx._source.__fulltextParts : new LinkedHashMap());
                            ctx._source = newData;
                            ctx._source.__fulltext = fulltext;
                            ctx._source.__fulltextParts = fulltextParts
                            ',
                            'params' => [
                                'newData' => $documentData
                            ],
                            'upsert' => $documentData,
                            'lang' => 'groovy'
                        ]
                    ];
                } else {
                    // non-fulltext-root documents can be indexed as-they-are
                    $this->currentBulkRequest[] = [
                        [
                            'index' => [
                                '_type' => $document->getType()->getName(),
                                '_id' => $document->getId()
                            ]
                        ],
                        $documentData
                    ];
                }

                $this->updateFulltext($node, $fulltextIndexOfNode, $targetWorkspaceName);
            }

            $this->logger->log(sprintf('NodeIndexer (%s): Indexed node %s.', $documentIdentifier, $contextPath), LOG_DEBUG, null, 'ElasticSearch (CR)');
        };

        $handleNode = function (NodeInterface $node, \TYPO3\TYPO3CR\Domain\Service\Context $context) use ($targetWorkspaceName, $indexer) {
            $nodeFromContext = $context->getNodeByIdentifier($node->getIdentifier());
            if ($nodeFromContext instanceof NodeInterface) {
                $indexer($nodeFromContext, $targetWorkspaceName);
            } else {
                $documentIdentifier = $this->calculateDocumentIdentifier($node, $targetWorkspaceName);
                if ($node->isRemoved()) {
                    $this->removeNode($node, $context->getWorkspaceName());
                    $this->logger->log(sprintf('NodeIndexer (%s): Removed node with identifier %s, no longer in workspace %s', $documentIdentifier, $node->getIdentifier(), $context->getWorkspaceName()), LOG_DEBUG, null, 'ElasticSearch (CR)');
                } else {
                    $this->logger->log(sprintf('NodeIndexer (%s): Could not index node with identifier %s, not found in workspace %s', $documentIdentifier, $node->getIdentifier(), $context->getWorkspaceName()), LOG_DEBUG, null, 'ElasticSearch (CR)');
                }
            }
        };

        $workspaceName = $targetWorkspaceName ?: $node->getContext()->getWorkspaceName();
        $dimensionCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        if ($dimensionCombinations !== []) {
            foreach ($dimensionCombinations as $combination) {
                $context = $this->contextFactory->create(['workspaceName' => $workspaceName, 'dimensions' => $combination, 'invisibleContentShown' => true]);
                $handleNode($node, $context);
            }
        } else {
            $context = $this->contextFactory->create(['workspaceName' => $workspaceName, 'invisibleContentShown' => true]);
            $handleNode($node, $context);
        }
    }

    /**
     * Returns a stable identifier for the Elasticsearch document representing the node
     *
     * @param NodeInterface $node
     * @param string $targetWorkspaceName
     * @return string
     */
    protected function calculateDocumentIdentifier(NodeInterface $node, $targetWorkspaceName = null)
    {
        $contextPath = $node->getContextPath();

        if ($targetWorkspaceName !== null) {
            $contextPath = str_replace($node->getContext()->getWorkspace()->getName(), $targetWorkspaceName, $contextPath);
        }

        return sha1($contextPath);
    }

    /**
     *
     *
     * @param NodeInterface $node
     * @param array $fulltextIndexOfNode
     * @param $targetWorkspaceName
     * @return void
     */
    protected function updateFulltext(NodeInterface $node, array $fulltextIndexOfNode, $targetWorkspaceName)
    {
        $closestFulltextNode = $node;
        while (!$this->isFulltextRoot($closestFulltextNode)) {
            $closestFulltextNode = $closestFulltextNode->getParent();
            if ($closestFulltextNode === null) {
                // root of hierarchy, no fulltext root found anymore, abort silently...
                $this->logger->log(sprintf('NodeIndexer: No fulltext root found for node %s (%)', $node->getPath(), $node->getIdentifier()), LOG_WARNING, null, 'ElasticSearch (CR)');

                return;
            }
        }

        $closestFulltextNodeContextPath = $closestFulltextNode->getContextPath();
        if ($targetWorkspaceName !== null) {
            $closestFulltextNodeContextPath = str_replace($node->getContext()->getWorkspace()->getName(), $targetWorkspaceName, $closestFulltextNodeContextPath);
        }
        $closestFulltextNodeDocumentIdentifier = sha1($closestFulltextNodeContextPath);

        if ($closestFulltextNode->isRemoved()) {
            // fulltext root is removed, abort silently...
            $this->logger->log(sprintf('NodeIndexer (%s): Fulltext root found for %s (%s) not updated, it is removed', $closestFulltextNodeDocumentIdentifier, $node->getPath(), $node->getIdentifier()), LOG_DEBUG, null, 'ElasticSearch (CR)');
            return;
        }

        $this->currentBulkRequest[] = [
            [
                'update' => [
                    '_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($closestFulltextNode->getNodeType()->getName()),
                    '_id' => $closestFulltextNodeDocumentIdentifier
                ]
            ],
            // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-update.html
            [
                // first, update the __fulltextParts, then re-generate the __fulltext from all __fulltextParts
                'script' => '
                    if (!ctx._source.containsKey("__fulltextParts")) {
                        ctx._source.__fulltextParts = new LinkedHashMap();
                    }

                    if (nodeIsRemoved || nodeIsHidden || fulltext.size() == 0) {
                        if (ctx._source.__fulltextParts.containsKey(identifier)) {
                            ctx._source.__fulltextParts.remove(identifier);
                        }
                    } else {
                        ctx._source.__fulltextParts[identifier] = fulltext;
                    }
                    ctx._source.__fulltext = new LinkedHashMap();

                    Iterator<LinkedHashMap.Entry<String, LinkedHashMap>> fulltextByNode = ctx._source.__fulltextParts.entrySet().iterator();
                    for (fulltextByNode; fulltextByNode.hasNext();) {
                        Iterator<LinkedHashMap.Entry<String, String>> elementIterator = fulltextByNode.next().getValue().entrySet().iterator();
                        for (elementIterator; elementIterator.hasNext();) {
                            Map.Entry<String, String> element = elementIterator.next();
                            String value;

                            if (ctx._source.__fulltext.containsKey(element.key)) {
                                value = ctx._source.__fulltext[element.key] + " " + element.value.trim();
                            } else {
                                value = element.value.trim();
                            }

                            ctx._source.__fulltext[element.key] = value;
                        }
                    }
                ',
                'params' => [
                    'identifier' => $node->getIdentifier(),
                    'nodeIsRemoved' => $node->isRemoved(),
                    'nodeIsHidden' => $node->isHidden(),
                    'fulltext' => $fulltextIndexOfNode
                ],
                'upsert' => [
                    '__fulltext' => $fulltextIndexOfNode,
                    '__fulltextParts' => [
                        $node->getIdentifier() => $fulltextIndexOfNode
                    ]
                ],
                'lang' => 'groovy'
            ]
        ];

        $this->logger->log(sprintf('NodeIndexer (%s): Updated fulltext index for %s (%s)', $closestFulltextNodeDocumentIdentifier, $closestFulltextNodeContextPath, $closestFulltextNode->getIdentifier()), LOG_DEBUG, null, 'ElasticSearch (CR)');
    }

    /**
     * Whether the node is configured as fulltext root.
     *
     * @param NodeInterface $node
     * @return boolean
     */
    protected function isFulltextRoot(NodeInterface $node)
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $elasticSearchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($elasticSearchSettingsForNode['fulltext']['isRoot']) && $elasticSearchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule node removal into the current bulk request.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspaceName
     * @return string
     */
    public function removeNode(NodeInterface $node, $targetWorkspaceName = null)
    {
        if ($this->settings['indexAllWorkspaces'] === false) {
            // we are only supposed to index the live workspace.
            // We need to check the workspace at two occasions; checking the
            // $targetWorkspaceName and the workspace name of the node's context as fallback
            if ($targetWorkspaceName !== null && $targetWorkspaceName !== 'live') {
                return;
            }

            if ($targetWorkspaceName === null && $node->getContext()->getWorkspaceName() !== 'live') {
                return;
            }
        }

        $documentIdentifier = $this->calculateDocumentIdentifier($node, $targetWorkspaceName);

        $this->currentBulkRequest[] = [
            [
                'delete' => [
                    '_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($node->getNodeType()),
                    '_id' => $documentIdentifier
                ]
            ]
        ];

        $this->updateFulltext($node, [], $targetWorkspaceName);

        $this->logger->log(sprintf('NodeIndexer (%s): Removed node %s (%s) from index.', $documentIdentifier, $node->getContextPath(), $node->getIdentifier()), LOG_DEBUG, null, 'ElasticSearch (CR)');
    }

    /**
     * Perform the current bulk request
     *
     * @return void
     */
    public function flush()
    {
        if (count($this->currentBulkRequest) === 0) {
            return;
        }

        $content = '';
        foreach ($this->currentBulkRequest as $bulkRequestTuple) {
            $tupleAsJson = '';
            foreach ($bulkRequestTuple as $bulkRequestItem) {
                $itemAsJson = json_encode($bulkRequestItem);
                if ($itemAsJson === false) {
                    $this->logger->log('NodeIndexer: Bulk request item could not be encoded as JSON - ' . json_last_error_msg(), LOG_ERR, $bulkRequestItem, 'ElasticSearch (CR)');
                    continue 2;
                }
                $tupleAsJson .= $itemAsJson . chr(10);
            }
            $content .= $tupleAsJson;
        }

        if ($content !== '') {
            $responseAsLines = $this->getIndex()->request('POST', '/_bulk', [], $content)->getOriginalResponse()->getContent();
            foreach (explode(chr(10), $responseAsLines) as $responseLine) {
                $response = json_decode($responseLine);
                if (!is_object($response) || (isset($response->errors) && $response->errors !== false)) {
                    $this->logger->log('NodeIndexer: ' . $responseLine, LOG_ERR, null, 'ElasticSearch (CR)');
                }
            }
        }

        $this->currentBulkRequest = [];
    }

    /**
     * Update the index alias
     *
     * @return void
     * @throws Exception
     * @throws \Flowpack\ElasticSearch\Transfer\Exception\ApiException
     * @throws \Exception
     */
    public function updateIndexAlias()
    {
        $aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name
        if ($this->getIndexName() === $aliasName) {
            throw new Exception('UpdateIndexAlias is only allowed to be called when $this->setIndexNamePostfix has been created.', 1383649061);
        }

        if (!$this->getIndex()->exists()) {
            throw new Exception('The target index for updateIndexAlias does not exist. This shall never happen.', 1383649125);
        }

        $aliasActions = [];
        try {
            $response = $this->searchClient->request('GET', '/_alias/' . $aliasName);
            if ($response->getStatusCode() !== 200) {
                throw new Exception('The alias "' . $aliasName . '" was not found with some unexpected error... (return code: ' . $response->getStatusCode() . ')', 1383650137);
            }

            $indexNames = array_keys($response->getTreatedContent());

            if ($indexNames === []) {
                // if there is an actual index with the name we want to use as alias, remove it now
                $response = $this->searchClient->request('HEAD', '/' . $aliasName);
                if ($response->getStatusCode() === 200) {
                    $response = $this->searchClient->request('DELETE', '/' . $aliasName);
                    if ($response->getStatusCode() !== 200) {
                        throw new Exception('The index "' . $aliasName . '" could not be removed to be replaced by an alias. (return code: ' . $response->getStatusCode() . ')', 1395419177);
                    }
                }
            } else {
                foreach ($indexNames as $indexName) {
                    $aliasActions[] = [
                        'remove' => [
                            'index' => $indexName,
                            'alias' => $aliasName
                        ]
                    ];
                }
            }
        } catch (\Flowpack\ElasticSearch\Transfer\Exception\ApiException $exception) {
            // in case of 404, do not throw an error...
            if ($exception->getResponse()->getStatusCode() !== 404) {
                throw $exception;
            }
        }

        $aliasActions[] = [
            'add' => [
                'index' => $this->getIndexName(),
                'alias' => $aliasName
            ]
        ];

        $this->searchClient->request('POST', '/_aliases', [], \json_encode(['actions' => $aliasActions]));
    }

    /**
     * Remove old indices which are not active anymore (remember, each bulk index creates a new index from scratch,
     * making the "old" index a stale one).
     *
     * @return array<string> a list of index names which were removed
     */
    public function removeOldIndices()
    {
        $aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name

        $currentlyLiveIndices = array_keys($this->searchClient->request('GET', '/_alias/' . $aliasName)->getTreatedContent());

        $indexStatus = $this->searchClient->request('GET', '/_status')->getTreatedContent();
        $allIndices = array_keys($indexStatus['indices']);

        $indicesToBeRemoved = [];

        foreach ($allIndices as $indexName) {
            if (strpos($indexName, $aliasName . '-') !== 0) {
                // filter out all indices not starting with the alias-name, as they are unrelated to our application
                continue;
            }

            if (array_search($indexName, $currentlyLiveIndices) !== false) {
                // skip the currently live index names from deletion
                continue;
            }

            $indicesToBeRemoved[] = $indexName;
        }

        if (count($indicesToBeRemoved) > 0) {
            $this->searchClient->request('DELETE', '/' . implode(',', $indicesToBeRemoved) . '/');
        }

        return $indicesToBeRemoved;
    }

    /**
     * Perform indexing without checking about duplication document
     *
     * This is used during bulk indexing to improve performance
     *
     * @param callable $callback
     * @throws \Exception
     */
    public function withBulkProcessing(callable $callback)
    {
        $bulkProcessing = $this->bulkProcessing;
        $this->bulkProcessing = true;
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $callback->__invoke();
        } catch (\Exception $exception) {
            $this->bulkProcessing = $bulkProcessing;
            throw $exception;
        }
        $this->bulkProcessing = $bulkProcessing;
    }
}
