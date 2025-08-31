<?php
namespace Drupal\access_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\Yaml\Yaml;

class AccessRequestController extends ControllerBase {
  protected $httpClient;
  protected $logger;
  protected $config;

  public function __construct(ClientInterface $http_client, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->config = $config_factory->get('access_request.settings');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.factory')->get('access_request'),
      $container->get('config.factory')
    );
  }

  public function proxyRequest() {
    $url = 'https://server.dev.access.makehaven.org/toolauth/req';
    $data = file_get_contents('php://input');

    try {
      $response = $this->httpClient->post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $data,
      ]);

      $this->logger->debug('HTTP request result: @result', ['@result' => $response->getBody()->getContents()]);
      return new JsonResponse(json_decode($response->getBody()->getContents(), TRUE));
    }
    catch (\Exception $e) {
      $this->logger->error('HTTP request error: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['success' => false, 'message' => 'Error sending the request.']);
    }
  }

  public function healthCheck() {
    $gateway_url = $this->config->get('python_gateway_url');
    $health_check_url = str_replace('/toolauth/req', '/health', $gateway_url);
    $build = [];

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
    } catch (\Exception $e) {
      $latency = microtime(TRUE) - $start_time;
      $build['#markup'] = $this->t('Health check failed with an exception.<br>Latency: @latency ms<br>Error: @error', [
        '@latency' => round($latency * 1000),
        '@error' => $e->getMessage(),
      ]);
    }

    return $build;
  }

  public function listDoors() {
    $door_map_yaml = $this->config->get('door_map');
    $build = [];

    if (empty($door_map_yaml)) {
      $build['#markup'] = $this->t('No doors have been configured.');
      return $build;
    }

    try {
      $door_map = Yaml::parse($door_map_yaml);
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing door map YAML: @error', ['@error' => $e->getMessage()]);
      $build['#markup'] = $this->t('There was an error parsing the door map configuration.');
      return $build;
    }

    if (empty($door_map) || !is_array($door_map)) {
        $build['#markup'] = $this->t('No doors have been configured or the format is incorrect.');
        return $build;
    }

    $items = [];
    foreach ($door_map as $asset_id => $door_info) {
      $url = Url::fromRoute('access_request.asset', ['asset_identifier' => $asset_id]);
      $link = [
        '#type' => 'link',
        '#title' => $door_info['name'] ?? $asset_id,
        '#url' => $url,
      ];
      $items[] = $link;
    }

    $build['door_list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => $this->t('Available Doors'),
    ];

    return $build;
  }
}

