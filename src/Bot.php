<?php declare(strict_types=1);

namespace DeepstakStats;

use \Discord\Discord;
use \Discord\WebSockets\Intents;
use \Discord\Parts\Channel\{Channel, Message};
use \Discord\Parts\Embed\Embed;
use \Discord\Builders\MessageBuilder;
use \GuzzleHttp\Client;

final class Bot {
  private string $infoMessageCacheFile = CACHE_DIR . '/infoMessage.txt';
  private string $infoMessageId = '';
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
        $this->loadInfoMessageCache();

        $this->guzzle = new Client(['base_uri' => 'https://ptero.deepstak.uk']);

        $discord->getLoop()->addPeriodicTimer(30, function () use ($discord, $channel) {
          $this->updateInfoMessage($discord, $channel);
        });
      }
    });

    $discord->run();
  }

  private function loadInfoMessageCache(): void {
    if (file_exists($this->infoMessageCacheFile)) {
      $this->infoMessageId = file_get_contents($this->infoMessageCacheFile) ?? '';
    }
  }

  private function saveInfoMessageCache(): void {
    file_put_contents($this->infoMessageCacheFile, $this->infoMessageId);
  }

  private function updateInfoMessage(Discord $discord, Channel $channel): void {
    $embeds = [];

    $cpuLoad = Stats::getCpuLoad();
    $memUsage = Stats::getMemoryUsage();
    $diskUsage = Stats::getDiskUsage();

    $embed = new Embed($discord);
    $embed
      ->setTitle('Deepstak Overall Usage')
      ->setDescription('Below is a list of servers availabe for everyone to play on, as well as their current status represented by the color of the left border. Feel free to ping @DannyDeeps to discuss adding any other game servers!')
      ->setColor(0x800080)
      ->addFieldValues('CPU', "```$cpuLoad%```", true)
      ->addFieldValues("RAM", "```{$memUsage['used_gb']}/{$memUsage['total_gb']} GB ({$memUsage['percent_used']}%)```", true)
      ->addFieldValues("DISK", "```{$diskUsage['used_gb']}/{$diskUsage['total_gb']} GB ({$diskUsage['percent_used']}%)```", true);

    $embeds[] = $embed;

    $servers = Stats::getServerList($this->guzzle, $discord->getLogger());
    foreach ($servers as $server) {
      $embeds[] = $this->buildServerInfo($discord, $server['attributes']);
    }

    $infoMessage = MessageBuilder::new();
    $infoMessage->addEmbed(...$embeds);

    $channel->messages->fetch($this->infoMessageId)->then(
      function (Message $message) use ($infoMessage) {
        $message->edit($infoMessage);
      },
      function () use ($channel, $infoMessage) {
        $channel->sendMessage($infoMessage)->then(
          function (Message $message) {
            $this->infoMessageId = $message->id;
            $this->saveInfoMessageCache();
          }
        );
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

      case '745f58e7': // Core Keeper
        $game = 'Core Keeper';
        $server = $_['environment']['GAME_ID'];
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/1621690/header.jpg?t=1741883937';
        break;

      case 'b3fa0915': // Project Zomboid
        $game = 'Project Zomboid';
        $server = '185.45.226.7:' . $_['relationships']['allocations']['data'][0]['attributes']['port'];
        $password = 'daddyspiffo';
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/108600/header.jpg?t=1739309087';
        break;

      case '73b2174e': // Satisfactory
        $game = 'Satisfactory';
        $server = '185.45.226.7:7777';
        $password = 'iamproperty';
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/526870/header.jpg?t=1749627472';
        break;

      case '0dfb3174': // Necesse
        $game = 'Necesse';
        $server = '185.45.226.7:14159';
        $password = 'needyneeders';
        $image = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/1169040/header.jpg?t=1749558478';
        break;
    }

    $port = $_['relationships']['allocations']['data'][0]['attributes']['port'];

    $cpuPrcnt = number_format(round($_['usage']['cpu_absolute'] ?? 0, 2), 2);
    $cpuLimit = $_['limits']['cpu'] ? $_['limits']['cpu'] . '%' : 'Unlimited';

    $memGb = round($_['usage']['memory_bytes'] / 1073741824, 2);
    $memGbLimit = $_['limits']['memory'] ? round($_['limits']['memory'] / 1024, 2) . ' GB' : 'Unlimited';

    $server = $server ?? "https://deepstak.uk:$port";
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
      ->addFieldValues('Server', "```$server```", true)
      ->addFieldValues('CPU', "```$cpuPrcnt/$cpuLimit```", true)
      ->addFieldValues('', '', false)
      ->addFieldValues('Password', "```$password```", true)
      ->addFieldValues('RAM', "```$memGb/$memGbLimit```", true);

    if (isset($image)) {
      $embed->setImage($image);
    }

    return $embed;
  }
}