<?php declare(strict_types=1);

namespace DeepstakStats;

use \GuzzleHttp\Client;
use \Psr\Log\LoggerInterface;

final class Stats {
  public static function getServerList(Client $guzzle, LoggerInterface $logger): array {
    $response = $guzzle->get('/api/application/servers/custom?include=allocations', ['headers' => ['Authorization' => 'Bearer ' . $_ENV['APP_API_KEY']]]);
    $serverList = json_decode((string) $response->getBody(), true);

    $servers = [];
    foreach ($serverList['data'] as $server) {
      if (20 === $server['attributes']['egg']) continue;

      $response = $guzzle->get("/api/client/servers/{$server['attributes']['identifier']}/resources", ['headers' => ['Authorization' => 'Bearer ' . $_ENV['CLIENT_API_KEY']]]);
      $resourceData = json_decode((string) $response->getBody(), true);
      $server['attributes']['usage'] = $resourceData['attributes']['resources'];
      $server['attributes']['current_state'] = $resourceData['attributes']['current_state'];
      $servers[] = $server;
    }

    return $servers;
  }
}