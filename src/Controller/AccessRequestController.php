<?php
namespace Drupal\access_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Exception\ClientException;
use Drupal\access_request\AccessRequestService;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class AccessRequestController extends ControllerBase {
  protected $accessRequestService;
  protected $config;
  protected $httpClient;
  protected $logger;

  public function __construct(AccessRequestService $access_request_service, ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->accessRequestService = $access_request_service;
    $this->config = $config_factory->get('access_request.settings');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('access_request');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('access_request.service'),
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.factory')
    );
  }

  public function proxyRequest() {
    $data = file_get_contents('php://input');
    $request_data = json_decode($data, TRUE);
    $asset_identifier = $request_data['asset_identifier'] ?? NULL;
    $method = $request_data['method'] ?? 'proxy';
    $source = $request_data['source'] ?? NULL;

    if (empty($asset_identifier)) {
      return new JsonResponse(['success' => false, 'message' => 'asset_identifier not provided.'], 400);
    }

    $result = $this->accessRequestService->performAccessRequest($asset_identifier, $method, $source);

    if ($result['status'] === 'success' || $result['status'] === 'denied') {
      return new JsonResponse($result);
    }
    else {
      return new JsonResponse($result, 500);
    }
  }

  public function healthCheck() {
    $gateway_url = $this->config->get('python_gateway_url');
    $build = [];

    if (empty($gateway_url)) {
      $build['#markup'] = $this->t('The Python Gateway URL is not configured. Please configure it in the Access Request settings.');
      return $build;
    }

    $health_check_url = str_replace('/toolauth/req', '/health', $gateway_url);

    $start_time = microtime(TRUE);
    try {
      $response = $this->httpClient->get($health_check_url);
      $latency = microtime(TRUE) - $start_time;
      $status = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();

      if ($status == 200) {
        $build['#markup'] = $this->t('Health check successful.<br>Status: @status<br>Latency: @latency ms<br>Response: @response', [
          '@status' => $status,
          '@latency' => round($latency * 1000),
          '@response' => $response_body,
        ]);
      } else {
        $build['#markup'] = $this->t('Health check failed.<br>Status: @status<br>Latency: @latency ms<br>Response: @response', [
          '@status' => $status,
          '@latency' => round($latency * 1000),
          '@response' => $response_body,
        ]);
      }
    } catch (ClientException $e) {
        $latency = microtime(TRUE) - $start_time;
        if ($e->getResponse()->getStatusCode() == 404) {
            $build['#markup'] = $this->t('The health check endpoint was not found on the Python gateway (404 Not Found). This is an optional endpoint that may not be implemented on the gateway.<br>Latency: @latency ms<br><br><strong>To implement the health check on the Python gateway:</strong><br>Create an endpoint at `/health` that accepts GET requests. This endpoint should return a 200 OK response with a JSON body, for example: <code>{"status": "ok"}</code>.', [
                '@latency' => round($latency * 1000),
            ]);
        } else {
            $build['#markup'] = $this->t('Health check failed with a client error.<br>Latency: @latency ms<br>Error: @error', [
                '@latency' => round($latency * 1000),
                '@error' => $e->getMessage(),
            ]);
        }
    }
    catch (\Exception $e) { // Catches other exceptions like connect timeout
      $latency = microtime(TRUE) - $start_time;
      $build['#markup'] = $this->t('Health check failed with an exception.<br>Latency: @latency ms<br>Error: @error', [
        '@latency' => round($latency * 1000),
        '@error' => $e->getMessage(),
      ]);
    }

    return $build;
  }

  public function listAssets($category = NULL) {
    $asset_map_yaml = $this->config->get('asset_map');
    $build = [];

    if (empty($asset_map_yaml)) {
      $build['#markup'] = $this->t('No assets have been configured.');
      return $build;
    }

    try {
      $asset_map = Yaml::parse($asset_map_yaml);
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing asset map YAML: @error', ['@error' => $e->getMessage()]);
      $build['#markup'] = $this->t('There was an error parsing the asset map configuration.');
      return $build;
    }

    if (empty($asset_map) || !is_array($asset_map)) {
        $build['#markup'] = $this->t('No assets have been configured or the format is incorrect.');
        return $build;
    }

    $assets = [];
    foreach ($asset_map as $asset_id => $asset_info) {
      if (!$category || (isset($asset_info['category']) && $asset_info['category'] === $category)) {
        $assets[$asset_id] = $asset_info;
        $assets[$asset_id]['url'] = Url::fromRoute('access_request.asset', ['asset_identifier' => $asset_id], ['query' => ['method' => 'website']]);
      }
    }

    $build['asset_list'] = [
      '#theme' => 'access_request_asset_cards',
      '#assets' => $assets,
      '#title' => $this->t('Available Assets'),
    ];

    return $build;
  }
}

