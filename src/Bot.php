<?php declare(strict_types=1);

namespace DeepstakStats;

use \Discord\Discord;
use \Discord\WebSockets\Intents;
use \Discord\Parts\Channel\{Channel, Message};
use \Discord\Parts\Embed\Embed;
use \Discord\Builders\MessageBuilder;
use \GuzzleHttp\Client;

final class Bot {
  private string $serverCacheFile = CACHE_DIR . '/servers.json';
  private array $serverCache = [];
  private string $usageCacheFile = CACHE_DIR . '/usage.txt';
  private string $usageMessageId = '';
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
        $this->loadServerCache();
        $this->loadUsageCache();

        $this->guzzle = new Client(['base_uri' => 'https://ptero.deepstak.uk']);

        $discord->getLoop()->addPeriodicTimer(15, function () use ($discord, $channel) {
          $this->refreshServerUsageInfo($discord, $channel);
          $this->refreshServerInfo($discord, $channel);
        });
      }
    });

    $discord->run();
  }

  private function loadServerCache(): void {
    if (file_exists($this->serverCacheFile)) {
      $this->serverCache = json_decode(file_get_contents($this->serverCacheFile), true) ?? [];
    }
  }

  private function saveServerCache(): void {
    file_put_contents($this->serverCacheFile, json_encode($this->serverCache, JSON_PRETTY_PRINT));
  }

  private function loadUsageCache(): void {
    if (file_exists($this->usageCacheFile)) {
      $this->usageMessageId = file_get_contents($this->usageCacheFile) ?? '';
    }
  }

  private function saveUsageCache(): void {
    file_put_contents($this->usageCacheFile, $this->usageMessageId);
  }

  private function sendServerInfo(Discord $discord, Channel $channel, array $attributes, string $serverId, string $hash): void {
    $embed = $this->buildServerInfo($discord, $attributes);

    $channel->sendMessage(MessageBuilder::new()->addEmbed($embed))->then(
      function ($message) use ($serverId, $hash, $attributes) {
        $this->serverCache[$serverId] = [
          'message_id' => $message->id,
          'hash' => $hash,
          'attributes' => $attributes
        ];
        $this->saveServerCache();
      }
    );
  }

  private function editServerInfo(Discord $discord, Channel $channel, string $messageId, array $attributes, string $serverId, string $hash): void {
    $embed = $this->buildServerInfo($discord, $attributes);

    $channel->messages->fetch($messageId)->then(
      function (Message $message) use ($embed, $serverId, $hash, $attributes) {
        $message->edit(MessageBuilder::new()->addEmbed($embed));
        $this->serverCache[$serverId] = [
          'message_id' => $message->id,
          'hash' => $hash,
          'attributes' => $attributes,
        ];
        $this->saveServerCache();
      },
      function () use ($serverId) {
        unset($this->serverCache[$serverId]);
        $this->saveServerCache();
      }
    );
  }

  private function deleteServerInfo(Channel $channel, string $serverId): void {
    $messageId = $this->serverCache[$serverId]['message_id'];

    $channel->messages->fetch($messageId)->then(
      function (Message $message) use ($serverId) {
        $message->delete();
        unset($this->serverCache[$serverId]);
        $this->saveServerCache();
      },
      function () use ($serverId) {
        unset($this->serverCache[$serverId]);
        $this->saveServerCache();
      }
    );
  }

  private function buildServerInfo(Discord $discord, array $attributes): Embed {
    $_ = $attributes;

    switch ($_['identifier']) {
      case 'e6e4eebe': // V Rising
        $game = 'V Rising';
        $password = $_['environment']['VR_PASSWORD'];
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/1604030/123091b358ac705c36642df5599d230825ad3cd6/header.jpg?t=1745842815';
        break;

      case '1cee825e': // Enshrouded
        $game = 'Enshrouded';
        $password = $_['environment']['SRV_PW2'];
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/1203620/header.jpg?t=1744617436';
        break;

      case '4e8a47cd': // Valheim Plus
        $game = 'Valheim Plus';
        $password = $_['environment']['PASSWORD'];
        $image = 'https://raw.githubusercontent.com/nxPublic/ValheimPlus/master/logo.png';
        break;

      case '930f597e': // ATM10
        $game = 'All The Mods 10 (Minecraft)';
        $image = 'https://i.imgur.com/QAUei6a_d.webp?maxwidth=760&fidelity=grand';
        break;

      case 'a4e4837d': // Arma 3
        $game = 'Arma 3';
        $password = $_['environment']['SERVER_PASSWORD'];
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/107410/header.jpg?t=1743497752';
        break;
    }

    $port = $_['relationships']['allocations']['data'][0]['attributes']['port'];

    $cpuPrcnt = number_format(round($_['usage']['cpu_absolute'] ?? 0, 2), 2);
    $cpuLimit = $_['limits']['cpu'] ? $_['limits']['cpu'] . '%' : 'Unlimited';

    $memGb = round($_['usage']['memory_bytes'] / 1073741824, 2);
    $memGbLimit = $_['limits']['memory'] ? round($_['limits']['memory'] / 1024, 2) . ' GB' : 'Unlimited';

    $password = $password ?? 'No Password';

    $statusColor = match ($_['current_state']) {
       'running' => 0x00FF00,
       'offline' => 0xFF0000,
       'starting' => 0xFFFF00,
       'reinstalling',
       'installing' => 0xFFA500,
       default => 0x808080
    };

    $embed = new Embed($discord);
    $embed
      ->setTitle($_['name'])
      ->setAuthor($game ?? '')
      ->setColor($statusColor)
      ->setDescription($_['description'])
      ->addFieldValues('Server', "```https://deepstak.uk:$port```", true)
      ->addFieldValues('CPU', "```$cpuPrcnt/$cpuLimit```", true)
      ->addFieldValues('', '', false)
      ->addFieldValues('Password', "```$password```", true)
      ->addFieldValues('RAM', "```$memGb/$memGbLimit```", true);

    if (isset($image)) {
      $embed->setImage($image);
    }

    return $embed;
  }

  private function refreshServerInfo(Discord $discord, Channel $channel): void {
    $servers = Stats::getServerList($this->guzzle, $discord->getLogger());

    foreach ($servers as $server) {
      $attributes = $server['attributes'];
      $id = $attributes['identifier'];
      $hash = md5(json_encode($attributes));

      if (isset($this->serverCache[$id])) {
        $cache = $this->serverCache[$id];

        if ($cache['hash'] !== $hash) {
          $this->editServerInfo($discord, $channel, $cache['message_id'], $attributes, $id, $hash);
        }
      } else {
        $this->sendServerInfo($discord, $channel, $attributes, $id, $hash);
      }
    }

    $existingIds = array_map(fn($item) => $item['attributes']['identifier'] ?? null, $servers);
    foreach (array_keys($this->serverCache) as $cachedId) {
      if (!in_array($cachedId, $existingIds)) {
        $this->deleteServerInfo($channel, $cachedId);
      }
    }
  }

  private function refreshServerUsageInfo(Discord $discord, Channel $channel): void {
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

    $channel->messages->fetch($this->usageMessageId ?? '')->then(
      function (Message $message) use ($embed) {
        $message->edit(MessageBuilder::new()->addEmbed($embed));
      },
      function () use ($channel, $embed) {
        $channel->sendMessage(MessageBuilder::new()->addEmbed($embed))->then(
          function ($message) {
            $this->usageMessageId = $message->id;
            $this->saveUsageCache();
          }
        );
      }
    );
  }
}