<?php declare(strict_types=1);

namespace DeepstakStats;

use \Discord\Discord;
use \Discord\WebSockets\Intents;
use \Discord\Parts\Channel\{Channel, Message};
use \Discord\Parts\Embed\Embed;
use \Discord\Builders\MessageBuilder;
use \GuzzleHttp\Client;

final class Bot {
  private string $sessionCacheFile = CACHE_DIR . '/session.json';
  private array $sessionCache = ['msgIds' => []];
  private Client $guzzle;

  public function start(): void {
    $discord = new Discord([
      'token' => $_ENV['BOT_TOKEN'],
      'intents' => [
        Intents::GUILDS,
        Intents::GUILD_MESSAGES
      ]
    ]);

    $discord->on('init', function (Discord $discord) {
      $channel = $discord->getChannel($_ENV['CHANNEL_ID']);
      if (!$channel) {
        $discord->getLogger()->error('[DeepstakStats] Channel not found.');
      } else {
        $this->loadSessionCache();

        $this->guzzle = new Client(['base_uri' => 'https://ptero.deepstak.uk']);

        $discord->getLoop()->addPeriodicTimer(30, function () use ($discord, $channel) {
          $this->updateInfoMessage($discord, $channel);
        });
      }
    });

    echo 'Bot Starting';
    $discord->run();
    echo 'Bot Stopped';
  }

  private function loadSessionCache(): void {
    if (file_exists($this->sessionCacheFile)) {
      $this->sessionCache = json_decode(file_get_contents($this->sessionCacheFile), true) ?? [];
    }
  }

  private function saveSessionCache(): void {
    file_put_contents($this->sessionCacheFile, json_encode($this->sessionCache));
  }

  private function updateInfoMessage(Discord $discord, Channel $channel): void {
    $embeds = [];

    $cpuLoad = Stats::getCpuLoad();
    $memUsage = Stats::getMemoryUsage();
    $diskUsage = Stats::getDiskUsage();

    $embed = new Embed($discord);
    $embed
      ->setTitle('Deepstak Overall Usage')
      ->setColor(0x800080)
      ->addFieldValues('CPU', "```$cpuLoad%```", true)
      ->addFieldValues("RAM", "```{$memUsage['used_gb']}/{$memUsage['total_gb']} GB ({$memUsage['percent_used']}%)```", true)
      ->addFieldValues("DISK", "```{$diskUsage['used_gb']}/{$diskUsage['total_gb']} GB ({$diskUsage['percent_used']}%)```", true);

    $embeds[] = $embed;

    $serverConfigs = $this->getServersConfig();
    $serverAttributes = Stats::getServerAttributes($this->guzzle, $discord->getLogger());

    $serverInfos = [];
    foreach ($serverConfigs as $server => $config) {
      $serverInfos[$server] = $this->buildServerInfo($config, $serverAttributes[$server] ?? []);
    }

    $serverInfos = $this->sortServersByStatus($serverInfos);

    foreach ($serverInfos as $serverInfo) {
      $embeds[] = $this->buildServerEmbed($discord, $serverInfo);
    }

    $embedBatches = array_chunk($embeds, 10);
    foreach ($embedBatches as $batchIndex => $embedBatch) {
      $infoMessage = MessageBuilder::new();
      $infoMessage->addEmbed(...$embedBatch);
      $infoMessageId = $this->sessionCache['msgIds'][$batchIndex] ?? '';

      $channel->messages->fetch($infoMessageId)->then(
        function (Message $message) use ($infoMessage) {
          $message->edit($infoMessage);
        },
        function () use ($channel, $infoMessage, $batchIndex) {
          $channel->sendMessage($infoMessage)->then(
            function (Message $message) use ($batchIndex) {
              $this->sessionCache['msgIds'][$batchIndex] = $message->id;
              $this->saveSessionCache();
            }
          );
        }
      );
    }
  }

  private function buildServerInfo(array $config, array $attributes): array {
    $serverInfo = [
      'name' => $config['name'] ?? $attributes['name'] ?? '',
      'game' => $config['game'],
      'address' => $config['address'] ?? 'deepstak.uk:' . $attributes['relationships']['allocations']['data'][0]['attributes']['port'],
      'password' => $config['password'] ?? '',
      'description' => $config['description'] ?? $attributes['description'] ?? '',
      'img' => $config['img'] ?? '',
      'statusColor' => match ($attributes['status']) {
        'running' => 0x00FF00,
        'offline' => 0xFF0000,
        'starting' => 0xFFFF00,
        'reinstalling',
        'installing' => 0xFFA500,
        default => 0x808080
      },
    ];

    $serverInfo = array_map(function(string|int $v) use ($attributes) {
      if (is_string($v) && str_starts_with($v, 'env:')) {
        return $attributes['environment'][substr($v, 4)];
      }

      return $v;
    }, $serverInfo);

    return $serverInfo;
  }

  private function buildServerEmbed(Discord $discord, array $serverInfo): Embed {
    $embed = new Embed($discord);
    $embed
      ->setTitle($serverInfo['name'])
      ->setAuthor($serverInfo['game'])
      ->setColor($serverInfo['statusColor'])
      ->setDescription($serverInfo['description'])
      ->addFieldValues('Address', "```{$serverInfo['address']}```", true);

    if ($serverInfo['password']) {
      $embed->addFieldValues('Password', "```{$serverInfo['password']}```", true);
    }

    if ($serverInfo['img']) {
      $embed->setImage($serverInfo['img']);
    }

    return $embed;
  }

  private function sortServersByStatus(array $servers): array {
    $order = [
      0x00FF00 => 1, // running
      0xFFFF00 => 2, // starting
      0xFFA500 => 3, // installing/reinstalling
      0xFF0000 => 4, // offline
      0x808080 => 5, // unknown
    ];

    usort($servers, function($a, $b) use ($order) {
      return ($order[$a['statusColor']] ?? 6) <=> ($order[$b['statusColor']] ?? 6);
    });

    return $servers;
  }

  private function getServersConfig(): array {
    return require __DIR__ . '/../config/servers.php';
  }
}
