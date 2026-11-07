<?php
/**
 * wallee SDK
 *
 * This library allows to interact with the wallee payment service.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace Wallee\Sdk\Service;

use Wallee\Sdk\ApiClient;
use Wallee\Sdk\ApiException;
use Wallee\Sdk\ApiResponse;
use Wallee\Sdk\Http\HttpRequest;
use Wallee\Sdk\ObjectSerializer;

/**
 * TransactionInvoiceService service
 *
 * @category Class
 * @package  Wallee\Sdk
 * @author   wallee AG
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache License v2
 */
class TransactionInvoiceService {

	/**
	 * The API client instance.
	 *
	 * @var ApiClient
	 */
	private $apiClient;

	/**
	 * Constructor.
	 *
	 * @param ApiClient $apiClient the api client
	 */
	public function __construct(ApiClient $apiClient) {
		if (is_null($apiClient)) {
			throw new \InvalidArgumentException('The api client is required.');
		}

		$this->apiClient = $apiClient;
	}

	/**
	 * Returns the API client instance.
	 *
	 * @return ApiClient
	 */
	public function getApiClient() {
		return $this->apiClient;
	}


	/**
	 * Operation count
	 *
	 * Count
	 *
	 * @param int $space_id  (required)
	 * @param \Wallee\Sdk\Model\EntityQueryFilter $filter The filter which restricts the entities which are used to calculate the count. (optional)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return int
	 */
	public function count($space_id, $filter = null) {
		return $this->countWithHttpInfo($space_id, $filter)->getData();
	}

	/**
	 * Operation countWithHttpInfo
	 *
	 * Count
     
     *
	 * @param int $space_id  (required)
	 * @param \Wallee\Sdk\Model\EntityQueryFilter $filter The filter which restricts the entities which are used to calculate the count. (optional)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function countWithHttpInfo($space_id, $filter = null) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling count');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['application/json;charset=utf-8']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}

		
		$resourcePath = '/transaction-invoice/count';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		$tempBody = null;
		if (isset($filter)) {
			$tempBody = $filter;
		}

		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'POST',
				$queryParams,
				$httpBody,
				$headerParams,
				'int',
				'/transaction-invoice/count'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), 'int', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        'int',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation getInvoiceDocument
	 *
	 * getInvoiceDocument
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice to get the document for. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\RenderedDocument
	 */
	public function getInvoiceDocument($space_id, $id) {
		return $this->getInvoiceDocumentWithHttpInfo($space_id, $id)->getData();
	}

	/**
	 * Operation getInvoiceDocumentWithHttpInfo
	 *
	 * getInvoiceDocument
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice to get the document for. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function getInvoiceDocumentWithHttpInfo($space_id, $id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling getInvoiceDocument');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling getInvoiceDocument');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['*/*']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/getInvoiceDocument';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'GET',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\RenderedDocument',
				'/transaction-invoice/getInvoiceDocument'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\RenderedDocument', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\RenderedDocument',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation getInvoiceDocumentWithTargetMediaType
	 *
	 * getInvoiceDocumentWithTargetMediaType
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice to get the document for. (required)
	 * @param int $target_media_type_id The id of the target media type for which the invoice should be generated for. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\RenderedDocument
	 */
	public function getInvoiceDocumentWithTargetMediaType($space_id, $id, $target_media_type_id) {
		return $this->getInvoiceDocumentWithTargetMediaTypeWithHttpInfo($space_id, $id, $target_media_type_id)->getData();
	}

	/**
	 * Operation getInvoiceDocumentWithTargetMediaTypeWithHttpInfo
	 *
	 * getInvoiceDocumentWithTargetMediaType
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice to get the document for. (required)
	 * @param int $target_media_type_id The id of the target media type for which the invoice should be generated for. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function getInvoiceDocumentWithTargetMediaTypeWithHttpInfo($space_id, $id, $target_media_type_id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling getInvoiceDocumentWithTargetMediaType');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling getInvoiceDocumentWithTargetMediaType');
		}
		
		if (is_null($target_media_type_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $target_media_type_id when calling getInvoiceDocumentWithTargetMediaType');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['*/*']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}
		if (!is_null($target_media_type_id)) {
			$queryParams['targetMediaTypeId'] = $this->apiClient->getSerializer()->toQueryValue($target_media_type_id);
		}

		
		$resourcePath = '/transaction-invoice/getInvoiceDocumentWithTargetMediaType';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'GET',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\RenderedDocument',
				'/transaction-invoice/getInvoiceDocumentWithTargetMediaType'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\RenderedDocument', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\RenderedDocument',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation isReplacementPossible
	 *
	 * isReplacementPossible
	 *
	 * @param int $space_id  (required)
	 * @param int $id The invoice which should be checked if a replacement is possible. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return bool
	 */
	public function isReplacementPossible($space_id, $id) {
		return $this->isReplacementPossibleWithHttpInfo($space_id, $id)->getData();
	}

