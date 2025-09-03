<?php
namespace Drupal\access_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;

class AccessRequestController extends ControllerBase {
  protected $config;
  protected $httpClient;
  protected $logger;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->config = $config_factory->get('access_request.settings');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('access_request');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.factory')
    );
  }

  public function proxyRequest() {
    $url = $this->config->get('python_gateway_url');
    $data = file_get_contents('php://input');

    try {
      $response = $this->httpClient->post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $data,
        'http_errors' => false,
      ]);

      return new JsonResponse(json_decode($response->getBody()->getContents(), TRUE), $response->getStatusCode());
    }
    catch (\Exception $e) {
      $this->logger->error('Proxy request failed with exception: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['success' => false, 'message' => 'Error sending the request.'], 500);
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

    try {
      $response = $this->httpClient->get($health_check_url, ['http_errors' => false]);
      $status = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();

      if ($status == 200) {
        $build['#markup'] = $this->t('Health check successful.<br>Status: @status<br>Response: @response', [
          '@status' => $status,
          '@response' => $response_body,
        ]);
      } else {
        $build['#markup'] = $this->t('Health check failed.<br>Status: @status<br>Response: @response', [
          '@status' => $status,
          '@response' => $response_body,
        ]);
      }
    }
    catch (\Exception $e) {
      $build['#markup'] = $this->t('Health check failed with an exception.<br>Error: @error', [
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
        $assets[$asset_id]['url'] = Url::fromRoute('access_request.asset', ['asset' => $asset_id], ['query' => ['method' => 'website']]);
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
