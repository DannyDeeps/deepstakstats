<?php declare(strict_types=1);

namespace DeepstakStats;

use \Discord\Discord;
use \Discord\WebSockets\Intents;
use \Discord\Parts\Channel\{Channel, Message};
use \Discord\Parts\Embed\Embed;
use \Discord\Builders\MessageBuilder;
use \GuzzleHttp\Client;

final class Bot {
  private string $cacheFile = CACHE_DIR . '/servers.json';
  private array $serverInfoCache = [];
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
        $this->guzzle = new Client(['base_uri' => 'https://ptero.deepstak.uk']);

        $discord->getLoop()->addPeriodicTimer(15, function () use ($discord, $channel) {
          $this->loadCache();
          $this->refreshServerInfo($discord, $channel);
        });
      }
    });

    $discord->run();
  }

  private function loadCache(): void {
    if (file_exists($this->cacheFile)) {
      $this->serverInfoCache = json_decode(file_get_contents($this->cacheFile), true) ?? [];
    }
  }

  private function saveCache(): void {
    file_put_contents($this->cacheFile, json_encode($this->serverInfoCache, JSON_PRETTY_PRINT));
  }

  private function sendServerInfo(Discord $discord, Channel $channel, array $attributes, string $serverId, string $hash): void {
    $embed = $this->buildServerInfo($discord, $attributes);

    $channel->sendMessage(MessageBuilder::new()->addEmbed($embed))->then(
      function ($message) use ($discord, $serverId, $hash, $attributes) {
        $this->serverInfoCache[$serverId] = [
          'message_id' => $message->id,
          'hash' => $hash,
          'attributes' => $attributes
        ];
        $this->saveCache();
      }
    );
  }

  private function editServerInfo(Discord $discord, Channel $channel, string $messageId, array $attributes, string $serverId, string $hash): void {
    $embed = $this->buildServerInfo($discord, $attributes);

    $channel->messages->fetch($messageId)->then(
      function (Message $message) use ($discord, $embed, $serverId, $hash, $attributes) {
        $message->edit(MessageBuilder::new()->addEmbed($embed));
        $this->serverInfoCache[$serverId] = [
          'message_id' => $message->id,
          'hash' => $hash,
          'attributes' => $attributes,
        ];
        $this->saveCache();
      },
      function () use ($discord, $messageId, $serverId) {
        unset($this->serverInfoCache[$serverId]);
        $this->saveCache();
      }
    );
  }

  private function deleteServerInfo(Channel $channel, string $serverId): void {
    $messageId = $this->serverInfoCache[$serverId]['message_id'];

    $channel->messages->fetch($messageId)->then(
      function (Message $message) use ($serverId) {
        $message->delete();
        unset($this->serverInfoCache[$serverId]);
        $this->saveCache();
      },
      function () use ($serverId) {
        unset($this->serverInfoCache[$serverId]);
        $this->saveCache();
      }
    );
  }

  private function buildServerInfo(Discord $discord, array $attributes): Embed {
    $_ = $attributes;

    $port = $_['relationships']['allocations']['data'][0]['attributes']['port'];

    $memMb = round($_['usage']['memory_bytes'] / 1000000) . 'MB';
    $memLimit = $_['limits']['memory'] ? $_['limits']['memory'] . 'MB' : 'Unlimited';

    $cpuPrcnt = $_['usage']['cpu_absolute'] . '%';
    $cpuLimit = $_['limits']['cpu'] ? $_['limits']['cpu'] . '%' : 'Unlimited';

    // $diskMb = round($_['usage']['disk_bytes'] / 1000000) . 'MB';
    // $diskLimit = $_['limits']['disk'] ? $_['limits']['disk'] . 'MB' : 'Unlimited';

    $game = '';

    switch ($_['identifier']) {
      case 'e6e4eebe': // V Rising
        $description = $_['environment']['VR_DESCRIPTION'];
        $game = 'V Rising';
        $password = $_['environment']['VR_PASSWORD'];
        // $image = 'https://media.discordapp.net/attachments/735531477361623070/1369385470542479370/v-rising1.png?ex=681bab1a&is=681a599a&hm=59cde2df7831693a679958fb73caf50a992b17ce77f021caa1056d60d1aefbb6&=&format=webp&quality=lossless';
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/1604030/123091b358ac705c36642df5599d230825ad3cd6/header.jpg?t=1745842815';
        break;

      case '1cee825e': // Enshrouded
        $game = 'Enshrouded';
        $password = $_['environment']['SRV_PW2'];
        // $image = 'https://cdn.discordapp.com/attachments/735531477361623070/1369388511962071070/image.png?ex=681badef&is=681a5c6f&hm=f6060879712cb8e285f9b1edff9339dd3d343ba52218e015d54c1f7976908e88&';
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
    }

    $embed = new Embed($discord);
    $embed
      ->setTitle($_['name'])
      ->setAuthor($game)
      ->setColor(0x00FF00)
      ->setDescription($description ?? $_['description'])
      ->addFieldValues('<:rickandmortyportal:735198191414411445> Server', "https://deepstak.uk:$port", true)
      ->addFieldValues('<:portalgun:735198189790953472> Password', $password ?? 'No password', true)
      ->addFieldValues('', '', false)
      ->addFieldValues('CPU', "$cpuPrcnt / $cpuLimit", true)
      ->addFieldValues('RAM', "$memMb / $memLimit", true);
      // ->addFieldValues('Disk', "$diskMb / $diskLimit", true);

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

      if (isset($this->serverInfoCache[$id])) {
        $cache = $this->serverInfoCache[$id];

        if ($cache['hash'] !== $hash) {
          $this->editServerInfo($discord, $channel, $cache['message_id'], $attributes, $id, $hash);
        }
      } else {
        $this->sendServerInfo($discord, $channel, $attributes, $id, $hash);
      }
    }

    $existingIds = array_map(fn($item) => $item['attributes']['identifier'] ?? null, $servers);
    foreach (array_keys($this->serverInfoCache) as $cachedId) {
      if (!in_array($cachedId, $existingIds)) {
        $this->deleteServerInfo($channel, $cachedId);
      }
    }
  }
}