	/**
	 * Operation isReplacementPossibleWithHttpInfo
	 *
	 * isReplacementPossible
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The invoice which should be checked if a replacement is possible. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function isReplacementPossibleWithHttpInfo($space_id, $id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling isReplacementPossible');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling isReplacementPossible');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['*/*']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/isReplacementPossible';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'GET',
				$queryParams,
				$httpBody,
				$headerParams,
				'bool',
				'/transaction-invoice/isReplacementPossible'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), 'bool', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        'bool',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation markAsDerecognized
	 *
	 * Mark as Derecognized
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice which should be marked as derecognized. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\TransactionInvoice
	 */
	public function markAsDerecognized($space_id, $id) {
		return $this->markAsDerecognizedWithHttpInfo($space_id, $id)->getData();
	}

	/**
	 * Operation markAsDerecognizedWithHttpInfo
	 *
	 * Mark as Derecognized
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice which should be marked as derecognized. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function markAsDerecognizedWithHttpInfo($space_id, $id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling markAsDerecognized');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling markAsDerecognized');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['application/json;charset=utf-8']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/markAsDerecognized';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'POST',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\TransactionInvoice',
				'/transaction-invoice/markAsDerecognized'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\TransactionInvoice', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\TransactionInvoice',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation markAsPaid
	 *
	 * Mark as Paid
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice which should be marked as paid. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\TransactionInvoice
	 */
	public function markAsPaid($space_id, $id) {
		return $this->markAsPaidWithHttpInfo($space_id, $id)->getData();
	}

	/**
	 * Operation markAsPaidWithHttpInfo
	 *
	 * Mark as Paid
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoice which should be marked as paid. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function markAsPaidWithHttpInfo($space_id, $id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling markAsPaid');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling markAsPaid');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['application/json;charset=utf-8']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/markAsPaid';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'POST',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\TransactionInvoice',
				'/transaction-invoice/markAsPaid'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\TransactionInvoice', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\TransactionInvoice',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation read
	 *
	 * Read
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoices which should be returned. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\TransactionInvoice
	 */
	public function read($space_id, $id) {
		return $this->readWithHttpInfo($space_id, $id)->getData();
	}

	/**
	 * Operation readWithHttpInfo
	 *
	 * Read
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoices which should be returned. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function readWithHttpInfo($space_id, $id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling read');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling read');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['*/*']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/read';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'GET',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\TransactionInvoice',
				'/transaction-invoice/read'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\TransactionInvoice', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\TransactionInvoice',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation replace
	 *
	 * replace
	 *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoices which should be replaced. (required)
	 * @param \Wallee\Sdk\Model\TransactionInvoiceReplacement $replacement  (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\TransactionInvoice
	 */
	public function replace($space_id, $id, $replacement) {
		return $this->replaceWithHttpInfo($space_id, $id, $replacement)->getData();
	}

	/**
	 * Operation replaceWithHttpInfo
	 *
	 * replace
     
     *
	 * @param int $space_id  (required)
	 * @param int $id The id of the transaction invoices which should be replaced. (required)
	 * @param \Wallee\Sdk\Model\TransactionInvoiceReplacement $replacement  (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function replaceWithHttpInfo($space_id, $id, $replacement) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling replace');
		}
		
		if (is_null($id)) {
			throw new \InvalidArgumentException('Missing the required parameter $id when calling replace');
		}
		
		if (is_null($replacement)) {
			throw new \InvalidArgumentException('Missing the required parameter $replacement when calling replace');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['application/json;charset=utf-8']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}
		if (!is_null($id)) {
			$queryParams['id'] = $this->apiClient->getSerializer()->toQueryValue($id);
		}

		
		$resourcePath = '/transaction-invoice/replace';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		$tempBody = null;
		if (isset($replacement)) {
			$tempBody = $replacement;
		}

		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'POST',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\TransactionInvoice',
				'/transaction-invoice/replace'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\TransactionInvoice', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\TransactionInvoice',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}

	/**
	 * Operation search
	 *
	 * Search
	 *
	 * @param int $space_id  (required)
	 * @param \Wallee\Sdk\Model\EntityQuery $query The query restricts the transaction invoices which are returned by the search. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\TransactionInvoice[]
	 */
	public function search($space_id, $query) {
		return $this->searchWithHttpInfo($space_id, $query)->getData();
	}

	/**
	 * Operation searchWithHttpInfo
	 *
	 * Search
     
     *
	 * @param int $space_id  (required)
	 * @param \Wallee\Sdk\Model\EntityQuery $query The query restricts the transaction invoices which are returned by the search. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function searchWithHttpInfo($space_id, $query) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling search');
		}
		
		if (is_null($query)) {
			throw new \InvalidArgumentException('Missing the required parameter $query when calling search');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType(['application/json;charset=utf-8']);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}

		
		$resourcePath = '/transaction-invoice/search';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		$tempBody = null;
		if (isset($query)) {
			$tempBody = $query;
		}

		
		$httpBody = '';
		if (isset($tempBody)) {
			$httpBody = $tempBody; 
		} elseif (!empty($formParams)) {
			$httpBody = $formParams; 
		}
		
		try {
			$response = $this->apiClient->callApi(
				$resourcePath,
				'POST',
				$queryParams,
				$httpBody,
				$headerParams,
				'\Wallee\Sdk\Model\TransactionInvoice[]',
				'/transaction-invoice/search'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\TransactionInvoice[]', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\TransactionInvoice[]',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 442:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ClientError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
                case 542:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\ServerError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                break;
			}
			throw $e;
		}
	}


}
