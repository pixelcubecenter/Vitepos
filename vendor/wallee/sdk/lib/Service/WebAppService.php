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
 * WebAppService service
 *
 * @category Class
 * @package  Wallee\Sdk
 * @author   wallee AG
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache License v2
 */
class WebAppService {

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
	 * Operation checkInstallation
	 *
	 * Check Installation
	 *
	 * @param int $space_id This parameter identifies the space which should be checked if the web app is installed. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return bool
	 */
	public function checkInstallation($space_id) {
		return $this->checkInstallationWithHttpInfo($space_id)->getData();
	}

	/**
	 * Operation checkInstallationWithHttpInfo
	 *
	 * Check Installation
     
     *
	 * @param int $space_id This parameter identifies the space which should be checked if the web app is installed. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function checkInstallationWithHttpInfo($space_id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling checkInstallation');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType([]);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}

		
		$resourcePath = '/web-app/check-installation';
		
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
				'/web-app/check-installation'
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
	 * Operation confirm
	 *
	 * Confirm
	 *
	 * @param \Wallee\Sdk\Model\WebAppConfirmationRequest $request  (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return \Wallee\Sdk\Model\WebAppConfirmationResponse
	 */
	public function confirm($request) {
		return $this->confirmWithHttpInfo($request)->getData();
	}

	/**
	 * Operation confirmWithHttpInfo
	 *
	 * Confirm
     
     *
	 * @param \Wallee\Sdk\Model\WebAppConfirmationRequest $request  (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function confirmWithHttpInfo($request) {
		
		if (is_null($request)) {
			throw new \InvalidArgumentException('Missing the required parameter $request when calling confirm');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept(['application/json;charset=utf-8']);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType([]);

		
		$queryParams = [];

		
		$resourcePath = '/web-app/confirm';
		
		$resourcePath = str_replace('{format}', 'json', $resourcePath);

		
		$formParams = [];
		
		$tempBody = null;
		if (isset($request)) {
			$tempBody = $request;
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
				'\Wallee\Sdk\Model\WebAppConfirmationResponse',
				'/web-app/confirm'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders(), $this->apiClient->getSerializer()->deserialize($response->getData(), '\Wallee\Sdk\Model\WebAppConfirmationResponse', $response->getHeaders()));
		} catch (ApiException $e) {
			switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\Wallee\Sdk\Model\WebAppConfirmationResponse',
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
	 * Operation uninstall
	 *
	 * Uninstall
	 *
	 * @param int $space_id This parameter identifies the space within which the web app should be uninstalled. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return void
	 */
	public function uninstall($space_id) {
		return $this->uninstallWithHttpInfo($space_id)->getData();
	}

	/**
	 * Operation uninstallWithHttpInfo
	 *
	 * Uninstall
     
     *
	 * @param int $space_id This parameter identifies the space within which the web app should be uninstalled. (required)
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\VersioningException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @return ApiResponse
	 */
	public function uninstallWithHttpInfo($space_id) {
		
		if (is_null($space_id)) {
			throw new \InvalidArgumentException('Missing the required parameter $space_id when calling uninstall');
		}
		
		$headerParams = [];
		$headerAccept = $this->apiClient->selectHeaderAccept([]);
		if (!is_null($headerAccept)) {
			$headerParams[HttpRequest::HEADER_KEY_ACCEPT] = $headerAccept;
		}
		$headerParams[HttpRequest::HEADER_KEY_CONTENT_TYPE] = $this->apiClient->selectHeaderContentType([]);

		
		$queryParams = [];
		if (!is_null($space_id)) {
			$queryParams['spaceId'] = $this->apiClient->getSerializer()->toQueryValue($space_id);
		}

		
		$resourcePath = '/web-app/uninstall';
		
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
				null,
				'/web-app/uninstall'
            );
			return new ApiResponse($response->getStatusCode(), $response->getHeaders());
		} catch (ApiException $e) {
			switch ($e->getCode()) {
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
