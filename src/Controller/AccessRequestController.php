<?php
namespace Drupal\access_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

class AccessRequestController extends ControllerBase {
  protected $httpClient;
  protected $logger;

  public function __construct(ClientInterface $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.factory')->get('access_request')
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
}

