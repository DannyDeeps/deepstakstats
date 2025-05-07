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
      if (in_array($server['attributes']['egg'], [19, 20]))
        continue;

      $response = $guzzle->get("/api/client/servers/{$server['attributes']['identifier']}/resources", ['headers' => ['Authorization' => 'Bearer ' . $_ENV['CLIENT_API_KEY']]]);
      $resourceData = json_decode((string) $response->getBody(), true);
      $server['attributes']['usage'] = $resourceData['attributes']['resources'];
      $server['attributes']['current_state'] = $resourceData['attributes']['current_state'];
      $servers[] = $server;
    }

    return $servers;
  }

  public static function getCpuLoad(): string {
    $load = sys_getloadavg();
    return number_format(round($load[0], 2), 2); // 1-minute average
  }

  public static function getMemoryUsage(): array {
    $meminfo = file_get_contents("/proc/meminfo");
    $data = [];

    foreach (explode("\n", $meminfo) as $line) {
      if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
        $data[$matches[1]] = (int) $matches[2];
      }
    }

    $total = $data['MemTotal'] ?? 0;
    $free = ($data['MemFree'] ?? 0) + ($data['Buffers'] ?? 0) + ($data['Cached'] ?? 0);
    $used = $total - $free;

    return [
      'total_gb' => round($total / 1024 / 1024, 2),
      'used_gb' => round($used / 1024 / 1024, 2),
      'free_gb' => round($free / 1024 / 1024, 2),
      'percent_used' => round(($used / $total) * 100, 2)
    ];
  }

  public static function getDiskUsage(string $path = '/'): array {
    $total = disk_total_space($path);
    $free = disk_free_space($path);
    $used = $total - $free;

    return [
      'total_gb' => round($total / 1073741824),
      'used_gb' => round($used / 1073741824),
      'free_gb' => round($free / 1073741824),
      'percent_used' => round(($used / $total) * 100)
    ];
  }
}